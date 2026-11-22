<?php
declare(strict_types=1);

// ============================================================
//  Google Calendar — disponibilidad real y creación de reuniones con Meet.
//
//  Esto es lo que nos saca de encima a Calendly:
//    · freeBusy  → qué franjas están ocupadas en el calendario del vendedor
//    · events.insert con conferenceData → crea el evento Y el Google Meet
//
//  Detalle importante de la API: al crear la conferencia, Google puede
//  responder con `status: pending` y todavía sin el enlace. Por eso, si no
//  vino, releemos el evento unas pocas veces antes de darnos por vencidos.
//
//  Quién lo usa: agenda.php (huecos, reservas, reprogramar, cancelar) y, a
//  través de ella, la agenda pública /r/, la API v1 y el cron. Módulo: Agenda.
//  Depende de google.php (google_access_token: el permiso offline de cada
//  vendedor) y escribe el ajuste `gcal_falla_u<id>` de cada vendedor
//  (app_settings) para el aviso de views/_aviso_gcal.php. No toca tablas
//  propias: los eventos viven en Google y su id queda en agenda_reservas (lo
//  guarda agenda.php).
//
//  Caché de freeBusy: archivos en STORAGE/cache-gcal (fuera del webroot), sólo
//  con las franjas ocupadas; ver gcal_ocupado().
// ============================================================

/** Base de la API REST de Google Calendar v3. */
const GCAL_API = 'https://www.googleapis.com/calendar/v3';

/**
 * Llamada autenticada a la API de Calendar con el permiso de un usuario.
 *
 * Pide el access_token a google_access_token(), que puede renovarlo contra
 * Google ANTES de esta llamada. `$timeout` es el tiempo TOTAL, renovación
 * incluida: la renovación puede usar hasta la mitad y la llamada usa lo que
 * quede (nunca menos de 2 s). Sin eso, la verificación final de una reserva
 * (6 s) podía sumar los 20 s de la renovación y pasar los 10 s que espera la
 * web.
 *
 * Si curl no llega a Google (sin conexión, tiempo agotado) el código vuelve
 * en 0 y el cuerpo vacío.
 *
 * @param int        $usuarioId dueño del calendario
 * @param string     $metodo    GET, POST, PATCH, DELETE
 * @param string     $ruta      ruta bajo GCAL_API, con la consulta
 * @param array|null $cuerpo    se manda como JSON si no es null
 * @param int        $timeout   segundos totales de la llamada, renovación incluida (mínimo 2)
 * @return array{0:int,1:array} [codigoHttp, cuerpo decodificado]; 401 sintético si no hay token
 */
function gcal_api(int $usuarioId, string $metodo, string $ruta, ?array $cuerpo = null, int $timeout = 25): array
{
    $timeout = max(2, $timeout);
    $inicio  = microtime(true);
    $token   = google_access_token($usuarioId, max(2, intdiv($timeout, 2)));
    if (!$token) {
        return [401, ['error' => ['message' => 'El usuario no tiene su cuenta de Google conectada.']]];
    }
    $resta = max(2, (int) ceil($timeout - (microtime(true) - $inicio)));
    $ch = curl_init(GCAL_API . $ruta);
    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_TIMEOUT        => $resta,
        CURLOPT_CONNECTTIMEOUT => min(5, $resta),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ];
    if ($cuerpo !== null) {
        $opciones[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opciones);
    $res    = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, is_string($res) ? (json_decode($res, true) ?: []) : []];
}

/**
 * Franjas ocupadas del calendario del usuario entre dos momentos.
 *
 * Devuelve NULL si no se pudo consultar el calendario, que no es lo mismo que
 * devolver una lista vacía: quien llama leería una lista vacía como «no hay
 * nada ocupado» y publicaría TODOS los horarios del día como libres, aunque
 * el calendario real esté lleno, y le encimaría reuniones al vendedor.
 *
 * $timeout: segundos que se espera a Google. $cacheSeg: si es mayor que cero,
 * sirve la respuesta guardada de la misma consulta (mismo usuario, mismo
 * calendario, misma ventana) durante ese tiempo. Es para las pantallas que
 * muestran horarios a cualquiera: sin caché, cada carga de la agenda pública o
 * cada pedido de la web era una consulta a Google con el permiso del vendedor,
 * y alcanzaba con martillarlas para agotar la cuota y dejar la agenda sin
 * horarios. La verificación final de una reserva nunca usa la caché.
 *
 * @return array<int,array{0:int,1:int}>|null pares [inicio, fin], o null si no se pudo saber
 */
function gcal_ocupado(int $usuarioId, string $desdeIso, string $hastaIso, string $calendario = 'primary',
                      int $timeout = 25, int $cacheSeg = 0): ?array
{
    $archivo = $cacheSeg > 0 ? gcal_cache_archivo($usuarioId, $calendario, $desdeIso, $hastaIso) : null;
    if ($archivo !== null && @is_file($archivo) && @filemtime($archivo) > time() - $cacheSeg) {
        $guardado = json_decode((string) @file_get_contents($archivo), true);
        if (is_array($guardado)) { return $guardado; }
    }

    [$codigo, $r] = gcal_api($usuarioId, 'POST', '/freeBusy', [
        'timeMin' => $desdeIso,
        'timeMax' => $hastaIso,
        'items'   => [['id' => $calendario]],
    ], $timeout);
    $falla   = null;
    $franjas = gcal_franjas_freebusy($codigo, $r, $calendario, $falla);
    if ($franjas === null) {
        log_app('agenda.freebusy_error', ['tipo' => 'error', 'usuario_id' => $usuarioId,
            'detalle' => 'freeBusy devolvió ' . $codigo . ': ' . $falla]);
        gcal_recordar_falla($usuarioId, $codigo, (string) $falla);
        return null;
    }
    gcal_limpiar_falla($usuarioId);   // anduvo: se limpia el aviso de este vendedor
    if ($archivo !== null) {
        @file_put_contents($archivo, json_encode($franjas), LOCK_EX);
        @chmod($archivo, 0600);
    }
    return $franjas;
}

/**
 * Lee la respuesta de freeBusy: las franjas ocupadas, o null si no sirve para
 * saber qué está libre.
 *
 * Null en tres casos: el código no es 200 (incluido 0, sin conexión); falta el
 * calendario pedido en la respuesta; o Google contestó 200 pero con
 * `calendars[id].errors` (calendario inexistente, sin permiso, backendError).
 * En ese último caso `busy` viene vacío, y leerlo como «nada ocupado»
 * publicaría todos los horarios como libres y encimaría reuniones.
 *
 * Sin red ni base: se prueba en test/casos/c2.php.
 *
 * @param string|null $falla sale con el motivo cuando devuelve null
 * @return array<int,array{0:int,1:int}>|null
 */
function gcal_franjas_freebusy(int $codigo, array $r, string $calendario, ?string &$falla = null): ?array
{
    $falla = null;
    if ($codigo !== 200) {
        $falla = $codigo === 0 ? 'Google no respondió (sin conexión o tiempo agotado).'
                               : (string)($r['error']['message'] ?? ('HTTP ' . $codigo));
        return null;
    }
    $cal = $r['calendars'][$calendario] ?? null;
    if (!is_array($cal)) {
        $falla = 'La respuesta de Google no trae el calendario consultado.';
        return null;
    }
    if (!empty($cal['errors'])) {
        $motivos = [];
        foreach ((array) $cal['errors'] as $e) {
            $motivos[] = is_array($e) ? (string)($e['reason'] ?? 'error') : (string) $e;
        }
        $falla = 'Google no pudo leer el calendario: ' . implode(', ', array_unique($motivos)) . '.';
        return null;
    }
    $franjas = [];
    foreach ((array)($cal['busy'] ?? []) as $b) {
        $i = strtotime((string)($b['start'] ?? ''));
        $f = strtotime((string)($b['end'] ?? ''));
        if ($i === false || $f === false) {
            // Una franja que no se entiende no se puede saltear: sería un
            // horario ocupado publicado como libre.
            $falla = 'Google devolvió una franja ocupada ilegible.';
            return null;
        }
        $franjas[] = [$i, $f];
    }
    return $franjas;
}

/**
 * Archivo de la caché de freeBusy, fuera del webroot (en el almacenamiento de
 * la instalación: producción y pruebas no se mezclan). Guarda sólo las franjas
 * ocupadas, sin títulos ni invitados. Null si no hay dónde escribir.
 */
function gcal_cache_archivo(int $usuarioId, string $calendario, string $desdeIso, string $hastaIso): ?string
{
    if (!defined('STORAGE')) { return null; }
    $dir = rtrim((string) STORAGE, '/\\') . '/cache-gcal';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return null; }
    if (!is_writable($dir)) { return null; }
    // De vez en cuando se borra lo viejo: una ventana distinta por hora deja
    // archivos que nadie vuelve a leer.
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/fb-*.json') ?: [] as $viejo) {
            if (@filemtime($viejo) < time() - 3600) { @unlink($viejo); }
        }
    }
    return $dir . '/fb-' . hash('sha256', $usuarioId . '|' . $calendario . '|' . $desdeIso . '|' . $hastaIso) . '.json';
}

/** Ajuste de la falla de un vendedor: `gcal_falla_u<id>`. */
function gcal_falla_clave(int $usuarioId): string
{
    return 'gcal_falla_u' . $usuarioId;
}

/**
 * Guarda el último problema con Google Calendar de un vendedor, para poder
 * avisarlo en el sistema.
 *
 * Siempre guarda: el código 0 (curl no llegó a Google: sin conexión, tiempo
 * agotado) también es una falla. Antes 0 significaba «anduvo» y borraba el
 * aviso, así que las caídas de red nunca se mostraban; para borrar está
 * gcal_limpiar_falla().
 *
 * Es un ajuste POR VENDEDOR (gcal_falla_clave()): una llamada que sale bien
 * con la cuenta de uno no borra el aviso de otro. Si ya había guardada la
 * misma falla (mismo código y mensaje) no se reescribe: queda la hora en que
 * se detectó por primera vez y no se escribe la base en cada consulta.
 *
 * Sin esto, una falla de Google no se ve: si la API de Calendar está
 * deshabilitada en el proyecto, la reserva se confirma igual, el visitante
 * recibe «quedó agendada» y en el calendario no aparece nada. El error
 * quedaría sólo en la auditoría, donde nadie lo mira.
 */
function gcal_recordar_falla(int $usuarioId, int $codigo, string $mensaje): void
{
    if ($codigo === 0 && trim($mensaje) === '') {
        $mensaje = 'Google no respondió (sin conexión o tiempo agotado).';
    }
    $mensaje = mb_substr($mensaje, 0, 600);
    $clave   = gcal_falla_clave($usuarioId);
    $previa  = json_decode((string) setting_get($clave, ''), true);
    if (is_array($previa) && (int)($previa['codigo'] ?? -1) === $codigo
        && (string)($previa['mensaje'] ?? '') === $mensaje) {
        return;
    }
    // El mensaje de Google trae la URL exacta para habilitar la API; se guarda
    // entero porque es la instrucción que resuelve el problema.
    setting_set($clave, json_encode([
        'codigo'     => $codigo,
        'mensaje'    => $mensaje,
        'usuario_id' => $usuarioId,
        'cuando'     => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Borra el aviso de un vendedor porque Google volvió a responder con su
 * cuenta (o porque la acaba de reconectar). Sólo escribe si había algo que
 * borrar: antes se escribía la base en cada consulta que salía bien.
 *
 * También borra el ajuste viejo `gcal_falla` (uno solo para toda la
 * instalación, de antes de que la falla fuera por vendedor) si era de este
 * vendedor o no decía de quién.
 */
function gcal_limpiar_falla(int $usuarioId): void
{
    $clave = gcal_falla_clave($usuarioId);
    if ((string) setting_get($clave, '') !== '') {
        setting_set($clave, '');
    }
    $vieja = json_decode((string) setting_get('gcal_falla', ''), true);
    if (is_array($vieja) && in_array((int)($vieja['usuario_id'] ?? 0), [0, $usuarioId], true)) {
        setting_set('gcal_falla', '');
    }
}

/**
 * Todas las fallas guardadas (las por vendedor y la vieja de la instalación),
 * decodificadas y con la clave del ajuste en `clave`. Sin la tabla, [].
 *
 * @return array<int,array>
 */
function gcal_fallas_guardadas(): array
{
    try {
        $claves = q("SELECT clave FROM app_settings
                     WHERE (clave = 'gcal_falla' OR clave LIKE 'gcal_falla_u%') AND valor <> ''")
                  ->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return [];
    }
    $fallas = [];
    foreach ($claves as $clave) {
        $d = json_decode((string) setting_get((string)$clave, ''), true);
        if (is_array($d)) { $fallas[] = $d + ['clave' => (string)$clave]; }
    }
    return $fallas;
}

/**
 * Cuál de las fallas se le muestra a quien mira: primero la suya (es el único
 * que puede reconectar esa cuenta); si no tiene, la de otro vendedor sólo a un
 * administrador. A un comercial no se le muestra la falla de otro: no puede
 * hacer nada con ella, y el botón «Reconectar» conectaría su propia cuenta.
 *
 * Sin base: se prueba en test/casos/c2.php.
 *
 * @param array<int,array> $fallas como las devuelve gcal_fallas_guardadas()
 * @return array|null la falla elegida con `propia` (bool), o null
 */
function gcal_falla_elegir(array $fallas, ?int $usuarioId, bool $esAdmin): ?array
{
    $ajena = null;
    foreach ($fallas as $d) {
        $uid = (int)($d['usuario_id'] ?? 0);
        if ($usuarioId !== null && $uid === $usuarioId) {
            return ['propia' => true] + $d;
        }
        // La falla vieja sin vendedor también cuenta como ajena.
        if ($ajena === null && $esAdmin) { $ajena = ['propia' => false] + $d; }
    }
    return $ajena;
}

/**
 * ¿Hay un problema pendiente con Google Calendar para mostrarle a quien mira?
 * Devuelve los datos para mostrarlos, o null si está todo bien.
 *
 * @param int|null  $usuarioId quien mira (por defecto el de la sesión)
 * @param bool|null $esAdmin   si ve también las fallas de otros (por defecto, según su rol)
 * @return array{codigo:int,mensaje:string,usuario_id:int,cuando:string,apagada:bool,propia:bool,
 *               usuario_nombre:string,enlace?:string}|null
 *         `apagada`: la API está deshabilitada en el proyecto de Google Cloud;
 *         `enlace`: la dirección de la consola que trae el mensaje de Google;
 *         `propia`: la falla es de la cuenta de quien mira (puede reconectarla).
 * Efecto: si una falla era de una cuenta que después se reconectó, la borra.
 */
function gcal_falla_pendiente(?int $usuarioId = null, ?bool $esAdmin = null): ?array
{
    if ($usuarioId === null && function_exists('current_user_id')) { $usuarioId = current_user_id(); }
    if ($esAdmin === null) { $esAdmin = function_exists('auth_is_admin') && auth_is_admin(); }

    $vigentes = [];
    foreach (gcal_fallas_guardadas() as $d) {
        // Una falla por «no tiene su cuenta conectada» se resuelve reconectando,
        // y eso NO pasa por acá. Si el aviso sólo se borrara cuando alguna llamada
        // a Google sale bien, después de reconectar seguiría ahí hasta que alguien
        // reserve una reunión o pase el cron. Si la cuenta se volvió a conectar
        // DESPUÉS de la falla, la falla ya no existe.
        $uid = (int)($d['usuario_id'] ?? 0);
        if ($uid > 0 && str_contains(mb_strtolower((string)($d['mensaje'] ?? '')), 'no tiene su cuenta')) {
            $reconectado = (string) qv('SELECT actualizado_en FROM google_tokens
                                        WHERE usuario_id = ? AND refresh_token <> \'\'', [$uid]);
            if ($reconectado !== '' && strtotime($reconectado) >= strtotime((string)($d['cuando'] ?? '1970-01-01'))) {
                setting_set((string)$d['clave'], '');
                continue;
            }
        }
        $vigentes[] = $d;
    }

    $d = gcal_falla_elegir($vigentes, $usuarioId, $esAdmin);
    if ($d === null) { return null; }

    // El caso más común y el que más cuesta descubrir: la API existe pero está
    // apagada en el proyecto de Google Cloud. Se traduce a algo accionable.
    $m = (string)($d['mensaje'] ?? '');
    $d['apagada'] = stripos($m, 'has not been used in project') !== false
                 || stripos($m, 'is disabled') !== false;
    if (preg_match('#https://console\.(?:developers|cloud)\.google\.com\S+#', $m, $mm)) {
        $d['enlace'] = rtrim($mm[0], '.,)');
    }
    $uid = (int)($d['usuario_id'] ?? 0);
    $d['usuario_nombre'] = $uid > 0 ? (string) qv('SELECT nombre FROM usuarios WHERE id = ?', [$uid]) : '';
    return $d;
}

/**
 * ¿El usuario tiene su calendario conectado? Es google_conectado(): hay
 * refresh_token guardado y Google no lo rechazó con invalid_grant.
 *
 * No consulta a Google: un permiso revocado del lado de Google sigue dando
 * true hasta que falle una renovación. Para distinguir «libre» de «no sé» lo
 * que vale es que gcal_ocupado() devuelva null.
 */
function gcal_disponible(int $usuarioId): bool
{
    return google_conectado($usuarioId);
}

/** Qué puede pedirse en `sendUpdates`. Cualquier otro valor se toma como 'none'. */
function gcal_avisar_valido(string $avisar): string
{
    return in_array($avisar, ['all', 'externalOnly', 'none'], true) ? $avisar : 'none';
}

/**
 * Crea un evento con Google Meet y devuelve [eventId, meetUrl, iCalUID].
 *
 * Google NO avisa a los invitados salvo que se pida (`avisar`). Con
 * `sendUpdates=all` al cliente le llegarían dos invitaciones para la misma
 * reunión —la de Google y la del CRM, con identificadores distintos—: dos
 * eventos en su calendario y dos avisos al cancelar. La invitación la manda
 * el CRM (agenda_avisar) usando el iCalUID que devuelve Google, y para Gmail
 * las dos son el mismo evento.
 *
 * @param array $d inicio|fin|titulo|descripcion|invitados[]|zona|con_meet
 *                 avisar ('none' por defecto: all | externalOnly | none)
 *                 privado (array clave => valor: extendedProperties.private,
 *                 que sólo ve el dueño del calendario)
 *                 id_propio (texto: la semilla del id del evento; si no viene,
 *                 se usa privado.crm_reserva_id)
 *
 * Idempotente cuando hay semilla: el evento se crea con un id propio
 * (gcal_id_evento()). Si el primer intento llegó a crearlo pero no a guardarlo
 * (el proceso se cortó y el cron completa la reserva), Google contesta 409 al
 * segundo y se usa el evento que ya estaba, en vez de dejar dos en el
 * calendario. Si ese evento estaba cancelado, se crea uno nuevo sin id propio.
 *
 * @return array{0:string,1:string,2:string}
 * @throws RuntimeException si Google rechaza el evento (también deja el aviso)
 */
function gcal_crear_evento(int $usuarioId, array $d, string $calendario = 'primary'): array
{
    $zona = (string)($d['zona'] ?? '') ?: 'America/Argentina/Buenos_Aires';
    $evento = [
        'summary'     => (string)($d['titulo'] ?? 'Reunión'),
        'description' => (string)($d['descripcion'] ?? ''),
        // date('c') ya lleva el desplazamiento del servidor, así que el
        // instante es exacto; `timeZone` sólo define cómo lo muestra Google
        // (y cómo se repite, si fuera recurrente).
        'start'       => ['dateTime' => date('c', (int)($d['inicio'] ?? 0)), 'timeZone' => $zona],
        'end'         => ['dateTime' => date('c', (int)($d['fin'] ?? 0)),    'timeZone' => $zona],
        'attendees'   => array_map(
            fn($a) => is_array($a) ? $a : ['email' => $a],
            array_values(array_filter((array)($d['invitados'] ?? [])))
        ),
        'reminders'   => ['useDefault' => false, 'overrides' => [
            ['method' => 'email', 'minutes' => 60],
            ['method' => 'popup', 'minutes' => 10],
        ]],
    ];
    if (!empty($d['privado']) && is_array($d['privado'])) {
        // Google sólo acepta textos como valores.
        $evento['extendedProperties'] = ['private' => array_map('strval', $d['privado'])];
    }
    $semilla = (string)($d['id_propio'] ?? ($d['privado']['crm_reserva_id'] ?? ''));
    $idPropio = $semilla !== '' ? gcal_id_evento($semilla) : '';
    if ($idPropio !== '') { $evento['id'] = $idPropio; }
    if (!empty($d['con_meet'])) {
        // requestId: Google lo usa para no crear dos Meet si el mismo pedido
        // llega dos veces. Con id propio es el mismo en cada intento; sin él,
        // al azar.
        $evento['conferenceData'] = [
            'createRequest' => [
                'requestId'             => $idPropio !== '' ? $idPropio : bin2hex(random_bytes(8)),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ],
        ];
    }

    $base = '/calendars/' . rawurlencode($calendario) . '/events';
    $qs   = '?conferenceDataVersion=1&sendUpdates=' . gcal_avisar_valido((string)($d['avisar'] ?? 'none'));
    [$codigo, $r] = gcal_api($usuarioId, 'POST', $base . $qs, $evento);
    if ($codigo === 409 && $idPropio !== '') {
        // Ya existe un evento con ese id: lo creó un intento anterior.
        [$cExiste, $rExiste] = gcal_api($usuarioId, 'GET', $base . '/' . rawurlencode($idPropio) . '?conferenceDataVersion=1');
        if ($cExiste === 200 && ($rExiste['status'] ?? '') !== 'cancelled') {
            [$codigo, $r] = [200, $rExiste];
        } else {
            // Cancelado (o ilegible): Google no deja reusar el id, va uno nuevo.
            unset($evento['id']);
            if (isset($evento['conferenceData'])) {
                $evento['conferenceData']['createRequest']['requestId'] = bin2hex(random_bytes(8));
            }
            [$codigo, $r] = gcal_api($usuarioId, 'POST', $base . $qs, $evento);
        }
    }
    if ($codigo < 200 || $codigo >= 300) {
        gcal_recordar_falla($usuarioId, $codigo, (string)($r['error']['message'] ?? ''));
        throw new RuntimeException('Google Calendar rechazó el evento: ' . ($r['error']['message'] ?? ('HTTP ' . $codigo)));
    }
    gcal_limpiar_falla($usuarioId);

    $eventId = (string)($r['id'] ?? '');
    $icalUid = (string)($r['iCalUID'] ?? '');
    $meet    = gcal_extraer_meet($r);

    // La conferencia puede venir "pending": se relee un par de veces.
    if (!empty($d['con_meet']) && $meet === '' && $eventId !== '') {
        for ($i = 0; $i < 3 && $meet === ''; $i++) {
            usleep(700000);
            [$c2, $r2] = gcal_api($usuarioId, 'GET',
                '/calendars/' . rawurlencode($calendario) . '/events/' . rawurlencode($eventId) . '?conferenceDataVersion=1');
            if ($c2 === 200) { $meet = gcal_extraer_meet($r2); }
        }
    }
    return [$eventId, $meet, $icalUid];
}

/**
 * Id propio de un evento de Google a partir de una semilla (el id de la
 * reserva): siempre el mismo para la misma semilla en esta instalación.
 *
 * Google exige caracteres de base32hex (0-9 y a-v) y de 5 a 1024 de largo; el
 * hexadecimal cumple. Lleva el secreto de la instalación: producción y pruebas
 * pueden usar el mismo calendario con reservas de igual número, y no pueden
 * chocar entre sí.
 */
function gcal_id_evento(string $semilla): string
{
    return 'crm' . substr(hash_hmac('sha256', 'gcal-evento|' . $semilla, crm_secret()), 0, 40);
}

/**
 * Saca el enlace de Meet de la respuesta de la API: el punto de entrada de
 * video de conferenceData o, en eventos viejos, `hangoutLink`. '' si no hay.
 */
function gcal_extraer_meet(array $evento): string
{
    foreach ($evento['conferenceData']['entryPoints'] ?? [] as $ep) {
        if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) {
            return (string)$ep['uri'];
        }
    }
    return (string)($evento['hangoutLink'] ?? '');
}

/**
 * Reprograma un evento existente.
 *
 * Sin aviso de Google por defecto, por lo mismo que al crearlo: el aviso del
 * cambio lo manda el CRM con la misma invitación y la secuencia nueva. En
 * $secuencia devuelve la `sequence` con la que quedó el evento en Google, que
 * es la que tiene que superar la invitación del CRM para que el calendario del
 * cliente la tome como una actualización y no la descarte por vieja.
 *
 * @return bool true si Google aceptó el cambio (2xx)
 */
function gcal_mover_evento(int $usuarioId, string $eventId, int $inicio, int $fin,
                           string $calendario = 'primary', string $avisar = 'none',
                           ?int &$secuencia = null, ?string $zona = null): bool
{
    $secuencia = null;
    $zona = $zona ?: 'America/Argentina/Buenos_Aires';
    [$codigo, $r] = gcal_api($usuarioId, 'PATCH',
        '/calendars/' . rawurlencode($calendario) . '/events/' . rawurlencode($eventId)
        . '?sendUpdates=' . gcal_avisar_valido($avisar),
        ['start' => ['dateTime' => date('c', $inicio), 'timeZone' => $zona],
         'end'   => ['dateTime' => date('c', $fin),    'timeZone' => $zona]]);
    $ok = $codigo >= 200 && $codigo < 300;
    if ($ok && isset($r['sequence'])) { $secuencia = (int)$r['sequence']; }
    return $ok;
}

/**
 * Cancela un evento. A los invitados sólo les avisa Google si se pide.
 * Devuelve true también si ya no existía (410).
 */
function gcal_borrar_evento(int $usuarioId, string $eventId, string $calendario = 'primary',
                            string $avisar = 'none'): bool
{
    [$codigo] = gcal_api($usuarioId, 'DELETE',
        '/calendars/' . rawurlencode($calendario) . '/events/' . rawurlencode($eventId)
        . '?sendUpdates=' . gcal_avisar_valido($avisar));
    // 410 = ya estaba borrado; para nosotros es el mismo resultado.
    return ($codigo >= 200 && $codigo < 300) || $codigo === 410;
}

/** La `sequence` actual de un evento, o null si no se pudo leer. */
function gcal_secuencia_evento(int $usuarioId, string $eventId, string $calendario = 'primary'): ?int
{
    [$codigo, $r] = gcal_api($usuarioId, 'GET',
        '/calendars/' . rawurlencode($calendario) . '/events/' . rawurlencode($eventId));
    return $codigo === 200 && isset($r['sequence']) ? (int)$r['sequence'] : null;
}

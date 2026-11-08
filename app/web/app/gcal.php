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
// ============================================================

const GCAL_API = 'https://www.googleapis.com/calendar/v3';

/** Llamada autenticada a la API de Calendar. Devuelve [codigoHttp, cuerpo]. */
function gcal_api(int $usuarioId, string $metodo, string $ruta, ?array $cuerpo = null, int $timeout = 25): array
{
    $token = google_access_token($usuarioId);
    if (!$token) {
        return [401, ['error' => ['message' => 'El usuario no tiene su cuenta de Google conectada.']]];
    }
    $ch = curl_init(GCAL_API . $ruta);
    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_TIMEOUT        => max(2, $timeout),
        CURLOPT_CONNECTTIMEOUT => min(5, max(2, $timeout)),
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
    if ($codigo !== 200) {
        log_app('agenda.freebusy_error', ['tipo' => 'error', 'usuario_id' => $usuarioId,
            'detalle' => 'freeBusy devolvió ' . $codigo . ': ' . ($r['error']['message'] ?? '')]);
        gcal_recordar_falla($usuarioId, $codigo, (string)($r['error']['message'] ?? ''));
        return null;
    }
    gcal_recordar_falla($usuarioId, 0, '');   // anduvo: se limpia el aviso
    $franjas = [];
    foreach ($r['calendars'][$calendario]['busy'] ?? [] as $b) {
        $franjas[] = [strtotime((string)$b['start']), strtotime((string)$b['end'])];
    }
    if ($archivo !== null) {
        @file_put_contents($archivo, json_encode($franjas), LOCK_EX);
        @chmod($archivo, 0600);
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

/**
 * Guarda (o borra) el último problema con Google Calendar para poder avisarlo
 * en el sistema.
 *
 * Sin esto, una falla de Google no se ve: si la API de Calendar está
 * deshabilitada en el proyecto, la reserva se confirma igual, el visitante
 * recibe «quedó agendada» y en el calendario no aparece nada. El error
 * quedaría sólo en la auditoría, donde nadie lo mira.
 */
function gcal_recordar_falla(int $usuarioId, int $codigo, string $mensaje): void
{
    if ($codigo === 0) {
        setting_set('gcal_falla', '');
        return;
    }
    // El mensaje de Google trae la URL exacta para habilitar la API; se guarda
    // entero porque es la instrucción que resuelve el problema.
    setting_set('gcal_falla', json_encode([
        'codigo'     => $codigo,
        'mensaje'    => mb_substr($mensaje, 0, 600),
        'usuario_id' => $usuarioId,
        'cuando'     => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * ¿Hay un problema pendiente con Google Calendar? Devuelve los datos para
 * mostrarlos, o null si está todo bien.
 */
function gcal_falla_pendiente(): ?array
{
    $j = (string) setting_get('gcal_falla', '');
    if ($j === '') { return null; }
    $d = json_decode($j, true);
    if (!is_array($d)) { return null; }

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
            setting_set('gcal_falla', '');
            return null;
        }
    }

    // El caso más común y el que más cuesta descubrir: la API existe pero está
    // apagada en el proyecto de Google Cloud. Se traduce a algo accionable.
    $m = (string)($d['mensaje'] ?? '');
    $d['apagada'] = stripos($m, 'has not been used in project') !== false
                 || stripos($m, 'is disabled') !== false;
    if (preg_match('#https://console\.(?:developers|cloud)\.google\.com\S+#', $m, $mm)) {
        $d['enlace'] = rtrim($mm[0], '.,)');
    }
    return $d;
}

/** ¿Se pudo consultar la disponibilidad? (para distinguir «libre» de «no sé»). */
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
 * @return array{0:string,1:string,2:string}
 */
function gcal_crear_evento(int $usuarioId, array $d, string $calendario = 'primary'): array
{
    $zona = (string)($d['zona'] ?? '') ?: 'America/Argentina/Buenos_Aires';
    $evento = [
        'summary'     => (string)($d['titulo'] ?? 'Reunión'),
        'description' => (string)($d['descripcion'] ?? ''),
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
    if (!empty($d['con_meet'])) {
        $evento['conferenceData'] = [
            'createRequest' => [
                'requestId'             => bin2hex(random_bytes(8)),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ],
        ];
    }

    $qs = '?conferenceDataVersion=1&sendUpdates=' . gcal_avisar_valido((string)($d['avisar'] ?? 'none'));
    [$codigo, $r] = gcal_api($usuarioId, 'POST', '/calendars/' . rawurlencode($calendario) . '/events' . $qs, $evento);
    if ($codigo < 200 || $codigo >= 300) {
        gcal_recordar_falla($usuarioId, $codigo, (string)($r['error']['message'] ?? ''));
        throw new RuntimeException('Google Calendar rechazó el evento: ' . ($r['error']['message'] ?? ('HTTP ' . $codigo)));
    }
    gcal_recordar_falla($usuarioId, 0, '');

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

/** Saca el enlace de Meet de la respuesta de la API. */
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

/** Cancela un evento. A los invitados sólo les avisa Google si se pide. */
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

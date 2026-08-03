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
function gcal_api(int $usuarioId, string $metodo, string $ruta, ?array $cuerpo = null): array
{
    $token = google_access_token($usuarioId);
    if (!$token) {
        return [401, ['error' => ['message' => 'El usuario no tiene su cuenta de Google conectada.']]];
    }
    $ch = curl_init(GCAL_API . $ruta);
    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_TIMEOUT        => 25,
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
 * devolver una lista vacía. La versión anterior devolvía `[]` en los dos casos
 * y el comentario decía «preferimos no ofrecer horarios a ciegas», pero el
 * código hacía exactamente lo contrario: quien la llamaba leía esa lista vacía
 * como «no hay nada ocupado» y publicaba TODOS los horarios del día como
 * libres. Con el calendario de verdad lleno de reuniones, eso le encimaba
 * reuniones al vendedor.
 *
 * @return array<int,array{0:int,1:int}>|null pares [inicio, fin], o null si no se pudo saber
 */
function gcal_ocupado(int $usuarioId, string $desdeIso, string $hastaIso, string $calendario = 'primary'): ?array
{
    [$codigo, $r] = gcal_api($usuarioId, 'POST', '/freeBusy', [
        'timeMin' => $desdeIso,
        'timeMax' => $hastaIso,
        'items'   => [['id' => $calendario]],
    ]);
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
    return $franjas;
}

/**
 * Guarda (o borra) el último problema con Google Calendar para poder avisarlo
 * en el sistema.
 *
 * Esto existe porque el fallo real que apareció no se veía por ningún lado: la
 * API de Calendar estaba deshabilitada en el proyecto de Google, la reserva se
 * confirmaba igual, el visitante recibía «quedó agendada» y en el calendario no
 * había nada. El error quedaba sólo en la auditoría, donde nadie lo mira.
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
    // y eso NO pasa por acá: el aviso sólo se borraba cuando alguna llamada a
    // Google salía bien, y después de reconectar no hay ninguna hasta que
    // alguien reserve una reunión o pase el cron. Resultado: reconectabas, el
    // token quedaba guardado, y el cartel rojo seguía ahí igual, sin forma de
    // sacarlo. Si la cuenta se volvió a conectar DESPUÉS de la falla, la falla
    // ya no existe.
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

/**
 * Crea un evento con Google Meet y devuelve [eventId, meetUrl].
 *
 * @param array $d inicio|fin|titulo|descripcion|invitados[]|zona|con_meet
 */
function gcal_crear_evento(int $usuarioId, array $d, string $calendario = 'primary'): array
{
    $zona = $d['zona'] ?? 'America/Argentina/Buenos_Aires';
    $evento = [
        'summary'     => (string)($d['titulo'] ?? 'Reunión'),
        'description' => (string)($d['descripcion'] ?? ''),
        'start'       => ['dateTime' => date('c', (int)($d['inicio'] ?? 0)), 'timeZone' => $zona],
        'end'         => ['dateTime' => date('c', (int)($d['fin'] ?? 0)),    'timeZone' => $zona],
        'attendees'   => array_map(
            fn($a) => is_array($a) ? $a : ['email' => $a],
            (array)($d['invitados'] ?? [])
        ),
        'reminders'   => ['useDefault' => false, 'overrides' => [
            ['method' => 'email', 'minutes' => 60],
            ['method' => 'popup', 'minutes' => 10],
        ]],
    ];
    if (!empty($d['con_meet'])) {
        $evento['conferenceData'] = [
            'createRequest' => [
                'requestId'             => bin2hex(random_bytes(8)),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ],
        ];
    }

    $qs = '?conferenceDataVersion=1&sendUpdates=all';
    [$codigo, $r] = gcal_api($usuarioId, 'POST', '/calendars/' . rawurlencode($calendario) . '/events' . $qs, $evento);
    if ($codigo < 200 || $codigo >= 300) {
        gcal_recordar_falla($usuarioId, $codigo, (string)($r['error']['message'] ?? ''));
        throw new RuntimeException('Google Calendar rechazó el evento: ' . ($r['error']['message'] ?? ('HTTP ' . $codigo)));
    }
    gcal_recordar_falla($usuarioId, 0, '');

    $eventId = (string)($r['id'] ?? '');
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
    return [$eventId, $meet];
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

/** Reprograma un evento existente. */
function gcal_mover_evento(int $usuarioId, string $eventId, int $inicio, int $fin, string $calendario = 'primary'): bool
{
    $zona = 'America/Argentina/Buenos_Aires';
    [$codigo] = gcal_api($usuarioId, 'PATCH',
        '/calendars/' . rawurlencode($calendario) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all',
        ['start' => ['dateTime' => date('c', $inicio), 'timeZone' => $zona],
         'end'   => ['dateTime' => date('c', $fin),    'timeZone' => $zona]]);
    return $codigo >= 200 && $codigo < 300;
}

/** Cancela un evento (avisa a los invitados). */
function gcal_borrar_evento(int $usuarioId, string $eventId, string $calendario = 'primary'): bool
{
    [$codigo] = gcal_api($usuarioId, 'DELETE',
        '/calendars/' . rawurlencode($calendario) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all');
    // 410 = ya estaba borrado; para nosotros es el mismo resultado.
    return ($codigo >= 200 && $codigo < 300) || $codigo === 410;
}

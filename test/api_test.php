<?php
declare(strict_types=1);

// ============================================================
//  Test de la API v1 de punta a punta — CRM INTELLIHELP
//
//  Lo que la web le manda al CRM, probado por HTTP de verdad contra la
//  instalación de PRUEBAS: firma, idempotencia, horarios, la reserva con su
//  evento de Google y su correo, la consulta con su tarea, los duplicados y
//  los límites. Es lo único que demuestra que el `REQUEST_URI` llega sin
//  reescribir detrás del RewriteRule (si no, toda firma daría 401).
//
//  ESCRIBE EN LA BASE: crea su propia clave, reserva un horario en el
//  calendario del vendedor del tipo «diagnostico» y deja una consulta. Al
//  final cancela la reserva y borra todo lo que creó. Por eso sólo corre
//  contra la instalación de pruebas (es_testing()).
//
//  Los datos son inventados; el único correo real es el de trabajo del dueño
//  de los datos de prueba (recibe la confirmación y la cancelación).
//
//  Uso (en el servidor; lo corre `deploy.py test --pruebas`):
//      CRM_WEBROOT=<webroot de crm-pruebas> CRM_URL=https://intellihelp.tech/crm-pruebas php api_test.php
// ============================================================

$RAIZ = getenv('CRM_WEBROOT') ?: '/home/u566451014/domains/intellihelp.tech/public_html/crm-pruebas';
require $RAIZ . '/app/bootstrap.php';

$BASE = rtrim((string)(getenv('CRM_URL') ?: ($GLOBALS['CONFIG']['app']['canonical'] ?? '')), '/');
$RUTA = rtrim((string) parse_url($BASE, PHP_URL_PATH), '/');

$ok = 0; $mal = 0;
function ok(string $q, bool $bien, string $detalle = ''): void
{
    global $ok, $mal;
    if ($bien) { $ok++; echo "  [OK] $q\n"; }
    else       { $mal++; echo "  [XX] $q" . ($detalle !== '' ? "\n       → $detalle" : '') . "\n"; }
}
function fin(int $codigo): never
{
    global $ok, $mal;
    echo "\n" . str_repeat('=', 56) . "\n";
    printf("  %d comprobaciones · %d correctas · %d fallidas\n", $ok + $mal, $ok, $mal);
    echo str_repeat('=', 56) . "\n";
    exit($codigo);
}

// ------------------------------------------------------------
//  Sólo en pruebas. Tres controles independientes: el modo de la
//  instalación, la carpeta de la URL y el nombre de la base.
// ------------------------------------------------------------
$base = (string)($GLOBALS['CONFIG']['db']['name'] ?? '');
if (!es_testing() || $RUTA !== '/crm-pruebas' || !str_contains($base, 'pruebas')) {
    fwrite(STDERR, "Este test escribe en la base: corre sólo contra crm-pruebas en modo prueba.\n");
    exit(2);
}
if (!function_exists('curl_init')) { fwrite(STDERR, "Falta la extensión curl.\n"); exit(2); }

echo "== Preparación ==\n";
ok('la migración 008 está aplicada', api_migracion_lista());
ok('la instalación tiene su secreto propio (32 caracteres o más)', api_secreto_app_ok());
ok('la Agenda está en marcha', modulo_activo('agenda'));
$tipo = q1("SELECT t.*, u.email AS usuario_email FROM agenda_tipos t JOIN usuarios u ON u.id = t.usuario_id
            WHERE t.slug = 'diagnostico' AND t.activo = 1 AND u.activo = 1 ORDER BY t.id LIMIT 1");
ok('hay un tipo «diagnostico» activo (lo siembra reinicio.php)', (bool)$tipo);
if ($mal) { fin(1); }

$vendedor = explode('@', (string)$tipo['usuario_email'])[0];
// El correo que recibe los avisos: el de trabajo del dueño de los datos de
// prueba. Nunca el de un cliente.
$correo = (string)(qv("SELECT email FROM usuarios WHERE email = 'bsierra@intellihelp.tech' AND activo = 1")
                ?: $tipo['usuario_email']);
/** Otra «persona» que llega al mismo buzón: la dirección de prueba con +algo. */
$otroCorreo = static function (string $etiqueta) use ($correo): string {
    [$local, $dominio] = explode('@', $correo, 2);
    return $local . '+' . $etiqueta . '@' . $dominio;
};

// Lo que existe antes de empezar, para borrar sólo lo que cree el test.
$maxContacto = (int) qv('SELECT COALESCE(MAX(id), 0) FROM contactos');
$maxCuenta   = (int) qv('SELECT COALESCE(MAX(id), 0) FROM cuentas');
$maxAlerta   = (int) qv('SELECT COALESCE(MAX(id), 0) FROM notificaciones');

/** Una clave de API creada directo en la base. Devuelve [id, clave, secreto]. */
function clave_nueva(string $scope, bool $conSecreto): array
{
    $clave   = 'crm_' . bin2hex(random_bytes(20));
    $secreto = $conSecreto ? bin2hex(random_bytes(32)) : null;
    q('INSERT INTO api_tokens (nombre, prefijo, token_hash, secreto_cifrado, scope) VALUES (?, ?, ?, ?, ?)',
      ['Prueba automática (api_test)', substr($clave, 0, 12), hash('sha256', $clave),
       $secreto !== null ? cifrar($secreto) : null, $scope]);
    return [last_id(), $clave, (string)$secreto];
}
[$idEsc, $claveEsc, $secretoEsc] = clave_nueva('escritura', true);
[$idLec, $claveLec]              = clave_nueva('lectura', false);
$tokens = [$idEsc, $idLec];
$ipHashes = [];

/**
 * Un pedido a la API v1, firmado como lo firma la web.
 *
 * @param array $o clave, secreto, ts, idem, ip, firma (para forzar una), ruta_firma
 * @return array{0:int,1:array,2:string} [código, json, cabeceras]
 */
function api(string $metodo, string $recurso, ?array $cuerpo = null, array $o = []): array
{
    global $BASE, $RUTA, $claveEsc, $secretoEsc, $ipHashes;
    $ts     = (string)($o['ts'] ?? time());
    $json   = $cuerpo === null ? '' : (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ruta   = $RUTA . '/api/v1/' . $recurso;
    $ip     = (string)($o['ip'] ?? bin2hex(random_bytes(32)));
    $idem   = (string)($o['idem'] ?? ('test-' . bin2hex(random_bytes(12))));
    $ipHashes[] = $ip;
    $esPost = $metodo === 'POST';
    // La firma cubre también la idempotencia y el hash de la IP (vacías en un GET).
    $firma  = $o['firma'] ?? api_firma((string)($o['secreto'] ?? $secretoEsc), $ts, $metodo,
                                       (string)($o['ruta_firma'] ?? $ruta),
                                       $esPost ? $idem : '', $esPost ? $ip : '', $json);
    $cab = ['X-ITH-Key: ' . ($o['clave'] ?? $claveEsc), 'X-ITH-Timestamp: ' . $ts, 'X-ITH-Signature: ' . $firma];
    if ($esPost) {
        $cab[] = 'Content-Type: application/json';
        $cab[] = 'X-ITH-Idempotency: ' . $idem;
        $cab[] = 'X-ITH-Client-IP-Hash: ' . $ip;
    }
    $ch = curl_init($BASE . '/api/v1/' . $recurso);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $cab,
    ] + ($metodo === 'POST' ? [CURLOPT_POSTFIELDS => $json] : []));
    $r     = (string) curl_exec($ch);
    $cod   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $corte = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$cod, (array)(json_decode(substr($r, $corte), true) ?: []), substr($r, 0, $corte)];
}

/** El cuerpo de una reserva o consulta con datos inventados. */
function cuerpo(?int $inicio, array $persona = []): array
{
    global $correo, $vendedor;
    $c = [
        'persona' => $persona + [
            'nombre' => 'Juana Prueba', 'empresa' => 'Empresa de Prueba SRL', 'email' => $correo,
            'telefono' => '+54 11 5555-0000', 'servicio' => 'Soporte IT, mesa de ayuda u outsourcing IT',
            'mensaje' => 'Mensaje de prueba automática: no responder.',
        ],
        'consentimiento' => ['aceptado' => true, 'fecha' => date('c'), 'version' => 'web-2026-11',
                             'texto' => 'Acepto que Intellihelp use estos datos para responder mi consulta y coordinar la reunión, según el aviso de privacidad.'],
        'novedades' => false,
        'origen' => ['pagina' => '/diagnostico/', 'canal' => 'Google Ads', 'utm_source' => 'google',
                     'utm_medium' => 'cpc', 'utm_campaign' => 'prueba-integracion', 'utm_term' => '',
                     'utm_content' => '', 'gclid' => '', 'li_fat_id' => '', 'referrer' => 'https://www.google.com/',
                     'landing' => '/diagnostico/', 'visitas_landing' => 1],
    ];
    if ($inicio !== null) {
        $c = ['tipo' => 'diagnostico', 'vendedor' => $vendedor, 'inicio' => $inicio] + $c;
    }
    return $c;
}

$reservas = [];   // ids de agenda_reservas creadas
$codigoSalida = 1;
try {
    echo "== Autenticación ==\n";
    [$c, $j] = api('GET', 'disponibilidad?tipo=diagnostico', null, ['clave' => '']);
    ok('sin clave: 401 falta_clave', $c === 401 && ($j['error'] ?? '') === 'falta_clave', "código $c");
    [$c, $j] = api('GET', 'disponibilidad?tipo=diagnostico', null, ['firma' => str_repeat('0', 64)]);
    ok('firma inventada: 401 firma_invalida', $c === 401 && ($j['error'] ?? '') === 'firma_invalida', "código $c");
    [$c, $j] = api('GET', 'disponibilidad?tipo=diagnostico', null, ['ts' => time() - 600]);
    ok('hora de hace 10 minutos: 401 hora_fuera_de_rango', $c === 401 && ($j['error'] ?? '') === 'hora_fuera_de_rango', "código $c");
    [$c, $j] = api('GET', 'disponibilidad?tipo=diagnostico', null, ['ruta_firma' => '/crm/api/v1/disponibilidad?tipo=diagnostico']);
    ok('firma hecha para producción: no sirve en pruebas', $c === 401, "código $c");
    [$c, $j] = api('GET', 'disponibilidad?tipo=diagnostico', null, ['clave' => $claveLec]);
    ok('clave de sólo lectura: 403 sin_permiso', $c === 403 && ($j['error'] ?? '') === 'sin_permiso', "código $c");
    [$c, , $cab] = api('GET', 'reserva');
    ok('reserva por GET: 405 con Allow', $c === 405 && stripos($cab, 'Allow: POST') !== false, "código $c");

    // La clave de la web es de escritura: sola, sin el secreto, no sirve para leer nada.
    $ch = curl_init($BASE . '/api/estado');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $claveEsc]]);
    curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    ok('la clave de escritura no lee api/estado: 401', $c === 401, "código $c");

    echo "== Horarios ==\n";
    $desde = date('Y-m-d');
    $hasta = date('Y-m-d', strtotime('+14 days'));
    [$c, $j, $cab] = api('GET', "disponibilidad?tipo=diagnostico&vendedor=$vendedor&desde=$desde&hasta=$hasta");
    ok('la firma con la consulta pasa: el REQUEST_URI llega sin reescribir', $c === 200,
       "código $c " . ($j['error'] ?? ''));
    ok('sin caché', stripos($cab, 'no-store') !== false);
    ok('no abre sesión para la API', stripos($cab, 'Set-Cookie') === false);
    $slots = [];
    foreach ((array)($j['dias'] ?? []) as $dia) {
        foreach ((array)$dia['horarios'] as $h) { $slots[] = (int)$h['ts']; }
    }
    ok('hay horarios para ofrecer (Google conectado y horario cargado)', count($slots) >= 3,
       count($slots) . ' horarios · ' . ($j['error'] ?? ''));
    $okIso = true;
    foreach ((array)($j['dias'] ?? []) as $dia) {
        foreach ((array)$dia['horarios'] as $h) {
            $okIso = $okIso && strtotime((string)$h['inicio']) === (int)$h['ts'];
        }
    }
    ok('el inicio ISO y los segundos dicen lo mismo', $okIso);
    [$c, $j] = api('GET', 'disponibilidad?tipo=no-existe');
    ok('tipo inexistente: 404', $c === 404 && ($j['error'] ?? '') === 'tipo_inexistente', "código $c");
    if (count($slots) < 3) { throw new RuntimeException('Sin horarios no se puede seguir con la reserva.'); }

    echo "== Reserva ==\n";
    $idem   = 'test-' . bin2hex(random_bytes(12));
    $cuerpo = cuerpo($slots[0]);
    [$c, $j] = api('POST', 'reserva', $cuerpo, ['idem' => $idem]);
    ok('reserva nueva: 201', $c === 201 && !empty($j['reserva']['id']), "código $c " . json_encode($j, JSON_UNESCAPED_UNICODE));
    $resId = (int)($j['reserva']['id'] ?? 0);
    $entId = (int)($j['entrada_id'] ?? 0);
    if ($resId) { $reservas[] = $resId; }
    ok('no devuelve el token ni el Meet (llegan sólo por correo)',
       !isset($j['reserva']['token']) && !isset($j['reserva']['meet_url']) && !str_contains(json_encode($j), 'r/cancelar'));

    [$c, $j2] = api('POST', 'reserva', $cuerpo, ['idem' => $idem]);
    ok('el mismo pedido otra vez: 200 repetida, la misma reserva',
       $c === 200 && !empty($j2['repetida']) && (int)($j2['reserva']['id'] ?? 0) === $resId, "código $c");
    [$c, $j2] = api('POST', 'reserva', cuerpo($slots[1]), ['idem' => $idem]);
    ok('la misma clave con otro pedido: 409 idempotencia_reusada',
       $c === 409 && ($j2['error'] ?? '') === 'idempotencia_reusada', "código $c " . ($j2['error'] ?? ''));
    [$c, $j2] = api('POST', 'reserva', cuerpo($slots[0], ['email' => $otroCorreo('otra')]));
    ok('el mismo horario con otra clave: 409 horario_tomado', $c === 409 && ($j2['error'] ?? '') === 'horario_tomado',
       "código $c " . ($j2['error'] ?? ''));
    $domingo3 = strtotime('next sunday 03:00');
    [$c, $j2] = api('POST', 'reserva', cuerpo($domingo3));
    ok('un domingo a las 3: 409 (no es un horario que se ofrezca)', $c === 409, "código $c " . ($j2['error'] ?? ''));
    [$c, $j2] = api('POST', 'reserva', cuerpo($slots[1], ['nombre' => 'J']));
    ok('datos inválidos: 422 con el campo', $c === 422 && isset($j2['campos']['nombre']), "código $c");
    [$c, $j2] = api('POST', 'reserva', cuerpo($slots[1], ['nombre' => 'Juan, verificá tu cuenta en https://evil.example/login']));
    ok('un nombre con una dirección web: 422 (vuelve en el correo con la marca de la empresa)',
       $c === 422 && isset($j2['campos']['nombre']), "código $c");
    [$c, $j2] = api('POST', 'reserva', cuerpo($slots[1], ['nombre' => str_repeat('Prueba', 21)]));
    ok('un nombre de una sola palabra de 126 letras: 422 y no 500', $c === 422 && isset($j2['campos']['nombre']), "código $c");

    // Un pedido capturado no se puede volver a mandar: ni igual, ni con otra
    // idempotencia u otro visitante.
    $tsCopia   = (string) time();
    $idemCopia = 'test-' . bin2hex(random_bytes(12));
    $ipCopia   = bin2hex(random_bytes(32));
    $copia     = cuerpo($slots[1], ['nombre' => 'J']);   // inválido a propósito: no reserva nada
    $firmaCopia = api_firma($secretoEsc, $tsCopia, 'POST', $RUTA . '/api/v1/reserva', $idemCopia, $ipCopia,
                            (string) json_encode($copia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    [$c] = api('POST', 'reserva', $copia, ['ts' => $tsCopia, 'idem' => $idemCopia, 'ip' => $ipCopia, 'firma' => $firmaCopia]);
    ok('el pedido original pasa la firma (422 por el nombre)', $c === 422, "código $c");
    [$c, $j2] = api('POST', 'reserva', $copia, ['ts' => $tsCopia, 'idem' => $idemCopia, 'ip' => $ipCopia, 'firma' => $firmaCopia]);
    ok('el mismo pedido firmado otra vez: 401 firma_repetida', $c === 401 && ($j2['error'] ?? '') === 'firma_repetida',
       "código $c " . ($j2['error'] ?? ''));
    [$c, $j2] = api('POST', 'reserva', $copia, ['ts' => $tsCopia, 'ip' => bin2hex(random_bytes(32)), 'firma' => $firmaCopia]);
    ok('con otra idempotencia y otro visitante: 401 firma_invalida', $c === 401 && ($j2['error'] ?? '') === 'firma_invalida',
       "código $c " . ($j2['error'] ?? ''));
    $sinCons = cuerpo($slots[1]);
    $sinCons['consentimiento']['aceptado'] = false;
    [$c, $j2] = api('POST', 'reserva', $sinCons);
    ok('sin consentimiento: 422 falta_consentimiento', $c === 422 && ($j2['error'] ?? '') === 'falta_consentimiento', "código $c");

    // Lo que quedó en la base.
    $r = q1('SELECT * FROM agenda_reservas WHERE id = ?', [$resId]) ?? [];
    $e = q1('SELECT * FROM entradas_web WHERE id = ?', [$entId]) ?? [];
    ok('la reserva quedó «web», con el consentimiento',
       ($r['origen'] ?? '') === 'web' && ($r['consentimiento_version'] ?? '') === 'web-2026-11');
    ok('la entrada sabe de qué página y por qué canal llegó',
       ($e['estado'] ?? '') === 'ok' && ($e['origen_pagina'] ?? '') === '/diagnostico/'
       && ($e['canal'] ?? '') === 'Google Ads' && ($e['utm_campaign'] ?? '') === 'prueba-integracion'
       && (int)($e['reserva_id'] ?? 0) === $resId);
    ok('la IP no se guarda: sólo su hash', preg_match('/^[0-9a-f]{64}$/', (string)($e['ip_hash'] ?? '')) === 1);
    $act = q1('SELECT * FROM actividades WHERE id = ?', [(int)($r['actividad_id'] ?? 0)]) ?? [];
    ok('la reunión está en Actividades con la empresa', ($act['tipo'] ?? '') === 'reunion' && !empty($act['cuenta_id']));
    ok('y en su descripción la línea de origen (interna del CRM)', str_contains((string)($act['descripcion'] ?? ''), 'canal Google Ads'));
    $ct = q1('SELECT * FROM contactos WHERE id = ?', [(int)($r['contacto_id'] ?? 0)]) ?? [];
    if ((int)($ct['id'] ?? 0) > $maxContacto) {
        ok('el contacto nuevo entra «Sitio web» y fuera de las campañas',
           ($ct['fuente'] ?? '') === 'Sitio web' && (int)($ct['no_email'] ?? 0) === 1);
    }

    // El evento y el correo van después de responder: se espera un poco.
    for ($i = 0; $i < 30; $i++) {
        $r = q1('SELECT * FROM agenda_reservas WHERE id = ?', [$resId]) ?? [];
        if (!empty($r['gcal_event_id']) && (!empty($r['avisada_en']) || !smtp_configurado())) { break; }
        usleep(500000);
    }
    ok('el evento de Google se creó después de responder', !empty($r['gcal_event_id']));
    ok('con Meet', !empty($r['meet_url']));
    if (smtp_configurado()) {
        ok('y salió la confirmación por correo', !empty($r['avisada_en']));
    } else {
        ok('SMTP configurado en pruebas (sin eso no sale la confirmación)', false, 'Ajustes → Correo');
    }
    if (!empty($r['gcal_event_id'])) {
        [$gc, $ev] = gcal_api((int)$r['usuario_id'], 'GET', '/calendars/primary/events/' . rawurlencode((string)$r['gcal_event_id']));
        $desc = (string)($ev['description'] ?? '');
        ok('el evento NO lleva el mensaje, las UTM ni el teléfono',
           $gc === 200 && !str_contains($desc, 'prueba automática') && !str_contains($desc, 'prueba-integracion')
           && !str_contains($desc, '5555'), $desc);
        ok('el título lleva nombre y empresa', str_contains((string)($ev['summary'] ?? ''), 'Juana Prueba (Empresa de Prueba SRL)'));
        ok('la invitación del CRM usa el identificador de Google', ($r['ics_uid'] ?? '') === ($ev['iCalUID'] ?? '-'));
    }

    echo "== Dos personas al mismo horario, al mismo tiempo ==\n";
    $mh = curl_multi_init();
    $hs = [];
    foreach ([['nombre' => 'Persona Uno', 'email' => $otroCorreo('uno')],
              ['nombre' => 'Persona Dos', 'email' => $otroCorreo('dos')]] as $p) {
        $json = (string) json_encode(cuerpo($slots[2], $p), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $idemC = 'test-' . bin2hex(random_bytes(12));
        $ipC   = bin2hex(random_bytes(32));
        $ipHashes[] = $ipC;
        $ch = curl_init($BASE . '/api/v1/reserva');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-ITH-Key: ' . $claveEsc,
                'X-ITH-Timestamp: ' . $ts,
                'X-ITH-Signature: ' . api_firma($secretoEsc, $ts, 'POST', $RUTA . '/api/v1/reserva', $idemC, $ipC, $json),
                'X-ITH-Idempotency: ' . $idemC, 'X-ITH-Client-IP-Hash: ' . $ipC],
        ]);
        curl_multi_add_handle($mh, $ch);
        $hs[] = $ch;
    }
    do { $st = curl_multi_exec($mh, $activos); if ($activos) { curl_multi_select($mh, 1.0); } }
    while ($activos && $st === CURLM_OK);
    $codigos = [];
    foreach ($hs as $ch) {
        $codigos[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $jj = json_decode((string) curl_multi_getcontent($ch), true) ?: [];
        if (!empty($jj['reserva']['id'])) { $reservas[] = (int)$jj['reserva']['id']; }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    sort($codigos);
    ok('una sola se queda con el horario', $codigos === [201, 409], implode(', ', $codigos));

    echo "== Tope por correo ==\n";
    // El buzón de prueba ya tiene dos reuniones futuras (la primera y la del
    // choque, con +uno o +dos). Una tercera, aunque llegue con otra etiqueta
    // +algo, no entra: el límite por IP no frena a quien rota direcciones.
    // Con un horario que siga libre (los de al lado de las reservas ya no lo
    // están por el margen): así lo único que puede frenarla es el tope.
    [, $jl] = api('GET', "disponibilidad?tipo=diagnostico&vendedor=$vendedor&desde=$desde&hasta=$hasta");
    $libres = [];
    foreach ((array)($jl['dias'] ?? []) as $dia) {
        foreach ((array)$dia['horarios'] as $h) { $libres[] = (int)$h['ts']; }
    }
    $libre = $libres ? end($libres) : $slots[1];
    [$c, $j2] = api('POST', 'reserva', cuerpo($libre, ['nombre' => 'Persona Tres', 'email' => $otroCorreo('tres')]));
    if (!empty($j2['reserva']['id'])) { $reservas[] = (int)$j2['reserva']['id']; }
    ok('la tercera reunión futura del mismo buzón: 429 limite_reservas',
       $c === 429 && ($j2['error'] ?? '') === 'limite_reservas', "código $c " . ($j2['error'] ?? ''));

    echo "== Consulta sin reunión ==\n";
    $ipCons = bin2hex(random_bytes(32));
    $cons   = cuerpo(null, ['mensaje' => 'Consulta de prueba automática ' . bin2hex(random_bytes(4))]);
    [$c, $j] = api('POST', 'entrada', $cons, ['ip' => $ipCons]);
    $entCons = (int)($j['entrada_id'] ?? 0);
    ok('consulta nueva: 201', $c === 201 && $entCons > 0, "código $c " . ($j['error'] ?? ''));
    $e = q1('SELECT * FROM entradas_web WHERE id = ?', [$entCons]) ?? [];
    $tarea = q1('SELECT * FROM actividades WHERE id = ?', [(int)($e['actividad_id'] ?? 0)]) ?? [];
    ok('deja la tarea de contestar a nombre del responsable',
       ($tarea['tipo'] ?? '') === 'tarea' && str_starts_with((string)($tarea['asunto'] ?? ''), 'Responder consulta web')
       && (int)($tarea['propietario_id'] ?? 0) === entrada_web_dueno());
    ok('y una nota con el mensaje', (bool) qv('SELECT 1 FROM notas WHERE id = ? AND cuerpo LIKE ?',
       [(int)($e['nota_id'] ?? 0), '%Consulta de prueba automática%']));
    ok('sin posibles clientes ni negocios', (int) qv("SELECT COUNT(*) FROM negocios WHERE creado_en >= NOW() - INTERVAL 5 MINUTE") === 0);
    [$c, $j] = api('POST', 'entrada', $cons, ['ip' => $ipCons]);
    ok('la misma consulta a los segundos: 200 repetida (doble clic)', $c === 200 && !empty($j['repetida']), "código $c");
    for ($i = 0; $i < 3; $i++) { api('POST', 'entrada', $cons, ['ip' => $ipCons]); }
    [$c, $j, $cab] = api('POST', 'entrada', $cons, ['ip' => $ipCons]);
    ok('la sexta en la hora desde el mismo visitante: 429 con Retry-After',
       $c === 429 && stripos($cab, 'Retry-After:') !== false, "código $c");
    ok('los duplicados no generaron otra tarea',
       (int) qv("SELECT COUNT(*) FROM entradas_web WHERE token_id = ? AND tipo = 'consulta' AND estado = 'ok'", [$idEsc]) === 1);

    echo "== Auditoría ==\n";
    $lineas = q("SELECT detalle FROM actividad WHERE canal = 'api' AND actor = 'Prueba automática (api_test)'
                 ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    $todo = implode("\n", $lineas);
    ok('cada pedido queda en la auditoría de la API', count($lineas) >= 10, count($lineas) . ' líneas');
    ok('sin datos de la persona', !str_contains($todo, 'Juana') && !str_contains($todo, $correo) && !str_contains($todo, '5555'));

    $codigoSalida = $mal ? 1 : 0;
} catch (\Throwable $e) {
    ok('el test terminó sin errores', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    echo "== Limpieza ==\n";
    try {
        // Primero se cancela: así se borra el evento de Google y sale la
        // cancelación, igual que si la persona usara el enlace del correo.
        foreach (array_unique($reservas) as $id) {
            $tok = (string) qv('SELECT token FROM agenda_reservas WHERE id = ?', [$id]);
            if ($tok !== '') { agenda_cancelar($tok, 'Limpieza del test de la API'); }
        }
        $ent = q('SELECT * FROM entradas_web WHERE token_id IN (?, ?)', [$idEsc, $idLec])->fetchAll();
        $resIds = array_values(array_unique(array_filter(array_merge($reservas, array_column($ent, 'reserva_id')))));
        $actIds = array_values(array_unique(array_filter(array_merge(
            array_column($ent, 'actividad_id'),
            $resIds ? q('SELECT actividad_id FROM agenda_reservas WHERE id IN (' . implode(',', array_map('intval', $resIds)) . ')')
                        ->fetchAll(PDO::FETCH_COLUMN) : []))));
        $notaIds = array_values(array_filter(array_column($ent, 'nota_id')));
        $ctIds   = array_values(array_unique(array_filter(array_column($ent, 'contacto_id'), fn($id) => (int)$id > $maxContacto)));
        $cuIds   = array_values(array_unique(array_filter(array_column($ent, 'cuenta_id'), fn($id) => (int)$id > $maxCuenta)));
        $en = static fn(array $ids): string => implode(',', array_map('intval', $ids ?: [0]));

        q('DELETE FROM entradas_web WHERE token_id IN (?, ?)', [$idEsc, $idLec]);
        // La consulta usa el correo de trabajo del dueño de los datos de prueba,
        // que ya es un contacto: su alerta apunta a ese contacto viejo y con
        // sólo mirar los contactos nuevos quedaba en la campana después de
        // cada corrida. Se borran también las alertas creadas durante el test
        // que apuntan a cualquier contacto de sus entradas.
        $ctTodos = array_values(array_unique(array_filter(array_column($ent, 'contacto_id'))));
        q("DELETE FROM notificaciones WHERE (entidad = 'reserva' AND entidad_id IN ({$en($resIds)}))
                                        OR (entidad = 'contacto' AND entidad_id IN ({$en($ctIds)}))
                                        OR (id > ? AND tipo = 'web' AND entidad = 'contacto'
                                            AND entidad_id IN ({$en($ctTodos)}))", [$maxAlerta]);
        q("DELETE FROM actividad_invitados WHERE actividad_id IN ({$en($actIds)})");
        q("DELETE FROM agenda_reservas WHERE id IN ({$en($resIds)}) OR reprogramada_de IN ({$en($resIds)})");
        q("DELETE FROM actividades WHERE id IN ({$en($actIds)})");
        q("DELETE FROM notas WHERE id IN ({$en($notaIds)})
                             OR (entidad = 'contacto' AND entidad_id IN ({$en($ctIds)}))");
        q("DELETE FROM contactos WHERE id IN ({$en($ctIds)}) AND id > ?", [$maxContacto]);
        q("DELETE FROM cuentas WHERE id IN ({$en($cuIds)}) AND id > ?", [$maxCuenta]);
        foreach (array_unique($ipHashes) as $h) {
            q('DELETE FROM api_limites WHERE clave LIKE ?', ['ip:' . $h . ':%']);
        }
        foreach ($tokens as $t) { q('DELETE FROM api_limites WHERE clave LIKE ?', ['k:' . $t . ':%']); }
        q('DELETE FROM api_tokens WHERE id IN (?, ?)', [$idEsc, $idLec]);
        ok('se borró todo lo que creó el test', !qv('SELECT COUNT(*) FROM api_tokens WHERE id IN (?, ?)', [$idEsc, $idLec]));
    } catch (\Throwable $e) {
        ok('limpieza', false, get_class($e) . ': ' . $e->getMessage());
        $codigoSalida = 1;
    }
}
fin($codigoSalida);

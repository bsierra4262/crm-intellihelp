<?php
declare(strict_types=1);

// ============================================================
//  Lectura de la casilla (IMAP): rebotes y respuestas.
//
//  ¿Por qué un cliente IMAP propio y no la extensión `imap` de PHP?
//
//  Porque la extensión usa la librería c-client, que **no envía SNI** al
//  negociar TLS. Gmail responde a eso con un certificado de descarte y PHP
//  aborta con el error:
//
//      Certificate failure for imap.gmail.com: self-signed certificate:
//      /OU=No SNI provided - please fix your client./CN=invalid2.invalid
//
//  La salida fácil es `/novalidate-cert`, pero eso apaga la verificación del
//  certificado: deja la conexión abierta a un intermediario. La salida correcta
//  es hablar IMAP sobre un socket propio, que **sí** manda SNI y **sí** valida
//  el certificado. Es el mismo camino que ya tomamos con SMTP.
//
//  La lógica de detección (qué es un rebote, cuál es definitivo, de quién es la
//  dirección que falló) es la de Pulsemail, que ya está probada en producción.
// ============================================================

const REMITENTES_REBOTE = ['mailer-daemon', 'postmaster', 'mail-daemon'];
const ASUNTOS_REBOTE = [
    'delivery status notification',
    'undelivered mail returned to sender',
    'mail delivery failed',
    'mail delivery failure',
    'returned mail',
    'undeliverable',
    'failure notice',
    'no se pudo entregar',
    'correo no entregado',
    'devolución de correo',
];

function imap_cfg(): array
{
    $c = (array)($GLOBALS['CONFIG']['imap'] ?? []);
    foreach (['host', 'port', 'user', 'pass'] as $k) {
        $v = setting_get('imap_' . $k, null);
        if ($v !== null && $v !== '') { $c[$k] = $v; }
    }
    // Por defecto se usa la misma cuenta con la que se manda.
    $smtp = smtp_cfg();
    $c += ['host' => 'imap.gmail.com', 'port' => 993,
           'user' => $smtp['user'] ?? '', 'pass' => $smtp['pass'] ?? ''];
    return $c;
}

function imap_configurado(): bool
{
    $c = imap_cfg();
    return !empty($c['user']) && !empty($c['pass']);
}

// ------------------------------------------------------------
//  Cliente IMAP mínimo (lo que necesitamos y nada más)
// ------------------------------------------------------------

/**
 * Abre la conexión y autentica. Devuelve el socket o null.
 *
 * El `peer_name` explícito es lo que hace que la negociación mande SNI: sin
 * eso Gmail devuelve el certificado de descarte y la conexión se cae.
 */
function imap_conectar(?string &$err = null, ?array $cfg = null)
{
    $c    = $cfg ?? imap_cfg();
    $host = (string)($c['host'] ?: 'imap.gmail.com');
    $port = (int)($c['port'] ?: 993);

    if (empty($c['user']) || empty($c['pass'])) {
        $err = 'La casilla de entrada no está configurada.';
        return null;
    }

    $ctx = stream_context_create(['ssl' => [
        'peer_name'         => $host,
        'SNI_enabled'       => true,
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'allow_self_signed' => false,
    ]]);

    // Se abre en texto plano y se sube a TLS a mano: así el contexto con SNI
    // se aplica de verdad (con `ssl://` algunos PHP lo arman antes de tiempo).
    $fp = @stream_socket_client("tcp://$host:$port", $en, $es, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $err = "No se pudo conectar a $host:$port ($es).";
        return null;
    }
    stream_set_timeout($fp, 30);

    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        $err = 'No se pudo establecer la conexión segura con ' . $host
             . '. Revisá que el servidor y el puerto sean correctos.';
        fclose($fp);
        return null;
    }

    $saludo = fgets($fp, 2048);
    if (!$saludo || !str_starts_with(ltrim((string)$saludo), '* OK')) {
        $err = 'El servidor no saludó como se esperaba: ' . trim((string)$saludo);
        fclose($fp);
        return null;
    }

    $r = imap_cmd($fp, 'LOGIN ' . imap_cita((string)$c['user']) . ' ' . imap_cita((string)$c['pass']));
    if (!$r['ok']) {
        // Gmail contesta con este texto cuando falta la contraseña de aplicación.
        $err = str_contains(mb_strtolower($r['texto']), 'credentials')
            ? 'Usuario o contraseña incorrectos. Con Google Workspace hay que usar una '
              . 'contraseña de aplicación, no la contraseña normal de la cuenta.'
            : 'No se pudo iniciar sesión: ' . $r['texto'];
        fclose($fp);
        return null;
    }
    return $fp;
}

function imap_cerrar($fp): void
{
    if (is_resource($fp)) {
        @imap_cmd($fp, 'LOGOUT');
        @fclose($fp);
    }
}

/** Entrecomilla un valor para el protocolo IMAP. */
function imap_cita(string $v): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

/**
 * Manda un comando y devuelve la respuesta completa.
 *
 * IMAP mezcla líneas sueltas con «literales» (`{123}` + 123 bytes crudos), así
 * que no alcanza con leer por líneas: hay que leer exactamente lo que anuncia.
 *
 * @return array{ok:bool, texto:string, lineas:array<int,string>, crudo:string}
 */
function imap_cmd($fp, string $comando, int $limiteBytes = 8388608): array
{
    static $n = 0;
    $tag = sprintf('a%04d', ++$n);
    fwrite($fp, $tag . ' ' . $comando . "\r\n");

    $lineas = [];
    $crudo  = '';
    $ok     = false;
    $texto  = '';
    $leidos = 0;

    while (!feof($fp)) {
        $linea = fgets($fp, 8192);
        if ($linea === false) { break; }
        $leidos += strlen($linea);
        $crudo  .= $linea;

        // Literal anunciado al final de la línea: se leen esos bytes exactos.
        if (preg_match('/\{(\d+)\}\r?\n$/', $linea, $m)) {
            $faltan = (int)$m[1];
            $buf = '';
            while ($faltan > 0 && !feof($fp)) {
                $trozo = fread($fp, min($faltan, 65536));
                if ($trozo === false || $trozo === '') { break; }
                $buf    .= $trozo;
                $faltan -= strlen($trozo);
            }
            $crudo  .= $buf;
            $leidos += strlen($buf);
            $lineas[] = rtrim($linea, "\r\n") . "\n" . $buf;
            continue;
        }

        $lineas[] = rtrim($linea, "\r\n");

        if (str_starts_with($linea, $tag . ' ')) {
            $resto = trim(substr($linea, strlen($tag) + 1));
            $ok    = str_starts_with($resto, 'OK');
            $texto = $resto;
            break;
        }
        if ($leidos > $limiteBytes) { break; }   // red de seguridad
    }

    return ['ok' => $ok, 'texto' => $texto, 'lineas' => $lineas, 'crudo' => $crudo];
}

/** Decodifica un encabezado MIME (=?UTF-8?B?...?=) a texto plano. */
function imap_texto(?string $s): string
{
    $s = trim((string)$s);
    if ($s === '') { return ''; }
    $out = preg_replace_callback(
        '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
        static function (array $m): string {
            $juego = strtoupper($m[1]);
            $texto = strtoupper($m[2]) === 'B'
                ? (base64_decode($m[3], true) ?: '')
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            if ($juego === 'UTF-8' || $juego === 'US-ASCII') { return $texto; }
            $conv = @mb_convert_encoding($texto, 'UTF-8', $juego);
            return $conv !== false ? $conv : $texto;
        },
        $s
    );
    // Entre dos palabras codificadas seguidas no va espacio (RFC 2047).
    return trim(preg_replace('/\?=\s+=\?/', '', (string)$out) ?: (string)$out);
}

/** Saca un encabezado del bloque de cabeceras. */
function imap_cabecera(string $cabeceras, string $nombre): string
{
    // Las cabeceras plegadas continúan en la línea siguiente con espacio o tab.
    if (preg_match('/^' . preg_quote($nombre, '/') . ':\s*(.*(?:\r?\n[ \t].*)*)/mi', $cabeceras, $m)) {
        return trim(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]) ?? $m[1]);
    }
    return '';
}

/** Extrae la dirección de un encabezado tipo `Nombre <x@y.com>`. */
function imap_direccion(string $cabecera): string
{
    if (preg_match('/<([^>]+)>/', $cabecera, $m)) { return normalizar_email($m[1]); }
    if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $cabecera, $m)) {
        return normalizar_email($m[0]);
    }
    return '';
}

// ------------------------------------------------------------
//  Estado por buzón
// ------------------------------------------------------------

function imap_ultimo_uid(string $buzon): int
{
    return (int) (qv('SELECT ultimo_uid FROM imap_estado WHERE buzon = ?', [$buzon]) ?: 0);
}

function imap_guardar_uid(string $buzon, int $uid): void
{
    q('INSERT INTO imap_estado (buzon, ultimo_uid, ultima_corrida) VALUES (?, ?, NOW())
       ON DUPLICATE KEY UPDATE ultimo_uid = GREATEST(ultimo_uid, VALUES(ultimo_uid)), ultima_corrida = NOW()',
      [$buzon, $uid]);
}

// ------------------------------------------------------------
//  Clasificación de rebotes (lógica de Pulsemail)
// ------------------------------------------------------------

function es_rebote(string $de, string $asunto): bool
{
    $de = mb_strtolower($de);
    foreach (REMITENTES_REBOTE as $r) {
        if (str_contains($de, $r)) { return true; }
    }
    $asunto = mb_strtolower($asunto);
    foreach (ASUNTOS_REBOTE as $a) {
        if (str_contains($asunto, $a)) { return true; }
    }
    return false;
}

/**
 * ¿El rebote es DEFINITIVO o pasajero?
 *
 * Importa porque un rebote definitivo archiva el contacto y uno pasajero no:
 * una casilla llena o un servidor caído se arreglan solos, pero una dirección
 * que no existe no va a existir mañana.
 */
function es_rebote_duro(string $cuerpo): bool
{
    if (preg_match('/Status:\s*([45])\.\d+\.\d+/i', $cuerpo, $m)) {
        return $m[1] === '5';
    }
    if (preg_match('/\b(4\d\d)[\s-]/', $cuerpo) && !preg_match('/\b5[05]\d[\s-]/', $cuerpo)) {
        return false;
    }
    $definitivas = [
        'user unknown', 'no such user', 'unknown user', 'does not exist',
        'recipient not found', 'no mailbox', 'mailbox unavailable',
        'address rejected', 'recipient address rejected', 'invalid recipient',
        'domain not found', 'host or domain name not found', 'nxdomain',
        'no such domain', 'unrouteable address', 'account has been disabled',
        'account is disabled', 'user doesn\'t have',
        'no existe', 'usuario desconocido', 'destinatario no encontrado',
        'direccion no existe', 'dirección no existe', 'cuenta deshabilitada',
    ];
    $temporales = [
        'mailbox full', 'quota exceeded', 'over quota', 'try again later',
        'temporarily deferred', 'temporary failure', 'greylist',
        'buzon lleno', 'buzón lleno', 'cuota excedida', 'intente mas tarde',
    ];
    $c = mb_strtolower($cuerpo);
    foreach ($temporales as $t) {
        if (str_contains($c, $t)) { return false; }
    }
    foreach ($definitivas as $d) {
        if (str_contains($c, $d)) { return true; }
    }
    // Ante la duda, se trata como temporal: archivar un contacto por error es
    // peor que dejarlo marcado y revisarlo después.
    return false;
}

/** Saca del cuerpo del rebote la dirección que falló. */
function extraer_destinatario_rebote(string $cuerpo): array
{
    $encontrados = [];
    if (preg_match_all('/Final-Recipient:\s*rfc822;\s*([^\s<>]+@[^\s<>]+)/i', $cuerpo, $m)) {
        $encontrados = array_merge($encontrados, $m[1]);
    }
    if (preg_match_all('/Original-Recipient:\s*rfc822;\s*([^\s<>]+@[^\s<>]+)/i', $cuerpo, $m)) {
        $encontrados = array_merge($encontrados, $m[1]);
    }
    if (!$encontrados) {
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $cuerpo, $m)) {
            $encontrados = $m[0];
        }
    }
    return array_values(array_unique(array_map('normalizar_email', $encontrados)));
}

// ------------------------------------------------------------
//  Revisión de la casilla
// ------------------------------------------------------------

/**
 * Escanea la casilla y actualiza rebotes y respuestas.
 *
 * La primera pasada mira los últimos N días; las siguientes, sólo lo que llegó
 * después del último UID visto. Sin eso, el cron cada 5 minutos releería la
 * casilla entera todo el tiempo.
 *
 * @return array{rebotes:int, duros:int, respuestas:int, revisados:int, error:?string}
 */
function imap_revisar(int $diasPrimeraVez = 30, ?array $cfg = null, string $etiquetaBuzon = 'INBOX'): array
{
    $res = ['rebotes' => 0, 'duros' => 0, 'respuestas' => 0, 'revisados' => 0, 'error' => null];

    $c = $cfg ?? imap_cfg();
    if (empty($c['user']) || empty($c['pass'])) {
        $res['error'] = 'La casilla de entrada no está configurada.';
        return $res;
    }

    $err = null;
    $fp  = imap_conectar($err, $c);
    if (!$fp) {
        $res['error'] = $err;
        return $res;
    }

    try {
        $sel = imap_cmd($fp, 'SELECT "INBOX"');
        if (!$sel['ok']) {
            $res['error'] = 'No se pudo abrir INBOX: ' . $sel['texto'];
            return $res;
        }

        $ultimo = imap_ultimo_uid($etiquetaBuzon);
        $busq = $ultimo > 0
            ? 'UID SEARCH UID ' . ($ultimo + 1) . ':*'
            : 'UID SEARCH SINCE ' . date('d-M-Y', strtotime("-$diasPrimeraVez days"));
        $r = imap_cmd($fp, $busq);
        if (!$r['ok']) {
            $res['error'] = 'La búsqueda falló: ' . $r['texto'];
            return $res;
        }

        $uids = [];
        foreach ($r['lineas'] as $l) {
            if (preg_match('/^\* SEARCH(.*)$/i', $l, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $x) {
                    if ($x !== '' && ctype_digit($x)) { $uids[] = (int)$x; }
                }
            }
        }
        // `UID n:*` siempre devuelve al menos el último mensaje aunque no sea
        // nuevo: se descarta lo que ya se procesó.
        $uids = array_values(array_filter($uids, fn($u) => $u > $ultimo));
        sort($uids);
        // Tope por corrida: el cron pasa cada pocos minutos, no hace falta
        // procesar miles de una y arriesgar el tiempo de ejecución.
        $uids   = array_slice($uids, 0, 300);
        $propio = normalizar_email((string)($c['user'] ?? ''));
        $maxUid = $ultimo;

        foreach ($uids as $uid) {
            $maxUid = max($maxUid, $uid);
            $res['revisados']++;

            $h = imap_cmd($fp, "UID FETCH $uid (BODY.PEEK[HEADER])");
            if (!$h['ok']) { continue; }
            $cab    = $h['crudo'];
            $de     = imap_direccion(imap_cabecera($cab, 'From'));
            $asunto = imap_texto(imap_cabecera($cab, 'Subject'));

            if ($de === '' || $de === $propio) { continue; }

            if (es_rebote($de, $asunto)) {
                // Sólo acá hace falta el cuerpo: es donde viene el DSN con la
                // dirección que falló y el código de error.
                $b = imap_cmd($fp, "UID FETCH $uid (BODY.PEEK[TEXT])", 524288);
                $cuerpo = $b['ok'] ? $b['crudo'] : '';
                $duro   = es_rebote_duro($cuerpo);
                foreach (extraer_destinatario_rebote($cuerpo) as $direccion) {
                    if ($direccion === $propio) { continue; }
                    // Sólo cuentan los rebotes de NUESTRAS campañas: los rebotes
                    // personales de la misma casilla no ensucian el CRM.
                    if (!qv('SELECT 1 FROM envios WHERE email = ? LIMIT 1', [$direccion])) { continue; }
                    marcar_rebote($direccion, recortar($asunto, 200), $duro);
                    $res['rebotes']++;
                    if ($duro) { $res['duros']++; }
                }
                continue;
            }

            // Respuesta de un destinatario de campaña.
            if (qv("SELECT 1 FROM envios WHERE email = ? AND estado = 'enviado' LIMIT 1", [$de])) {
                marcar_respuesta($de, $asunto);
                $res['respuestas']++;
            }
        }

        imap_guardar_uid($etiquetaBuzon, $maxUid);
    } catch (\Throwable $e) {
        $res['error'] = $e->getMessage();
    } finally {
        imap_cerrar($fp);
    }

    // Los contadores de las campañas afectadas se recalculan de una sola vez.
    foreach (q("SELECT DISTINCT campana_id FROM envios
                WHERE rebotado_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                   OR respondido_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchAll() as $r2) {
        campana_recontar((int)$r2['campana_id']);
    }
    return $res;
}

/** Prueba la conexión sin procesar nada. */
function imap_probar(?string &$err = null, ?array $cfg = null): bool
{
    $fp = imap_conectar($err, $cfg);
    if (!$fp) { return false; }
    $r = imap_cmd($fp, 'SELECT "INBOX"');
    imap_cerrar($fp);
    if (!$r['ok']) {
        $err = 'Se conectó pero no pudo abrir la bandeja de entrada: ' . $r['texto'];
        return false;
    }
    return true;
}

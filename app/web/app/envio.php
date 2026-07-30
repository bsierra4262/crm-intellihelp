<?php
declare(strict_types=1);

// ============================================================
//  Envío SMTP sin dependencias externas.
//
//  Decisiones heredadas de Pulsemail (la app de escritorio que reemplazamos),
//  y el porqué de cada una:
//
//   · Se manda SIEMPRE texto plano + HTML (multipart/alternative). Un correo
//     sólo-HTML es una señal clásica de spam y hay clientes viejos que lo
//     descartan directamente.
//   · El Message-ID lo generamos nosotros y lo guardamos: es lo único que
//     permite después cruzar un rebote o una respuesta con el envío exacto.
//   · Va la cabecera List-Unsubscribe además del enlace de baja en el cuerpo:
//     Gmail y Outlook la usan para no marcarnos como spam.
//   · La pausa entre correos NO vive acá sino en el despachador (cron):
//     esta función manda uno y vuelve.
// ============================================================

/** Configuración de correo: config.php + lo que se cargue desde Ajustes. */
function smtp_cfg(): array
{
    $c = (array)($GLOBALS['CONFIG']['mail'] ?? []);
    foreach (['user', 'pass', 'from', 'from_name', 'host', 'port', 'seguridad', 'responder_a'] as $k) {
        $v = setting_get('smtp_' . $k, null);
        if ($v !== null && $v !== '') { $c[$k] = $v; }
    }
    return $c + ['host' => 'smtp.gmail.com', 'port' => 587, 'seguridad' => 'tls'];
}

function smtp_configurado(): bool
{
    $c = smtp_cfg();
    return !empty($c['user']) && !empty($c['pass']);
}

/** Genera un Message-ID propio y estable para un envío. */
function nuevo_message_id(string $dominio = 'intellihelp.tech'): string
{
    return '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $dominio . '>';
}

function mime_nombre(string $nombre): string
{
    return preg_match('/[^\x20-\x7e]/', $nombre)
        ? '=?UTF-8?B?' . base64_encode($nombre) . '?='
        : '"' . str_replace('"', '', $nombre) . '"';
}

/**
 * Abre una sesión SMTP autenticada. Devuelve el socket o null.
 *
 * Se mantiene abierta entre correos durante una corrida del cron: abrir y
 * cerrar la conexión en cada mensaje multiplica la latencia y algunos
 * proveedores lo interpretan como comportamiento robótico.
 */
function smtp_abrir(?string &$err = null)
{
    $c    = smtp_cfg();
    $host = (string)$c['host'];
    $port = (int)$c['port'];
    $seg  = strtolower((string)$c['seguridad']);
    if (empty($c['user']) || empty($c['pass'])) {
        $err = 'SMTP sin configurar: faltan usuario y contraseña.';
        return null;
    }

    $ctx    = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $prefijo = ($seg === 'ssl') ? 'ssl://' : '';
    $fp = @stream_socket_client($prefijo . $host . ':' . $port, $en, $es, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $err = "No se pudo conectar a $host:$port ($es).";
        return null;
    }
    stream_set_timeout($fp, 30);

    $leer = static function () use ($fp): string {
        $data = '';
        while (($linea = fgets($fp, 1024)) !== false) {
            $data .= $linea;
            if (isset($linea[3]) && $linea[3] === ' ') { break; }
        }
        return $data;
    };
    $cmd = static fn(string $x) => (static function () use ($fp, $leer, $x) { fwrite($fp, $x . "\r\n"); return $leer(); })();
    $ok  = static fn(string $r, string $code) => str_starts_with(ltrim($r), $code);

    if (!$ok($leer(), '220')) { $err = 'El servidor SMTP no saludó.'; fclose($fp); return null; }
    $cmd('EHLO intellihelp.tech');
    if ($seg === 'tls') {
        if (!$ok($cmd('STARTTLS'), '220')) { $err = 'STARTTLS rechazado.'; fclose($fp); return null; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $err = 'No se pudo negociar TLS.'; fclose($fp); return null;
        }
        $cmd('EHLO intellihelp.tech');
    }
    if (!$ok($cmd('AUTH LOGIN'), '334')) { $err = 'El servidor no aceptó AUTH LOGIN.'; fclose($fp); return null; }
    $cmd(base64_encode((string)$c['user']));
    $r = $cmd(base64_encode((string)$c['pass']));
    if (!$ok($r, '235')) {
        $err = 'La autenticación SMTP falló. Revisá el usuario y la contraseña de aplicación.';
        fclose($fp);
        return null;
    }
    return $fp;
}

function smtp_cerrar($fp): void
{
    if (is_resource($fp)) {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
    }
}

/**
 * Manda un mensaje por una sesión ya abierta.
 *
 * @param array $msg  para|para_nombre|asunto|html|texto|de|de_nombre|responder_a|cc[]|message_id|baja_url
 * @return string 'enviado' | 'rechazado' | 'error'
 */
function smtp_enviar($fp, array $msg, ?string &$err = null): string
{
    if (!is_resource($fp)) { $err = 'La conexión SMTP no está abierta.'; return 'error'; }

    $leer = static function () use ($fp): string {
        $data = '';
        while (($linea = fgets($fp, 1024)) !== false) {
            $data .= $linea;
            if (isset($linea[3]) && $linea[3] === ' ') { break; }
        }
        return $data;
    };
    $cmd = static function (string $x) use ($fp, $leer): string { fwrite($fp, $x . "\r\n"); return $leer(); };
    $ok  = static fn(string $r, string $code) => str_starts_with(ltrim($r), $code);

    $c        = smtp_cfg();
    $de       = (string)($msg['de'] ?? $c['from'] ?? $c['user']);
    $deNombre = (string)($msg['de_nombre'] ?? $c['from_name'] ?? 'INTELLIHELP');
    $para     = normalizar_email((string)($msg['para'] ?? ''));
    if (!email_valido($para)) { $err = 'Destinatario inválido: ' . $para; return 'rechazado'; }

    $cc = array_values(array_filter(array_map('normalizar_email', (array)($msg['cc'] ?? [])), 'email_valido'));

    $r = $cmd('MAIL FROM:<' . $de . '>');
    if (!$ok($r, '250')) { $err = 'MAIL FROM rechazado: ' . trim($r); return 'error'; }

    $r = $cmd('RCPT TO:<' . $para . '>');
    if (!$ok($r, '250') && !$ok($r, '251')) {
        // 5xx en RCPT = la casilla no existe: es un rechazo definitivo, no un
        // error temporal. No tiene sentido reintentar.
        $err = 'Destinatario rechazado: ' . trim($r);
        $cmd('RSET');
        return str_starts_with(ltrim($r), '5') ? 'rechazado' : 'error';
    }
    foreach ($cc as $direccion) {
        $cmd('RCPT TO:<' . $direccion . '>');
    }

    $r = $cmd('DATA');
    if (!$ok($r, '354')) { $err = 'DATA rechazado: ' . trim($r); $cmd('RSET'); return 'error'; }

    $limite    = '=_ith_' . bin2hex(random_bytes(10));
    $messageId = (string)($msg['message_id'] ?? nuevo_message_id());
    $texto     = (string)($msg['texto'] ?? strip_tags((string)($msg['html'] ?? '')));

    $cab  = 'From: ' . mime_nombre($deNombre) . ' <' . $de . ">\r\n";
    $cab .= 'To: ' . (!empty($msg['para_nombre']) ? mime_nombre((string)$msg['para_nombre']) . ' ' : '') . '<' . $para . ">\r\n";
    if ($cc) { $cab .= 'Cc: ' . implode(', ', array_map(fn($x) => '<' . $x . '>', $cc)) . "\r\n"; }
    if (!empty($msg['responder_a'])) { $cab .= 'Reply-To: <' . $msg['responder_a'] . ">\r\n"; }
    $cab .= 'Subject: =?UTF-8?B?' . base64_encode((string)($msg['asunto'] ?? '')) . "?=\r\n";
    $cab .= 'Message-ID: ' . $messageId . "\r\n";
    $cab .= 'Date: ' . date('r') . "\r\n";
    $cab .= "MIME-Version: 1.0\r\n";
    if (!empty($msg['baja_url'])) {
        // Gmail y Outlook usan esta cabecera para ofrecer la baja en un clic;
        // tenerla mejora la reputación del remitente.
        $cab .= 'List-Unsubscribe: <' . $msg['baja_url'] . ">\r\n";
        $cab .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    }
    $cab .= 'Content-Type: multipart/alternative; boundary="' . $limite . "\"\r\n";

    $cuerpo  = "--$limite\r\n";
    $cuerpo .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $cuerpo .= chunk_split(base64_encode($texto)) . "\r\n";
    $cuerpo .= "--$limite\r\n";
    $cuerpo .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $cuerpo .= chunk_split(base64_encode((string)($msg['html'] ?? ''))) . "\r\n";
    $cuerpo .= "--$limite--\r\n";

    // Dot-stuffing: una línea que arranca con "." cerraría el DATA antes de tiempo.
    $cuerpo = preg_replace('/^\./m', '..', $cuerpo);

    $r = $cmd($cab . "\r\n" . $cuerpo . "\r\n.");
    if (!$ok($r, '250')) { $err = 'El servidor rechazó el mensaje: ' . trim($r); return 'error'; }
    return 'enviado';
}

/** Envío suelto (avisos del sistema, confirmaciones de agenda). */
function enviar_correo_simple(string $para, string $asunto, string $html, ?string &$err = null): bool
{
    $fp = smtp_abrir($err);
    if (!$fp) { return false; }
    $res = smtp_enviar($fp, ['para' => $para, 'asunto' => $asunto, 'html' => $html], $err);
    smtp_cerrar($fp);
    return $res === 'enviado';
}

/** Prueba la configuración SMTP sin mandar nada. */
function smtp_probar(?string &$err = null): bool
{
    $fp = smtp_abrir($err);
    if (!$fp) { return false; }
    smtp_cerrar($fp);
    return true;
}

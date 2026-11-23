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
//
//  Quién lo usa: campanas.php (campanas_despachar, con sesión abierta entre
//  correos) y todo el que manda un correo suelto con enviar_correo_simple()
//  (agenda, avisos, pruebas de Ajustes). Es núcleo: no depende de que el
//  módulo de campañas esté encendido.
//
//  No toca la base salvo para leer ajustes y cuentas (setting_get,
//  cuenta_correo_predeterminada). Habla SMTP a mano: AUTH LOGIN únicamente,
//  STARTTLS ('tls') o TLS directo ('ssl'), certificado verificado.
//
//  Trampas:
//   · Las respuestas del servidor se leen línea por línea hasta la que tiene
//     un espacio en la cuarta posición (fin de respuesta multilínea). Si el
//     servidor no contesta en 30 s, fgets devuelve false y la respuesta llega
//     vacía o a medias: eso se interpreta como error, no como cuelgue.
//   · Un 5xx es definitivo en cualquier paso (smtp_clasificar_fallo): en el
//     RCPT es 'rechazado' (la casilla no existe) y en MAIL FROM o en el DATA
//     es 'fallido' (el servidor no acepta ese mensaje o ese remitente; no es
//     culpa del destinatario). La excepción son los topes de envío que
//     algunos proveedores contestan con 5xx (Gmail: 550 5.4.5): ésos se
//     reintentan. Si después de mandar el mensaje entero el servidor no
//     contesta, vuelve 'dudoso': pudo haberlo recibido y reintentar lo
//     duplicaría.
// ============================================================

/**
 * Cuenta con la que sale un correo suelto (avisos, confirmaciones, pruebas).
 *
 * Las casillas se cargan en UN solo lugar: la pantalla «Cuentas de correo». Si
 * hay una marcada como predeterminada, es esa. Un solo lugar evita tener dos
 * configuraciones en paralelo sin saber cuál manda.
 *
 * Los otros dos escalones quedan como red de seguridad para una instalación que
 * todavía no cargó ninguna cuenta: lo de `config.php` (`$CONFIG['mail']`),
 * pisado clave por clave por los ajustes `smtp_*` que no estén vacíos, y por
 * último Gmail en el 587 con STARTTLS.
 *
 * @return array user, pass, from, from_name, host, port, seguridad,
 *               responder_a (las que haya).
 */
function smtp_cfg(): array
{
    if (function_exists('cuenta_correo_predeterminada')) {
        $cuenta = cuenta_correo_predeterminada();
        if ($cuenta) {
            $cfg = cuenta_a_config($cuenta);
            if (!empty($cfg['user']) && !empty($cfg['pass'])) { return $cfg; }
        }
    }

    $c = (array)($GLOBALS['CONFIG']['mail'] ?? []);
    foreach (['user', 'pass', 'from', 'from_name', 'host', 'port', 'seguridad', 'responder_a'] as $k) {
        $v = setting_get('smtp_' . $k, null);
        if ($v !== null && $v !== '') { $c[$k] = $v; }
    }
    return $c + ['host' => 'smtp.gmail.com', 'port' => 587, 'seguridad' => 'tls'];
}

/**
 * Casilla a la que se desvía TODO correo saliente, o null si no hay desvío.
 *
 * Es la barrera de la instalación de pruebas: con `$CONFIG['mail']['desviar_a']`
 * puesto en su config (crm-pruebas.php), ningún correo sale a un contacto real
 * aunque la base de pruebas tenga direcciones reales o alguien la pase a modo
 * producción. Vive en el archivo de config del servidor y no en la base a
 * propósito: copiar o reiniciar la base no la apaga. En producción no se pone.
 * Una dirección inválida se toma como «sin desvío» para no tragarse correos
 * en silencio, y el chequeo de instalación lo avisa.
 */
function correo_desvio(): ?string
{
    $d = normalizar_email((string)($GLOBALS['CONFIG']['mail']['desviar_a'] ?? ''));
    return email_valido($d) ? $d : null;
}

/**
 * Aplica el desvío de pruebas a un mensaje (pura, para poder probarla).
 *
 * El destinatario pasa a ser la casilla de desvío, se sacan las copias y el
 * asunto avisa a quién iba: «[PRUEBAS → cliente@x.com] Asunto». Sin desvío
 * devuelve el mensaje tal cual.
 */
function correo_aplicar_desvio(array $msg, ?string $desvio): array
{
    if ($desvio === null) { return $msg; }
    $original = trim((string)($msg['para'] ?? ''));
    $cc = array_values(array_filter((array)($msg['cc'] ?? [])));
    $iba = $original . ($cc ? ' + cc ' . implode(', ', $cc) : '');
    $msg['para']        = $desvio;
    $msg['para_nombre'] = '';
    $msg['cc']          = [];
    $msg['asunto']      = '[PRUEBAS → ' . $iba . '] ' . (string)($msg['asunto'] ?? '');
    $msg['desviado_de'] = $original;
    return $msg;
}

/**
 * ¿Hay usuario y contraseña para salir? Sólo mira que estén cargados, no que
 * funcionen (para eso está smtp_probar). Mira la cuenta por defecto, no la
 * que haya elegido cada campaña.
 */
function smtp_configurado(): bool
{
    $c = smtp_cfg();
    return !empty($c['user']) && !empty($c['pass']);
}

/**
 * Genera un Message-ID propio y estable para un envío.
 *
 * «Estable» en el sentido de que se genera una vez y se guarda en
 * `envios.message_id`: cada llamada da uno nuevo. El dominio va fijo, no
 * sale del remitente.
 */
function nuevo_message_id(string $dominio = 'intellihelp.tech'): string
{
    return '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $dominio . '>';
}

/**
 * Asunto codificado para la cabecera Subject (RFC 2047).
 *
 * Va en base64 y partido en «palabras codificadas» de 75 caracteres como
 * máximo, cortando siempre entre caracteres UTF-8 completos (una letra con
 * tilde no puede quedar partida entre dos palabras). Las palabras se separan
 * con un salto y un espacio (plegado de cabecera), que el cliente descarta
 * al mostrar el asunto.
 */
function mime_asunto(string $asunto): string
{
    if ($asunto === '') { return '=?UTF-8?B??='; }
    // 75 - strlen('=?UTF-8?B?') - strlen('?=') = 63 caracteres de base64:
    // entran 45 bytes de texto por palabra (múltiplo de 3, sin relleno).
    $palabras = [];
    $actual   = '';
    $letras   = preg_split('//u', $asunto, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($asunto);
    foreach ($letras as $car) {
        if ($actual !== '' && strlen($actual . $car) > 45) {
            $palabras[] = $actual;
            $actual = '';
        }
        $actual .= $car;
    }
    if ($actual !== '') { $palabras[] = $actual; }
    return implode("\r\n ", array_map(fn($p) => '=?UTF-8?B?' . base64_encode($p) . '?=', $palabras));
}

/**
 * Qué hacer con una respuesta de error del servidor SMTP.
 *
 * Es pura (no habla con nadie) para poder probarla sola.
 *
 * @param string $fase      'rcpt' (el destinatario) o cualquier otra
 *                          ('mail', 'data', 'fin').
 * @param string $respuesta lo que contestó el servidor, tal cual.
 * @return string 'rechazado' (5xx en el RCPT: la casilla no existe),
 *                'fallido' (5xx en otro paso: el servidor no acepta el
 *                mensaje o el remitente; no se reintenta, pero tampoco es
 *                culpa del destinatario) o 'error' (temporal: 4xx, sin
 *                respuesta o un tope de envío contestado con 5xx).
 */
function smtp_clasificar_fallo(string $fase, string $respuesta): string
{
    $r = ltrim($respuesta);
    if (!str_starts_with($r, '5')) { return 'error'; }
    // Algunos proveedores contestan con 5xx cuando se llegó al tope de envíos
    // del día (Gmail: «550 5.4.5 Daily user sending limit exceeded»). No es
    // un rechazo del mensaje: mañana sale. Se trata como temporal.
    if (preg_match('/\b5\.4\.5\b|quota|limit exceeded|rate limit|too many/i', $r)) {
        return 'error';
    }
    return $fase === 'rcpt' ? 'rechazado' : 'fallido';
}

/**
 * Nombre visible para una cabecera From/To.
 *
 * Si tiene algo fuera del ASCII imprimible (acentos, pero también saltos de
 * línea) va codificado en base64 (RFC 2047), lo que de paso impide inyectar
 * cabeceras con un nombre que traiga CR/LF. Si es ASCII puro va entre
 * comillas, quitándole las comillas que tuviera.
 */
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
 *
 * @param string|null $err se llena con el motivo si falla.
 * @param array|null  $cfg configuración de una cuenta concreta
 *                         (cuenta_a_config); null = smtp_cfg().
 * @return resource|null el socket listo para MAIL FROM, o null.
 */
function smtp_abrir(?string &$err = null, ?array $cfg = null)
{
    // `$cfg` permite abrir la sesión con UNA cuenta concreta. Es lo que hace
    // posible correr varias campañas en paralelo, cada una con su casilla.
    $c    = $cfg ?? smtp_cfg();
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

    // Lee una respuesta completa: las multilínea vienen como «250-…» y la
    // última como «250 …» (espacio en la cuarta posición).
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
    // El EHLO se revisa: si el servidor no lo acepta, decirlo acá es más
    // claro que el error que daría el paso siguiente.
    $r = $cmd('EHLO intellihelp.tech');
    if (!$ok($r, '250')) { $err = 'El servidor no aceptó el saludo EHLO: ' . trim($r); fclose($fp); return null; }
    if ($seg === 'tls') {
        if (!$ok($cmd('STARTTLS'), '220')) { $err = 'STARTTLS rechazado.'; fclose($fp); return null; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $err = 'No se pudo negociar TLS.'; fclose($fp); return null;
        }
        $r = $cmd('EHLO intellihelp.tech');
        if (!$ok($r, '250')) { $err = 'El servidor no aceptó el saludo EHLO tras el TLS: ' . trim($r); fclose($fp); return null; }
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

/** Cierra la sesión con QUIT, sin esperar respuesta. Acepta cualquier cosa. */
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
 *                    y opcionales in_reply_to, referencias, ics, ics_metodo.
 * @param string|null $err se llena con el motivo si no sale.
 * @param array|null  $cfg la cuenta de la sesión, para el remitente por
 *                         defecto; null = smtp_cfg().
 * @param callable|null $alEntregar se llama justo antes de mandar el mensaje
 *                         (el servidor ya aceptó el DATA). El despachador la
 *                         usa para anotar que ese envío llegó hasta acá: si el
 *                         proceso muere después, el correo pudo haber salido y
 *                         no se reintenta solo.
 * @return string 'enviado' | 'rechazado' (5xx en el RCPT del destinatario o
 *                dirección inválida: no reintentar) | 'fallido' (5xx en
 *                MAIL FROM o en el DATA: no reintentar, pero no es culpa del
 *                destinatario) | 'dudoso' (se mandó el mensaje entero y no
 *                llegó respuesta: pudo haber salido, reintentar duplicaría)
 *                | 'error' (temporal: quien llama decide si reintenta).
 *
 * Después de cualquier cosa que no sea 'enviado' o 'rechazado' la sesión
 * puede quedar en un estado intermedio (por ejemplo, a mitad de un DATA):
 * quien llama la cierra y abre otra.
 * Reply-To, In-Reply-To y References se copian tal cual a las cabeceras:
 * quien llama tiene que garantizar que no traigan saltos de línea.
 */
function smtp_enviar($fp, array $msg, ?string &$err = null, ?array $cfg = null, ?callable $alEntregar = null): string
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

    // Instalación de pruebas: todo va a la casilla de desvío (correo_desvio).
    // Es el único punto por donde sale un correo, así que alcanza con hacerlo acá.
    $msg      = correo_aplicar_desvio($msg, correo_desvio());
    $c        = $cfg ?? smtp_cfg();
    $de       =(string)($msg['de'] ?? $c['from'] ?? $c['user']);
    $deNombre = (string)($msg['de_nombre'] ?? $c['from_name'] ?? 'INTELLIHELP');
    $para     = normalizar_email((string)($msg['para'] ?? ''));
    if (!email_valido($para)) { $err = 'Destinatario inválido: ' . $para; return 'rechazado'; }

    $cc = array_values(array_filter(array_map('normalizar_email', (array)($msg['cc'] ?? [])), 'email_valido'));

    $r = $cmd('MAIL FROM:<' . $de . '>');
    if (!$ok($r, '250')) { $err = 'MAIL FROM rechazado: ' . trim($r); $cmd('RSET'); return smtp_clasificar_fallo('mail', $r); }

    $r = $cmd('RCPT TO:<' . $para . '>');
    if (!$ok($r, '250') && !$ok($r, '251')) {
        // 5xx en RCPT = la casilla no existe: es un rechazo definitivo, no un
        // error temporal. No tiene sentido reintentar.
        $err = 'Destinatario rechazado: ' . trim($r);
        $cmd('RSET');
        return smtp_clasificar_fallo('rcpt', $r);
    }
    // Las copias se agregan sin mirar la respuesta: una copia rechazada no
    // frena el envío al destinatario.
    foreach ($cc as $direccion) {
        $cmd('RCPT TO:<' . $direccion . '>');
    }

    $r = $cmd('DATA');
    if (!$ok($r, '354')) { $err = 'DATA rechazado: ' . trim($r); $cmd('RSET'); return smtp_clasificar_fallo('data', $r); }

    $limite    = '=_ith_' . bin2hex(random_bytes(10));
    $messageId = (string)($msg['message_id'] ?? nuevo_message_id());
    $texto     = (string)($msg['texto'] ?? strip_tags((string)($msg['html'] ?? '')));

    $cab  = 'From: ' . mime_nombre($deNombre) . ' <' . $de . ">\r\n";
    $cab .= 'To: ' . (!empty($msg['para_nombre']) ? mime_nombre((string)$msg['para_nombre']) . ' ' : '') . '<' . $para . ">\r\n";
    if ($cc) { $cab .= 'Cc: ' . implode(', ', array_map(fn($x) => '<' . $x . '>', $cc)) . "\r\n"; }
    if (!empty($msg['responder_a'])) { $cab .= 'Reply-To: <' . $msg['responder_a'] . ">\r\n"; }
    // El asunto va siempre en base64, partido en palabras de 75 caracteres
    // como pide la norma (mime_asunto).
    $cab .= 'Subject: ' . mime_asunto((string)($msg['asunto'] ?? '')) . "\r\n";
    $cab .= 'Message-ID: ' . $messageId . "\r\n";
    if (!empty($msg['desviado_de']) && !preg_match('/[\r\n]/', (string)$msg['desviado_de'])) {
        $cab .= 'X-CRM-Desviado-De: ' . $msg['desviado_de'] . "\r\n";
    }
    // Sin estas dos, una respuesta escrita desde el CRM le llega al destinatario
    // como un correo suelto en vez de aparecer pegada a la conversación que él
    // mismo empezó. Es la diferencia entre contestar y escribir de nuevo.
    if (!empty($msg['in_reply_to'])) { $cab .= 'In-Reply-To: ' . $msg['in_reply_to'] . "\r\n"; }
    if (!empty($msg['referencias'])) { $cab .= 'References: ' . $msg['referencias'] . "\r\n"; }
    $cab .= 'Date: ' . date('r') . "\r\n";
    $cab .= "MIME-Version: 1.0\r\n";
    if (!empty($msg['baja_url'])) {
        // Gmail y Outlook usan esta cabecera para ofrecer la baja en un clic;
        // tenerla mejora la reputación del remitente.
        // El «One-Click» (RFC 8058) obliga a que esa URL dé de baja con un
        // POST sin pedir nada más: lo resuelve pages/baja.php.
        $cab .= 'List-Unsubscribe: <' . $msg['baja_url'] . ">\r\n";
        $cab .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    }
    // Las dos versiones del mensaje: texto plano para quien no ve HTML, y HTML
    // para el resto. El cliente de correo elige la última que entiende.
    $alt  = "--$limite\r\n";
    $alt .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $alt .= chunk_split(base64_encode($texto)) . "\r\n";
    $alt .= "--$limite\r\n";
    $alt .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $alt .= chunk_split(base64_encode((string)($msg['html'] ?? ''))) . "\r\n";

    $ics = (string)($msg['ics'] ?? '');
    if ($ics !== '') {
        // Invitación de calendario. Esta parte es la que hace que Gmail y
        // Outlook muestren los botones «Sí / No / Quizás» arriba del mensaje:
        // sin ella, el correo avisa de la reunión pero no se puede aceptar.
        // Tiene que ir DENTRO del multipart/alternative y con el mismo method
        // que declara el archivo, o el cliente lo ignora.
        $metodo = (string)($msg['ics_metodo'] ?? 'REQUEST');
        $alt .= "--$limite\r\n";
        $alt .= 'Content-Type: text/calendar; charset=UTF-8; method=' . $metodo . "\r\n";
        $alt .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $alt .= chunk_split(base64_encode($ics)) . "\r\n";
    }
    $alt .= "--$limite--\r\n";

    if ($ics === '') {
        $cab   .= 'Content-Type: multipart/alternative; boundary="' . $limite . "\"\r\n";
        $cuerpo = $alt;
    } else {
        // Además de la parte de calendario va el archivo adjunto: algunos
        // clientes (Apple Mail entre ellos) sólo lo reconocen así.
        $ext    = '=_ith_m_' . bin2hex(random_bytes(10));
        $cab   .= 'Content-Type: multipart/mixed; boundary="' . $ext . "\"\r\n";
        $cuerpo  = "--$ext\r\n";
        $cuerpo .= 'Content-Type: multipart/alternative; boundary="' . $limite . "\"\r\n\r\n";
        $cuerpo .= $alt;
        $cuerpo .= "--$ext\r\n";
        $cuerpo .= "Content-Type: application/ics; name=\"reunion.ics\"\r\n";
        $cuerpo .= "Content-Disposition: attachment; filename=\"reunion.ics\"\r\n";
        $cuerpo .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $cuerpo .= chunk_split(base64_encode($ics)) . "\r\n";
        $cuerpo .= "--$ext--\r\n";
    }

    // Dot-stuffing: una línea que arranca con "." cerraría el DATA antes de tiempo.
    $cuerpo = preg_replace('/^\./m', '..', $cuerpo);

    // Desde acá el mensaje puede llegar aunque después se pierda la respuesta:
    // quien llama lo anota antes de mandarlo. Si anotarlo falla, el envío
    // sigue igual.
    if ($alEntregar) {
        try { $alEntregar(); } catch (\Throwable $e) { }
    }
    // Si se pierde esta respuesta (tiempo de espera), el servidor pudo haber
    // aceptado el mensaje igual: por eso una respuesta vacía vuelve 'dudoso' y
    // no 'error', para que nadie lo reintente solo y lo duplique.
    $r = $cmd($cab . "\r\n" . $cuerpo . "\r\n.");
    if (trim($r) === '') {
        $err = 'El servidor no confirmó la recepción del mensaje (no hubo respuesta): puede haber llegado.';
        return 'dudoso';
    }
    if (!$ok($r, '250')) { $err = 'El servidor rechazó el mensaje: ' . trim($r); return smtp_clasificar_fallo('fin', $r); }
    return 'enviado';
}

/**
 * Envío suelto (avisos del sistema, confirmaciones de agenda).
 *
 * En $extra viajan las partes opcionales: `texto` con la versión en texto
 * plano, e `ics` / `ics_metodo` con la invitación de calendario.
 *
 * Abre y cierra una sesión propia con la cuenta por defecto (smtp_cfg).
 * No reintenta: si falla devuelve false con el motivo en $err.
 */
function enviar_correo_simple(string $para, string $asunto, string $html, ?string &$err = null, array $extra = []): bool
{
    $fp = smtp_abrir($err);
    if (!$fp) { return false; }
    $res = smtp_enviar($fp, ['para' => $para, 'asunto' => $asunto, 'html' => $html] + $extra, $err);
    smtp_cerrar($fp);
    return $res === 'enviado';
}

/**
 * Prueba la configuración SMTP sin mandar nada: conecta, negocia TLS y se
 * autentica con la cuenta por defecto.
 */
function smtp_probar(?string &$err = null): bool
{
    $fp = smtp_abrir($err);
    if (!$fp) { return false; }
    smtp_cerrar($fp);
    return true;
}

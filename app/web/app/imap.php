<?php
declare(strict_types=1);

// ============================================================
//  Lectura de la casilla (IMAP): rebotes y respuestas.
//
//  ¿Por qué un cliente IMAP propio y no la extensión `imap` de PHP?
//
//  Porque la extensión usa la librería c-client, que no envía SNI al
//  negociar TLS. Gmail responde a eso con un certificado de descarte y PHP
//  aborta con el error:
//
//      Certificate failure for imap.gmail.com: self-signed certificate:
//      /OU=No SNI provided - please fix your client./CN=invalid2.invalid
//
//  La salida fácil es `/novalidate-cert`, pero eso apaga la verificación del
//  certificado: deja la conexión abierta a un intermediario. La salida correcta
//  es hablar IMAP sobre un socket propio, que sí manda SNI y sí valida
//  el certificado. Es el mismo camino que ya tomamos con SMTP.
//
//  La lógica de detección (qué es un rebote, cuál es definitivo, de quién es la
//  dirección que falló) es la de Pulsemail, que ya está probada en producción.
//
//  Módulo: Campañas (lo carga bootstrap; las pantallas que lo usan son de
//  Campañas y Ajustes). Quién lo llama:
//    · app/cron.php, cada 5 minutos, vía imap_quizas_revisar();
//    · campanas_despachar() en medio de una corrida, también vía
//      imap_quizas_revisar(60);
//    · Ajustes («Probar» y «Revisar ahora») y cuenta_probar().
//
//  Tablas: lee `envios` y `cuentas_correo` (por cuentas_correo.php); escribe
//  `imap_estado` (la marca de UID por casilla) y, por mensajes.php y
//  campanas.php, `mensajes`, `envios`, `contactos`, `posibles_clientes` y
//  `desuscripciones`. Depende de: db.php (q, qv, db_reanudar), helpers.php
//  (normalizar_email, recortar, setting_get/set, log_app), envio.php
//  (smtp_cfg), cuentas_correo.php, mensajes.php y campanas.php.
//
//  Decisiones y trampas:
//    · Se usa BODY.PEEK y nunca BODY: leer no marca los correos como leídos,
//      así quien mira la casilla en Gmail no pierde nada.
//    · Sólo se lee INBOX: lo que Gmail mande a Spam o a otra etiqueta no se ve.
//    · La respuesta cruda del servidor (`crudo`) trae, además del mensaje, la
//      línea `* n FETCH (... {bytes}` de adelante y la de cierre con la
//      etiqueta del comando atrás: no es el correo puro.
// ============================================================

/** Fragmentos del remitente que delatan un aviso de rebote (MAILER-DAEMON y parientes). */
const REMITENTES_REBOTE = ['mailer-daemon', 'postmaster', 'mail-daemon'];
/** Fragmentos de asunto de los avisos de rebote, en inglés y en castellano (en minúsculas). */
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

/**
 * Casilla de entrada por defecto (rebotes y respuestas).
 *
 * Igual que con la de salida: manda la cuenta marcada como predeterminada en
 * «Cuentas de correo». Para revisar TODAS las casillas —que es lo que hace el
 * cron— está imap_revisar_todas().
 *
 * Orden de prioridad:
 *   1. la cuenta predeterminada de `cuentas_correo`, si tiene usuario y
 *      contraseña (descifrada);
 *   2. si no, lo que haya en $CONFIG['imap'], pisado por los ajustes
 *      `imap_host`, `imap_port`, `imap_user`, `imap_pass` (esquema anterior a
 *      las cuentas múltiples; esos ajustes se guardan en texto plano);
 *   3. lo que falte se completa con Gmail y con el usuario/contraseña de la
 *      salida (smtp_cfg()).
 *
 * @return array{host?:string, port?:int|string, user?:string, pass?:string, cuenta_id?:?int}
 */
function imap_cfg(): array
{
    if (function_exists('cuenta_correo_predeterminada')) {
        $cuenta = cuenta_correo_predeterminada();
        if ($cuenta) {
            $cfg = cuenta_a_imap($cuenta);
            if (!empty($cfg['user']) && !empty($cfg['pass'])) { return $cfg; }
        }
    }

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

/**
 * ¿Hay con qué entrar a la casilla por defecto? Sólo mira que haya usuario y
 * contraseña; no se conecta. Lo usa Ajustes para mostrar el estado.
 */
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
 *
 * Sólo habla IMAPS (TLS desde el primer byte, puerto 993): no hay soporte de
 * STARTTLS en el 143. Tiempos de espera: 20 s para conectar y 30 s por cada
 * lectura del socket (stream_set_timeout).
 *
 * @param string|null $err  por referencia: el motivo legible si falla
 * @param array|null  $cfg  host/port/user/pass; null = imap_cfg()
 * @return resource|null el socket ya autenticado, o null
 */
function imap_conectar(?string &$err = null, ?array $cfg = null)
{
    $c    = $cfg ?? imap_cfg();
    $host = (string)(($c['host'] ?? null) ?: 'imap.gmail.com');
    $port = (int)(($c['port'] ?? null) ?: 993);

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
    // se aplica bien (con `ssl://` algunos PHP lo arman antes de tiempo).
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

/**
 * Cierra la sesión (LOGOUT) y el socket. Silencia los errores: se llama desde
 * bloques `finally`, donde la conexión puede estar ya caída.
 *
 * @param resource|mixed $fp
 */
function imap_cerrar($fp): void
{
    if (is_resource($fp)) {
        @imap_cmd($fp, 'LOGOUT');
        @fclose($fp);
    }
}

/**
 * Entrecomilla un valor para el protocolo IMAP (cadena entre comillas, con
 * `\` y `"` escapados). No sirve para valores con saltos de línea o bytes
 * de control: esos irían como literal `{n}`, que acá no se usa.
 */
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
 * Se lee hasta la línea que empieza con la etiqueta del comando (`a0001 OK…`,
 * `NO` o `BAD`). Si el socket se corta o vence el tiempo de espera, vuelve con
 * ok=false y lo que alcanzó a leer.
 *
 * Trampas:
 *   · `$limiteBytes` sólo se controla en las líneas sueltas: un literal se lee
 *     siempre entero, aunque supere el tope (un correo con un adjunto grande
 *     se carga completo en memoria).
 *   · Si se corta por el tope, lo que quedó sin leer sigue en el socket y se
 *     mezcla con la respuesta del comando siguiente.
 *   · `crudo` incluye todas las líneas leídas, también la de cierre con la
 *     etiqueta: no es sólo el contenido pedido.
 *
 * @param resource $fp          socket de imap_conectar()
 * @param string   $comando     el comando sin etiqueta ni CRLF
 * @param int      $limiteBytes tope de lectura (8 MB por defecto)
 * @return array{ok:bool, texto:string, lineas:array<int,string>, crudo:string}
 */
function imap_cmd($fp, string $comando, int $limiteBytes = 8388608): array
{
    // Contador de etiquetas compartido por todo el proceso: sólo tiene que ser
    // distinto en cada comando de una misma conexión.
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

/**
 * Decodifica un encabezado MIME (=?UTF-8?B?...?=) a texto plano.
 *
 * Soporta las dos codificaciones de RFC 2047: B (base64) y Q
 * (quoted-printable con `_` como espacio), y convierte a UTF-8 desde el juego
 * de caracteres declarado.
 *
 * Trampas:
 *   · La limpieza del final (quitar el espacio entre dos palabras codificadas
 *     seguidas) corre DESPUÉS de decodificar, cuando ya no quedan marcas
 *     `?= =?` en el texto: no hace nada, y una palabra partida en dos trozos
 *     codificados queda con un espacio en el medio.
 *   · En PHP 8, mb_convert_encoding() lanza ValueError ante un juego de
 *     caracteres que no conoce; la `@` no lo ataja. La excepción sube hasta
 *     imap_revisar().
 */
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

/**
 * Saca un encabezado del bloque de cabeceras, con las continuaciones
 * plegadas unidas en una sola línea. Devuelve el valor crudo, sin decodificar
 * (para eso está imap_texto()), o '' si no está. Si el encabezado aparece más
 * de una vez, devuelve el primero.
 */
function imap_cabecera(string $cabeceras, string $nombre): string
{
    // Las cabeceras plegadas continúan en la línea siguiente con espacio o tab.
    if (preg_match('/^' . preg_quote($nombre, '/') . ':\s*(.*(?:\r?\n[ \t].*)*)/mi', $cabeceras, $m)) {
        return trim(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]) ?? $m[1]);
    }
    return '';
}

/**
 * Extrae el nombre de un encabezado tipo `Nombre <x@y.com>`, ya decodificado.
 * Devuelve '' si el encabezado trae sólo la dirección.
 */
function imap_nombre(string $cabecera): string
{
    $c = trim(imap_texto($cabecera));
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<[^>]+>/', $c, $m)) { return trim($m[1]); }
    return '';
}

// ------------------------------------------------------------
//  Leer el cuerpo de un correo
//
//  Un correo real no es texto: viene partido en pedazos, cada pedazo con su
//  codificación y su juego de caracteres. Guardando el crudo, la respuesta de
//  un cliente se veía como «Hola, c=C3=B3mo est=C3=A1s» o directamente como un
//  bloque de base64. Para poder leerla y contestarla desde el CRM hay que
//  decodificarla.
// ------------------------------------------------------------

/**
 * Deshace la codificación de transporte de una parte (base64 o
 * quoted-printable; 7bit, 8bit y binary pasan tal cual).
 *
 * El base64 se decodifica en modo no estricto: los caracteres que sobran no
 * hacen fallar, pero se decodifican como basura. Si al cuerpo le quedó
 * pegado algo que no es del mensaje (por ejemplo, la línea de cierre de la
 * respuesta IMAP), aparecen bytes de más al final.
 */
function mime_decodificar(string $cuerpo, string $codificacion): string
{
    return match (strtolower(trim($codificacion))) {
        'base64'           => (string) base64_decode(preg_replace('/\s+/', '', $cuerpo) ?? '', false),
        'quoted-printable' => quoted_printable_decode($cuerpo),
        default            => $cuerpo,
    };
}

/**
 * Pasa un texto a UTF-8 desde el juego que declare la parte.
 *
 * Si la parte dice UTF-8 (o nada) pero el texto no es UTF-8 válido, se lo
 * reinterpreta entero como ISO-8859-1: alcanza un solo byte inválido para que
 * todos los acentos se vean mal. Igual que en imap_texto(), un juego
 * desconocido hace que mb_convert_encoding() lance ValueError en PHP 8.
 */
function mime_a_utf8(string $texto, string $juego): string
{
    $juego = strtoupper(trim($juego)) ?: 'UTF-8';
    if ($juego === 'UTF-8' || $juego === 'US-ASCII' || $juego === '') {
        // Hay quien declara UTF-8 y manda otra cosa: si no es válido se
        // reinterpreta, porque un texto roto es peor que uno aproximado.
        return mb_check_encoding($texto, 'UTF-8') ? $texto : (string) mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
    }
    $conv = @mb_convert_encoding($texto, 'UTF-8', $juego);
    return $conv !== false ? $conv : $texto;
}

/**
 * Separa un correo crudo en su texto y su HTML.
 *
 * Recorre las partes (incluso anidadas: un correo con adjunto lleva adentro
 * otro multipart con el texto y el HTML) y se queda con la primera de cada
 * tipo. Los adjuntos se saltean: acá interesa lo que se lee.
 *
 * El HTML que devuelve es el del remitente, sin sanear: quien lo muestre
 * tiene que pasarlo por mensaje_html_seguro() y aislarlo (la vista de la
 * conversación lo pone en un iframe con sandbox). Los adjuntos no se guardan
 * en ningún lado: se pierden.
 *
 * Trampa: el Content-Type se pasa a minúsculas ANTES de sacar el boundary, y
 * el boundary distingue mayúsculas. Con un separador en mayúsculas (Outlook,
 * Apple Mail, Yahoo) el explode no encuentra las partes y el correo queda con
 * texto y HTML vacíos.
 *
 * @param string $crudo        cabeceras + cuerpo de la parte (o del correo entero)
 * @param int    $profundidad  nivel de anidamiento; corta a partir de 7
 * @return array{texto:string, html:string}
 */
function mime_partes(string $crudo, int $profundidad = 0): array
{
    $out = ['texto' => '', 'html' => ''];
    if ($profundidad > 6) { return $out; }          // correo raro o malicioso

    // Las cabeceras terminan en la primera línea en blanco; lo que sigue es el cuerpo.
    $corte = preg_split("/\r?\n\r?\n/", $crudo, 2);
    $cab   = $corte[0] ?? '';
    $cuerpo = $corte[1] ?? '';
    // Sin Content-Type, el estándar dice text/plain en US-ASCII.
    $tipo  = strtolower(imap_cabecera($cab, 'Content-Type')) ?: 'text/plain';
    $codif = imap_cabecera($cab, 'Content-Transfer-Encoding');

    if (str_starts_with($tipo, 'multipart/') && preg_match('/boundary="?([^";\s]+)"?/i', $tipo, $m)) {
        $sep    = '--' . $m[1];
        $trozos = explode($sep, $cuerpo);
        array_shift($trozos);                        // el preámbulo no es una parte
        foreach ($trozos as $t) {
            if (str_starts_with(ltrim($t), '--')) { break; }   // cierre del multipart
            $p = mime_partes(ltrim($t, "\r\n"), $profundidad + 1);
            if ($out['texto'] === '' && $p['texto'] !== '') { $out['texto'] = $p['texto']; }
            if ($out['html']  === '' && $p['html']  !== '') { $out['html']  = $p['html']; }
        }
        return $out;
    }

    // Un adjunto no es contenido para leer.
    if (stripos(imap_cabecera($cab, 'Content-Disposition'), 'attachment') === 0) { return $out; }

    $juego = preg_match('/charset="?([^";\s]+)"?/i', $tipo, $m2) ? $m2[1] : 'UTF-8';
    $texto = mime_a_utf8(mime_decodificar($cuerpo, $codif), $juego);

    if (str_starts_with($tipo, 'text/html'))       { $out['html']  = $texto; }
    elseif (str_starts_with($tipo, 'text/plain'))  { $out['texto'] = $texto; }
    return $out;
}

/**
 * Corta la parte citada de una respuesta.
 *
 * Cuando alguien contesta, el cliente de correo le pega abajo todo el mensaje
 * original. Mostrarlo entero hace que la respuesta de dos líneas quede
 * enterrada bajo la campaña completa. Se corta en las marcas típicas y se
 * guarda igual el resto, que se puede desplegar.
 *
 * Es la versión para texto plano; la de HTML es mensaje_partir_cita_html()
 * (mensajes.php). La usa la vista de la conversación al mostrar, no al
 * guardar: en la base queda siempre el cuerpo entero.
 *
 * @return array{limpio:string, citado:string} lo escrito y la cita ('' si no hay)
 */
function mensaje_partir_cita(string $texto): array
{
    $lineas = preg_split("/\r?\n/", $texto) ?: [];
    $corte  = null;

    // Se trabaja por líneas y no con una expresión sobre el texto entero: la
    // línea de atribución de Gmail («El dom, 2 de ago…, ITHOS <…> escribió:»)
    // viene partida en dos, así que buscándola de una sola pieza no aparece
    // nunca y una respuesta de dos palabras queda enterrada bajo la campaña.
    foreach ($lineas as $i => $l) {
        $t = trim($l);

        // Separadores explícitos: Outlook, Thunderbird, correos reenviados.
        if (preg_match('/^-{2,}\s*(Mensaje original|Original Message|Forwarded message)/iu', $t)
            || preg_match('/^_{10,}$/', $t)) {
            $corte = $i;
            break;
        }

        // Primera línea citada: la cita arranca acá, salvo que justo arriba
        // esté la atribución, que también es parte de la cita.
        if (str_starts_with($t, '>')) {
            $corte = $i;
            // La atribución puede ocupar varias líneas: Gmail la parte donde le
            // queda. Se camina hacia atrás juntándolas hasta encontrar la que
            // la empieza («El …», «On …»), o hasta toparse con una línea en
            // blanco, que ya es el final de lo que escribió la persona.
            $bloque = '';
            for ($j = $i - 1; $j >= max(0, $i - 5); $j--) {
                $prev = trim($lineas[$j]);
                // Las líneas en blanco se saltean: Gmail deja una entre la
                // atribución y la cita, y cortando ahí la atribución quedaría
                // colgada arriba, como si fuera parte de lo que escribieron.
                if ($prev === '') { continue; }
                $bloque = $prev . ' ' . $bloque;
                if (preg_match('/^(El|On|De|From)\b/u', $prev)) {
                    // Sólo es atribución si termina anunciando la cita.
                    if (preg_match('/(escribi[óo]|wrote|sent):?\s*$/iu', trim($bloque))) { $corte = $j; }
                    break;
                }
            }
            break;
        }

        // Atribución suelta, sin «>» detrás (pasa cuando el cliente cita en HTML).
        if (preg_match('/^(El|On)\b.*(escribi[óo]|wrote):?\s*$/iu', $t)) { $corte = $i; break; }
    }

    if ($corte === null) { return ['limpio' => trim($texto), 'citado' => '']; }

    $limpio = rtrim(implode("\n", array_slice($lineas, 0, $corte)));
    $citado = trim(implode("\n", array_slice($lineas, $corte)));
    // Si arriba del corte no quedó nada legible, es que el mensaje ES la cita:
    // se muestra entero antes que dejar la pantalla en blanco.
    if (trim($limpio) === '') { return ['limpio' => trim($texto), 'citado' => '']; }
    return ['limpio' => $limpio, 'citado' => $citado];
}

/**
 * Extrae la dirección de un encabezado tipo `Nombre <x@y.com>`, normalizada
 * (minúsculas). Si no hay `<…>`, toma la primera dirección que aparezca.
 * Devuelve '' si no encuentra ninguna.
 */
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

/**
 * El último UID procesado de un buzón (0 si nunca se leyó).
 *
 * @param string $buzon clave en imap_estado: 'INBOX:<usuario>' por cuenta, o
 *                      'INBOX' para la casilla de la configuración general
 */
function imap_ultimo_uid(string $buzon): int
{
    return (int) (qv('SELECT ultimo_uid FROM imap_estado WHERE buzon = ?', [$buzon]) ?: 0);
}

/**
 * Guarda hasta dónde se leyó un buzón y la hora de la corrida.
 *
 * El GREATEST hace que la marca nunca retroceda, aunque dos corridas
 * terminen en otro orden. Por lo mismo, para releer una casilla hay que
 * borrar o bajar la fila a mano.
 */
function imap_guardar_uid(string $buzon, int $uid): void
{
    q('INSERT INTO imap_estado (buzon, ultimo_uid, ultima_corrida) VALUES (?, ?, NOW())
       ON DUPLICATE KEY UPDATE ultimo_uid = GREATEST(ultimo_uid, VALUES(ultimo_uid)), ultima_corrida = NOW()',
      [$buzon, $uid]);
}

// ------------------------------------------------------------
//  Clasificación de rebotes (lógica de Pulsemail)
// ------------------------------------------------------------

/**
 * ¿El correo es un aviso de rebote? Se decide sólo por el remitente
 * (MAILER-DAEMON, postmaster…) o por el asunto; no se mira el cuerpo ni el
 * Content-Type `multipart/report`.
 *
 * @param string $de     dirección del remitente
 * @param string $asunto asunto ya decodificado
 */
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
 *
 * Orden de decisión:
 *   1. el `Status: X.Y.Z` del DSN estándar: 5 es definitivo, 4 pasajero;
 *   2. un código SMTP 4xx sin ningún 50x/55x en el texto: pasajero;
 *   3. frases típicas: primero las pasajeras (ganan si aparecen las dos),
 *      después las definitivas;
 *   4. si nada coincide, pasajero.
 *
 * @param string $cuerpo cuerpo crudo del aviso (BODY[TEXT])
 */
function es_rebote_duro(string $cuerpo): bool
{
    if (preg_match('/Status:\s*([45])\.\d+\.\d+/i', $cuerpo, $m)) {
        return $m[1] === '5';
    }
    // Ojo: busca cualquier número de tres cifras que empiece con 4 seguido de
    // espacio o guion, en todo el texto; un «450 » suelto alcanza.
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

/**
 * Saca del cuerpo del rebote la dirección que falló.
 *
 * Primero busca los campos del DSN estándar (Final-Recipient y
 * Original-Recipient). Si no hay, toma TODAS las direcciones que aparezcan en
 * el texto, incluidas las de la copia del mensaje original que suelen traer
 * los avisos (remitente, Reply-To, direcciones del pie). El llamador filtra
 * después por las que figuran en `envios`.
 *
 * @return array<int,string> direcciones normalizadas, sin repetir
 */
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
 * Lee la casilla si ya pasó el tiempo mínimo desde la última vez.
 *
 * Con una campaña andando, los rebotes llegan a los segundos de cada envío y
 * las respuestas empiezan a entrar enseguida: leer sólo al final de la corrida
 * los mostraría todos juntos, cuando ya no sirven para reaccionar. Por eso se
 * mira seguido mientras la campaña manda —que es cuando pasan cosas— y
 * espaciado cuando no hay nada en curso, porque abrir IMAP es caro y no tiene
 * sentido hacerlo cada cinco minutos para no encontrar nada.
 *
 * Devuelve null si todavía no tocaba mirar.
 *
 * La llaman el cron (cada 60 s con campañas en curso, cada 900 s sin nada) y
 * el despachador de campañas en medio de una corrida larga. La marca vive en
 * el ajuste `imap_ultima_corrida` (epoch). Nunca lanza excepción: un error
 * vuelve como `['error' => ...]` con los contadores en cero.
 *
 * Trampa: la consulta y la escritura de la marca no son atómicas. Dos
 * procesos que entran casi juntos pueden leer la casilla a la vez; el UNIQUE
 * de `mensajes.message_id` evita el duplicado, pero el segundo corta con
 * error esa vuelta.
 *
 * @param int $cadaSegundos intervalo mínimo entre lecturas (piso de 30 s)
 * @return array|null el resumen de imap_revisar_todas(), o null si no tocaba
 */
function imap_quizas_revisar(int $cadaSegundos): ?array
{
    $ultima = (int) setting_get('imap_ultima_corrida', '0');
    if (time() - $ultima < max(30, $cadaSegundos)) { return null; }

    // La marca se pone ANTES de leer: si la lectura falla o tarda, no se
    // reintenta en bucle contra un servidor que no está contestando.
    setting_set('imap_ultima_corrida', (string)time());
    try {
        $r = imap_revisar_todas();
    } catch (\Throwable $e) {
        db_reanudar();
        return ['error' => $e->getMessage(), 'rebotes' => 0, 'respuestas' => 0,
                'revisados' => 0, 'casillas' => 0];
    }
    // Leer una casilla puede llevar varios segundos y este hosting corta la
    // conexión a la base a los 20 de inactividad.
    db_reanudar();
    return $r;
}

/**
 * Revisa la casilla de TODAS las cuentas de correo activas.
 *
 * Revisando una sola casilla, las campañas que salen por otras cuentas no se
 * enterarían de sus rebotes: esos contactos seguirían recibiendo correo en
 * direcciones que ya no existen, que es justo lo que arruina la reputación
 * del remitente.
 *
 * Cada cuenta lleva su propia marca de por dónde iba (tabla imap_estado), así
 * que ninguna vuelve a leer lo que ya leyó.
 *
 * Las casillas se leen una detrás de otra en el mismo proceso: el tiempo
 * total crece con la cantidad de cuentas. Un error en una no frena a las
 * demás; los mensajes se juntan en 'errores' y, unidos con « · », en 'error'.
 * También la llama Ajustes («Revisar ahora»).
 *
 * @param int $diasPrimeraVez ventana de la primera lectura de cada casilla
 * @return array{rebotes:int, duros:int, respuestas:int, revisados:int, casillas:int,
 *               error:?string, errores:array<int,string>}
 */
function imap_revisar_todas(int $diasPrimeraVez = 30): array
{
    $total = ['rebotes' => 0, 'duros' => 0, 'respuestas' => 0, 'revisados' => 0,
              'casillas' => 0, 'error' => null, 'errores' => []];

    $cuentas = cuentas_correo_listar(true);
    foreach ($cuentas as $c) {
        $cfg = cuenta_a_imap($c);
        if (empty($cfg['user']) || empty($cfg['pass'])) { continue; }
        // La marca se guarda por dirección de casilla, no por número de cuenta:
        // si mañana se borra y se vuelve a cargar la misma casilla, no se
        // reprocesan meses de correo viejo.
        $r = imap_revisar($diasPrimeraVez, $cfg, 'INBOX:' . mb_strtolower((string)$cfg['user']));
        $total['casillas']++;
        foreach (['rebotes', 'duros', 'respuestas', 'revisados'] as $k) {
            $total[$k] += (int)($r[$k] ?? 0);
        }
        if (!empty($r['error'])) {
            $total['errores'][] = $c['nombre'] . ': ' . $r['error'];
        }
    }

    if (!$cuentas) {
        // Sin cuentas cargadas se cae a la casilla de la configuración general,
        // que es lo que había antes de que existieran las cuentas múltiples.
        $r = imap_revisar($diasPrimeraVez);
        $total = $r + ['casillas' => 1, 'errores' => []];
        if (!empty($r['error'])) { $total['errores'] = [$r['error']]; }
    }

    $total['error'] = $total['errores'] ? implode(' · ', $total['errores']) : null;
    return $total;
}

/**
 * Escanea la casilla y actualiza rebotes y respuestas.
 *
 * La primera pasada mira los últimos N días; las siguientes, sólo lo que llegó
 * después del último UID visto. Sin eso, el cron cada 5 minutos releería la
 * casilla entera todo el tiempo.
 *
 * Qué se hace con cada mensaje nuevo de INBOX:
 *   · si es un rebote (remitente o asunto típicos), se busca en el cuerpo la
 *     dirección que falló y, si figura en `envios`, se llama a marcar_rebote()
 *     (que puede archivar contactos y dar de baja la dirección si es duro);
 *   · si el remitente tiene un envío en estado 'enviado', se baja el correo
 *     entero, se guarda en `mensajes` (mensaje_entrante) y se llama a
 *     marcar_respuesta(), que avisa al dueño de la campaña;
 *   · todo lo demás (correo personal, spam) se ignora sin guardarse.
 *
 * El cruce con la campaña es por dirección de correo, no por In-Reply-To:
 * cualquier correo de alguien que alguna vez recibió una campaña cuenta como
 * respuesta, y se asigna al envío más reciente.
 *
 * Escribe en: imap_estado (marca), mensajes, envios, contactos,
 * posibles_clientes, desuscripciones (a través de las funciones de campanas.php).
 *
 * Trampas:
 *   · No se mira UIDVALIDITY: si el servidor renumera la casilla, la marca
 *     guardada queda por encima de todo y no se lee nada más.
 *   · Si algo lanza una excepción a mitad de la vuelta, la marca NO se guarda
 *     (imap_guardar_uid queda adentro del try) y la próxima corrida reprocesa
 *     desde el principio de esta tanda; un mensaje que siempre falla deja la
 *     casilla trabada en ese punto.
 *
 * @param int         $diasPrimeraVez ventana de la primera lectura (sin marca previa)
 * @param array|null  $cfg            host/port/user/pass/cuenta_id; null = imap_cfg()
 * @param string      $etiquetaBuzon  clave en imap_estado ('INBOX:<usuario>' por cuenta)
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
            $res['revisados']++;

            $h = imap_cmd($fp, "UID FETCH $uid (BODY.PEEK[HEADER])");
            if (!$h['ok']) {
                // No se pudo leer. El puntero NO avanza: si avanzara, este
                // mensaje quedaría dado por procesado y ese rebote —o esa
                // respuesta de un cliente— se perdería sin que
                // nadie se entere. Se corta acá y la próxima corrida reintenta
                // desde este mismo mensaje.
                log_app('imap.lectura_fallida', ['tipo' => 'error',
                    'detalle' => 'No se pudo leer el mensaje ' . $uid . ' de ' . $etiquetaBuzon
                               . '. Se reintenta en la próxima corrida.']);
                break;
            }
            // Recién ahora se da por leído.
            $maxUid = max($maxUid, $uid);
            $cab    = $h['crudo'];
            $de     = imap_direccion(imap_cabecera($cab, 'From'));
            $asunto = imap_texto(imap_cabecera($cab, 'Subject'));

            // Lo que manda la propia casilla (copias, pruebas) no es ni rebote
            // ni respuesta.
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
                // Se guarda el correo entero, no sólo la marca de que
                // respondió: así la respuesta se lee desde el CRM sin tener
                // que irse al correo.
                $b     = imap_cmd($fp, "UID FETCH $uid (BODY.PEEK[])", 2097152);
                // Ojo: `crudo` trae también la línea de cierre del comando
                // («)» y «aNNNN OK …»). En un correo de una sola parte eso
                // queda pegado al final del cuerpo.
                $partes = $b['ok'] ? mime_partes($b['crudo']) : ['texto' => '', 'html' => ''];
                $fecha  = imap_cabecera($cab, 'Date');
                mensaje_entrante([
                    'de'          => $de,
                    'de_nombre'   => imap_nombre(imap_cabecera($cab, 'From')),
                    'para'        => $propio,
                    'asunto'      => $asunto,
                    'texto'       => $partes['texto'],
                    'html'        => $partes['html'],
                    'message_id'  => trim(imap_cabecera($cab, 'Message-ID')),
                    'in_reply_to' => trim(imap_cabecera($cab, 'In-Reply-To')),
                    'referencias' => trim(imap_cabecera($cab, 'References')),
                    'fecha'       => $fecha !== '' ? date('Y-m-d H:i:s', strtotime($fecha) ?: time()) : date('Y-m-d H:i:s'),
                    'cuenta_correo_id' => $cfg['cuenta_id'] ?? null,
                ]);
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

/**
 * Prueba la conexión sin procesar nada: conecta, autentica y abre INBOX.
 * No toca la base. La usan Ajustes y cuenta_probar().
 *
 * @param string|null $err por referencia: el motivo si falla
 * @param array|null  $cfg host/port/user/pass; null = imap_cfg()
 */
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

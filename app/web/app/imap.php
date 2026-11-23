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
//      etiqueta del comando atrás: no es el correo puro. El correo puro es
//      el literal (`literal` en lo que devuelve imap_cmd()), y es lo único
//      que se decodifica.
//    · La marca de por dónde se iba avanza mensaje por mensaje: un correo que
//      no se puede procesar se registra y se saltea, no traba la casilla.
//    · Se guarda el UIDVALIDITY de cada casilla: si el servidor la renumera,
//      la marca vieja ya no sirve y se vuelve a leer la ventana inicial.
//    · El cruce con la campaña es por Message-ID (In-Reply-To/References de
//      la respuesta, o la copia del original que trae el rebote). La
//      dirección del remitente es sólo el respaldo.
// ============================================================

/**
 * Lo máximo que se lee del socket para UNA respuesta. Pasado esto la
 * conexión se abandona: un servidor que manda sin parar no puede llenar la
 * memoria ni dejar el proceso leyendo para siempre.
 */
const IMAP_TOPE_ABSOLUTO = 67108864;
/** Bytes que se bajan de una respuesta (se pide con FETCH parcial: un adjunto grande no se baja entero). */
const IMAP_BYTES_RESPUESTA = 2097152;
/** Bytes que se bajan del cuerpo de un rebote (el DSN y la copia del original entran de sobra). */
const IMAP_BYTES_REBOTE = 524288;
/**
 * Días hacia atrás en que se busca un envío por dirección cuando la
 * respuesta no se pudo cruzar por Message-ID. Pasado ese plazo, un correo de
 * alguien que recibió una campaña hace meses ya no se cuenta como respuesta.
 */
const IMAP_RESPALDO_DIAS = 45;

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
 *      las cuentas múltiples, en texto plano; la migración 019 borra las
 *      contraseñas cuando hay una casilla activa);
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
 * ok=false, sucio=true y lo que alcanzó a leer.
 *
 * El tope `$limiteBytes` vale para lo que se GUARDA (líneas y literales): lo
 * que pasa del tope se sigue leyendo del socket y se descarta, así la
 * conexión no queda desfasada y el comando siguiente no recibe restos de
 * éste (`truncado` avisa que algo se descartó). Como red de seguridad, si el
 * servidor manda más de IMAP_TOPE_ABSOLUTO se deja de leer y se devuelve
 * sucio=true: esa conexión ya no se puede usar.
 *
 * Claves de la respuesta:
 *   · `literal`: el primer literal (`{n}` + n bytes), que en un FETCH es
 *     justo lo pedido, sin la línea `* n FETCH (` ni el cierre; '' si no hubo.
 *   · `literales`: todos los literales, en orden.
 *   · `crudo`: todas las líneas leídas, también la de cierre con la etiqueta.
 *
 * @param resource $fp          socket de imap_conectar()
 * @param string   $comando     el comando sin etiqueta ni CRLF
 * @param int      $limiteBytes tope de lo que se guarda (8 MB por defecto)
 * @return array{ok:bool, texto:string, lineas:array<int,string>, crudo:string,
 *               literal:string, literales:array<int,string>, truncado:bool, sucio:bool}
 */
function imap_cmd($fp, string $comando, int $limiteBytes = 8388608): array
{
    // Contador de etiquetas compartido por todo el proceso: sólo tiene que ser
    // distinto en cada comando de una misma conexión.
    static $n = 0;
    $tag = sprintf('a%04d', ++$n);
    fwrite($fp, $tag . ' ' . $comando . "\r\n");

    $lineas    = [];
    $literales = [];
    $crudo     = '';
    $ok        = false;
    $texto     = '';
    $leidos    = 0;
    $guardados = 0;
    $truncado  = false;
    $sucio     = true;   // pasa a false recién al ver la línea con la etiqueta

    while (true) {
        $linea = fgets($fp, 8192);
        if ($linea === false || $linea === '') { break; }
        $leidos += strlen($linea);
        if ($leidos > IMAP_TOPE_ABSOLUTO) { break; }

        if ($guardados < $limiteBytes) { $crudo .= $linea; $guardados += strlen($linea); }
        else { $truncado = true; }

        // Literal anunciado al final de la línea: se leen esos bytes exactos.
        // Lo que no entra en el tope se lee igual y se descarta.
        if (preg_match('/\{(\d+)\}\r?\n$/', $linea, $m)) {
            $faltan = (int)$m[1];
            $buf = '';
            while ($faltan > 0) {
                $trozo = fread($fp, min($faltan, 65536));
                if ($trozo === false || $trozo === '') { break 2; }
                $faltan -= strlen($trozo);
                $leidos += strlen($trozo);
                if ($leidos > IMAP_TOPE_ABSOLUTO) { break 2; }
                $lugar = $limiteBytes - $guardados - strlen($buf);
                if ($lugar > 0) { $buf .= strlen($trozo) <= $lugar ? $trozo : substr($trozo, 0, $lugar); }
                if (strlen($trozo) > max(0, $lugar)) { $truncado = true; }
            }
            $guardados  += strlen($buf);
            $crudo      .= $buf;
            $literales[] = $buf;
            $lineas[]    = rtrim($linea, "\r\n") . "\n" . $buf;
            continue;
        }

        $lineas[] = rtrim($linea, "\r\n");

        if (str_starts_with($linea, $tag . ' ')) {
            $resto = trim(substr($linea, strlen($tag) + 1));
            $ok    = str_starts_with($resto, 'OK');
            $texto = $resto;
            $sucio = false;
            break;
        }
    }

    return ['ok' => $ok && !$sucio, 'texto' => $texto, 'lineas' => $lineas, 'crudo' => $crudo,
            'literal' => $literales[0] ?? '', 'literales' => $literales,
            'truncado' => $truncado, 'sucio' => $sucio];
}

/**
 * Decodifica un encabezado MIME (=?UTF-8?B?...?=) a texto plano.
 *
 * Soporta las dos codificaciones de RFC 2047: B (base64) y Q
 * (quoted-printable con `_` como espacio), y convierte a UTF-8 desde el juego
 * de caracteres declarado (texto_a_utf8(), que nunca lanza).
 *
 * El espacio entre dos palabras codificadas seguidas no es parte del texto
 * (RFC 2047) y se quita ANTES de decodificar. Además, los bytes de palabras
 * seguidas con el mismo juego se juntan antes de convertir: Outlook parte el
 * asunto donde le queda, a veces en el medio de una letra de dos bytes.
 */
function imap_texto(?string $s): string
{
    $s = trim((string)$s);
    if ($s === '') { return ''; }
    $palabra = '=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=';
    $s = preg_replace('/(' . $palabra . ')\s+(?=' . $palabra . ')/', '$1', $s) ?? $s;

    $trozos = preg_split('/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($trozos === false) { return texto_a_utf8($s, 'UTF-8'); }

    $out = '';
    $juegoPend = null;
    $bytesPend = '';
    $volcar = static function () use (&$out, &$juegoPend, &$bytesPend): void {
        if ($juegoPend !== null) { $out .= texto_a_utf8($bytesPend, $juegoPend); }
        $juegoPend = null;
        $bytesPend = '';
    };
    // preg_split con captura devuelve: texto, juego, codificación, datos, texto, …
    for ($i = 0, $n = count($trozos); $i < $n; $i += 4) {
        $texto = $trozos[$i];
        if ($texto !== '') { $volcar(); $out .= texto_a_utf8($texto, 'UTF-8'); }
        if (!isset($trozos[$i + 3])) { break; }
        // RFC 2231 permite «UTF-8*es»: el idioma no interesa.
        $juego = strtoupper((string) preg_replace('/\*.*$/', '', $trozos[$i + 1]));
        $datos = $trozos[$i + 3];
        $bytes = strtoupper($trozos[$i + 2]) === 'B'
            ? (string) base64_decode($datos, false)
            : quoted_printable_decode(str_replace('_', ' ', $datos));
        if ($juegoPend !== null && $juegoPend !== $juego) { $volcar(); }
        $juegoPend  = $juego;
        $bytesPend .= $bytes;
    }
    $volcar();
    return trim($out);
}

/**
 * Pasa un texto a UTF-8 desde el juego de caracteres que declara el correo.
 * Nunca lanza.
 *
 * En PHP 8, mb_convert_encoding() lanza ValueError ante un juego que no
 * conoce (`unknown-8bit`, `iso-8859-8-i`, `x-user-defined` o lo que invente
 * un spammer), y la `@` no lo ataja: antes eso cortaba la lectura de la
 * casilla entera. Ahora: se prueban los alias conocidos, después iconv y, si
 * nada sirve, el texto se deja como está si ya es UTF-8 válido o se lo lee
 * como Windows-1252 (que incluye ISO-8859-1 y es lo que manda casi todo el
 * correo viejo en castellano).
 */
function texto_a_utf8(string $texto, string $juego): string
{
    if ($texto === '') { return ''; }
    $juego = strtoupper(trim($juego, " \t\"'"));
    $alias = [
        'UTF8' => 'UTF-8', 'LATIN1' => 'ISO-8859-1', 'ASCII' => 'US-ASCII', 'ANSI_X3.4-1968' => 'US-ASCII',
        'ISO-8859-8-I' => 'ISO-8859-8', 'ISO-8859-6-I' => 'ISO-8859-6',
        'KS_C_5601-1987' => 'UHC', 'GB2312' => 'CP936', 'GBK' => 'CP936', 'X-GBK' => 'CP936',
        'X-SJIS' => 'SJIS', 'SHIFT-JIS' => 'SJIS', 'WINDOWS-1252' => 'Windows-1252', 'CP-1252' => 'Windows-1252',
        'UNKNOWN-8BIT' => '', 'X-UNKNOWN' => '', 'X-USER-DEFINED' => '', 'DEFAULT' => '', 'UNKNOWN' => '',
    ];
    $juego = $alias[$juego] ?? $juego;

    if ($juego !== '' && $juego !== 'UTF-8' && $juego !== 'US-ASCII') {
        try {
            $conv = mb_convert_encoding($texto, 'UTF-8', $juego);
            if (is_string($conv) && mb_check_encoding($conv, 'UTF-8')) { return $conv; }
        } catch (\ValueError $e) {
            // Juego desconocido para mbstring: se prueba con iconv.
        }
        if (function_exists('iconv')) {
            $conv = @iconv($juego, 'UTF-8//IGNORE', $texto);
            if (is_string($conv) && $conv !== '' && mb_check_encoding($conv, 'UTF-8')) { return $conv; }
        }
    }
    // Hay quien declara UTF-8 (o nada) y manda otra cosa: si no es válido se
    // reinterpreta, porque un texto roto es peor que uno aproximado.
    if (mb_check_encoding($texto, 'UTF-8')) { return $texto; }
    return (string) mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
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
 * hacen fallar, pero se decodifican como basura. Por eso al decodificador
 * le llega sólo el literal del FETCH, nunca la respuesta IMAP cruda.
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
 * Pasa el cuerpo de una parte a UTF-8 desde el juego que declare. Es
 * texto_a_utf8(): nunca lanza, ni con un juego desconocido.
 */
function mime_a_utf8(string $texto, string $juego): string
{
    return texto_a_utf8($texto, $juego);
}

/**
 * Lee un parámetro de un encabezado MIME (`boundary`, `charset`…) respetando
 * mayúsculas y minúsculas del valor: el boundary las distingue. Acepta el
 * valor entre comillas (que puede tener espacios) o suelto. '' si no está.
 */
function mime_parametro(string $cabecera, string $nombre): string
{
    if (preg_match('/(?:^|;)\s*' . preg_quote($nombre, '/') . '\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]+))/i',
                   $cabecera, $m)) {
        return ($m[1] ?? '') !== '' ? (string) preg_replace('/\\\\(.)/s', '$1', $m[1]) : (string)($m[2] ?? '');
    }
    return '';
}

/**
 * Separa cabeceras y cuerpo en la primera línea en blanco. Una parte sin
 * cabeceras (empieza directamente con la línea en blanco) da cabeceras ''.
 *
 * @return array{0:string, 1:string}
 */
function mime_separar(string $crudo): array
{
    if (preg_match('/^\r?\n/', $crudo)) { return ['', (string) preg_replace('/^\r?\n/', '', $crudo)]; }
    $corte = preg_split("/\r?\n\r?\n/", $crudo, 2) ?: [$crudo];
    return [$corte[0], $corte[1] ?? ''];
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
 * El boundary se saca del encabezado original, sin pasarlo a minúsculas: lo
 * distingue, y Outlook, Apple Mail o Yahoo lo arman con mayúsculas. Los
 * separadores se buscan sólo a principio de línea, como dice el estándar.
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
    [$cab, $cuerpo] = mime_separar($crudo);
    // Sin Content-Type, el estándar dice text/plain en US-ASCII.
    $tipoCrudo = imap_cabecera($cab, 'Content-Type');
    $tipo  = strtolower($tipoCrudo) ?: 'text/plain';
    $codif = imap_cabecera($cab, 'Content-Transfer-Encoding');

    $limite = str_starts_with($tipo, 'multipart/') ? mime_parametro($tipoCrudo, 'boundary') : '';
    if ($limite !== '') {
        $trozos = explode("\n--" . $limite, "\n" . $cuerpo);
        array_shift($trozos);                        // el preámbulo no es una parte
        foreach ($trozos as $t) {
            if (str_starts_with($t, '--')) { break; }   // cierre del multipart
            // El resto de la línea del separador (espacios de relleno) y su
            // salto; al final, el \r que era del salto antes del separador.
            $t = (string) preg_replace('/^[ \t]*\r?\n/', '', $t, 1);
            if (str_ends_with($t, "\r")) { $t = substr($t, 0, -1); }
            $p = mime_partes($t, $profundidad + 1);
            if ($out['texto'] === '' && $p['texto'] !== '') { $out['texto'] = $p['texto']; }
            if ($out['html']  === '' && $p['html']  !== '') { $out['html']  = $p['html']; }
        }
        return $out;
    }

    // Un adjunto no es contenido para leer.
    if (stripos(ltrim(imap_cabecera($cab, 'Content-Disposition')), 'attachment') === 0) { return $out; }

    $juego = mime_parametro($tipoCrudo, 'charset') ?: 'UTF-8';
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
 * El UIDVALIDITY guardado de un buzón, o null si todavía no se guardó (o si
 * la columna no existe porque falta la migración 016).
 */
function imap_uidvalidity_guardado(string $buzon): ?int
{
    try {
        $v = qv('SELECT uidvalidity FROM imap_estado WHERE buzon = ?', [$buzon]);
    } catch (\PDOException $e) {
        return null;
    }
    return ($v === false || $v === null || $v === '') ? null : (int)$v;
}

/**
 * El UIDVALIDITY que anuncia el servidor al hacer SELECT
 * (`* OK [UIDVALIDITY 1234] …`), o null si no lo manda.
 *
 * @param array<int,string> $lineas las líneas de la respuesta al SELECT
 */
function imap_uidvalidity(array $lineas): ?int
{
    foreach ($lineas as $l) {
        if (preg_match('/\[UIDVALIDITY\s+(\d+)\]/i', $l, $m)) { return (int)$m[1]; }
    }
    return null;
}

/**
 * Guarda el UIDVALIDITY de un buzón. Con $reiniciar la marca de UID vuelve a
 * cero (el servidor renumeró la casilla y la marca vieja no significa nada).
 * Sin la columna (falta la migración 016) no hace nada: se sigue como antes.
 */
function imap_guardar_uidvalidity(string $buzon, int $validez, bool $reiniciar = false): void
{
    try {
        q('INSERT IGNORE INTO imap_estado (buzon, ultimo_uid) VALUES (?, 0)', [$buzon]);
        q('UPDATE imap_estado SET uidvalidity = ?' . ($reiniciar ? ', ultimo_uid = 0' : '') . ' WHERE buzon = ?',
          [$validez, $buzon]);
    } catch (\PDOException $e) {
        // Columna inexistente: la migración todavía no corrió.
    }
}

/**
 * Guarda hasta dónde se leyó un buzón y la hora de la corrida.
 *
 * Se llama después de CADA mensaje, no al final de la vuelta: así un corte a
 * mitad de camino no obliga a reprocesar lo ya hecho, y un mensaje que no se
 * pudo procesar no traba la casilla.
 *
 * La marca nunca retrocede (el CASE hace de GREATEST), aunque dos corridas
 * terminen en otro orden. Para releer una casilla hay que bajar la fila a
 * mano. Se escribe en dos pasos (INSERT IGNORE + UPDATE) para no depender de
 * ON DUPLICATE KEY.
 */
function imap_guardar_uid(string $buzon, int $uid): void
{
    q('INSERT IGNORE INTO imap_estado (buzon, ultimo_uid) VALUES (?, 0)', [$buzon]);
    q('UPDATE imap_estado SET ultimo_uid = CASE WHEN ultimo_uid < ? THEN ? ELSE ultimo_uid END,
              ultima_corrida = NOW()
       WHERE buzon = ?', [$uid, $uid, $buzon]);
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
 *   2. un código SMTP 4xx en su contexto (con el código extendido 4.x.x, o
 *      detrás de «smtp;», «said:» o «error») y ningún 5xx en el mismo
 *      contexto: pasajero. Un «450» suelto en el texto (la copia del
 *      original, un pie de página) no cuenta;
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
    $codigo = static fn(string $d): string =>
        '/(?:\b' . $d . '\d\d[ -]' . $d . '\.\d{1,3}\.\d{1,3}\b|(?:smtp;|said:|error:?)\s*' . $d . '\d\d\b)/i';
    if (preg_match($codigo('4'), $cuerpo) && !preg_match($codigo('5'), $cuerpo)) {
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
 * Original-Recipient). Si no hay DSN, lee SÓLO el aviso, cortado antes de la
 * copia del mensaje original que suelen traer los rebotes: ahí están el
 * remitente, el Reply-To y las direcciones del pie de la campaña, que no
 * rebotaron. Dentro del aviso, primero las formas típicas de los servidores
 * (`<x@y>:` de Postfix, la dirección sola en su línea de Exim y Gmail,
 * «to x@y» / «para x@y»); si ninguna aparece, todas las del aviso. El
 * llamador además filtra por las que figuran en `envios`.
 *
 * @return array<int,string> direcciones normalizadas, sin repetir
 */
function extraer_destinatario_rebote(string $cuerpo): array
{
    $email = '[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}';
    $encontrados = [];
    if (preg_match_all('/Final-Recipient:\s*rfc822;\s*<?([^\s<>]+@[^\s<>]+?)>?\s*$/im', $cuerpo, $m)) {
        $encontrados = array_merge($encontrados, $m[1]);
    }
    if (preg_match_all('/Original-Recipient:\s*rfc822;\s*<?([^\s<>]+@[^\s<>]+?)>?\s*$/im', $cuerpo, $m)) {
        $encontrados = array_merge($encontrados, $m[1]);
    }
    if (!$encontrados) {
        $aviso = rebote_sin_copia($cuerpo);
        $patrones = [
            '/^\s*<(' . $email . ')>\s*:/im',                         // Postfix: «<x@y>: host … said»
            '/^[ \t]*<?(' . $email . ')>?[ \t]*\r?$/im',              // Exim, Gmail: la dirección sola en su línea
            '/\b(?:to|para|a|recipient|destinatario)\s*:?\s*<?(' . $email . ')>?/i',
        ];
        foreach ($patrones as $re) {
            if (preg_match_all($re, $aviso, $m)) { $encontrados = array_merge($encontrados, $m[1]); }
        }
        if (!$encontrados && preg_match_all('/' . $email . '/i', $aviso, $m)) {
            $encontrados = $m[0];
        }
    }
    return array_values(array_unique(array_map('normalizar_email', $encontrados)));
}

/**
 * El texto del aviso de rebote sin la copia del mensaje original que viene
 * pegada abajo. Corta en la primera marca conocida: la parte
 * `message/rfc822` o `text/rfc822-headers`, los separadores de Exim, Postfix,
 * Gmail y Office 365, o el primer bloque de cabeceras (`From:`, `Received:`,
 * `Return-Path:` a principio de línea). Si no encuentra nada, devuelve todo.
 */
function rebote_sin_copia(string $cuerpo): string
{
    $marcas = [
        '/^Content-Type:\s*(?:message\/rfc822|text\/rfc822-headers|message\/global)/im',
        '/^-{2,}\s*(?:This is a copy of|Original message|Mensaje original|Below this line is a copy)/im',
        '/^-{2,}\s*(?:Forwarded message|Mensaje reenviado)/im',
        '/^(?:Return-Path|Received|From|De|Message-ID|DKIM-Signature):\s/im',
        '/^Original message headers:/im',
    ];
    $corte = strlen($cuerpo);
    foreach ($marcas as $re) {
        if (preg_match($re, $cuerpo, $m, PREG_OFFSET_CAPTURE) && $m[0][1] < $corte) { $corte = $m[0][1]; }
    }
    return substr($cuerpo, 0, $corte);
}

/**
 * Los Message-ID (`<algo@dominio>`) que aparecen en un texto: cabeceras
 * In-Reply-To/References, o la copia del original que trae un rebote.
 * Como mucho 50, sin repetir.
 *
 * @return array<int,string>
 */
function imap_message_ids(string $texto): array
{
    if (!preg_match_all('/<([^<>\s@]{1,200}@[^<>\s@]{1,200})>/', $texto, $m)) { return []; }
    $ids = array_values(array_unique(array_map(fn($x) => '<' . $x . '>', $m[1])));
    return array_slice($ids, 0, 50);
}

// ------------------------------------------------------------
//  Revisión de la casilla
// ------------------------------------------------------------

/**
 * Toma el turno de leer las casillas: escribe la marca `imap_ultima_corrida`
 * sólo si sigue valiendo lo que se leyó ($previa). Es una comparación y
 * escritura en una sola sentencia: si el cron y el despachador entran casi
 * juntos, uno solo gana y el otro no lee.
 *
 * Si la tabla de ajustes no responde como se espera, se cae al
 * setting_set() de siempre y se lee igual (la casilla soporta dos lecturas
 * a la vez: la marca es por mensaje y el guardado ignora repetidos).
 *
 * @param int $previa el valor de la marca que se leyó antes de decidir
 * @return bool true si este proceso tiene el turno
 */
function imap_tomar_turno(int $previa): bool
{
    $ahora = (string) time();
    try {
        q("INSERT IGNORE INTO app_settings (clave, valor) VALUES ('imap_ultima_corrida', '0')");
        $st = q("UPDATE app_settings SET valor = ?
                 WHERE clave = 'imap_ultima_corrida' AND (valor = ? OR valor IS NULL OR valor = '')",
                [$ahora, (string)$previa]);
        $gano = $st->rowCount() === 1;
    } catch (\Throwable $e) {
        setting_set('imap_ultima_corrida', $ahora);
        $gano = true;
    }
    // El caché de ajustes de un proceso largo (el cron) puede tener la marca
    // vieja: se descarta para que la próxima consulta lea la de la base.
    if (function_exists('setting_refrescar')) { setting_refrescar(); }
    return $gano;
}

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
 * Devuelve null si todavía no tocaba mirar, o si otro proceso tomó el turno.
 *
 * La llaman el cron (cada 60 s con campañas en curso, cada 900 s sin nada) y
 * el despachador de campañas en medio de una corrida larga. La marca vive en
 * el ajuste `imap_ultima_corrida` (epoch) y se toma con imap_tomar_turno(),
 * que es atómica. Nunca lanza excepción: un error vuelve como
 * `['error' => ...]` con los contadores en cero.
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
    if (!imap_tomar_turno($ultima)) { return null; }
    try {
        $r = imap_revisar_todas();
    } catch (\Throwable $e) {
        db_reanudar();
        log_error('imap.revisar', $e->getMessage(), $e->getFile(), $e->getLine());
        return ['error' => 'La lectura de las casillas falló; el detalle quedó en el registro de errores.',
                'rebotes' => 0, 'respuestas' => 0, 'revisados' => 0, 'casillas' => 0];
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
 * Abre la conexión, delega todo el trabajo en imap_procesar_buzon() y, al
 * final, recalcula los contadores de las campañas que tuvieron movimiento en
 * la última hora.
 *
 * Un error de la base (PDOException) no se muestra tal cual: se registra con
 * log_error() y vuelve un mensaje genérico.
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
        $res = imap_procesar_buzon($fp, $c, $etiquetaBuzon, $diasPrimeraVez);
    } catch (\PDOException $e) {
        log_error('imap.revisar', $e->getMessage(), $e->getFile(), $e->getLine());
        $res['error'] = 'Falló la base de datos mientras se leía la casilla; se reintenta en la próxima corrida.';
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
 * Las acciones que se disparan al encontrar un rebote o una respuesta. Están
 * separadas para que las pruebas puedan reemplazarlas por un doble.
 *
 *   · 'rebote'    fn(string $direccion, string $detalle, bool $duro): void
 *                 → marcar_rebote() (campanas.php);
 *   · 'respuesta' fn(array $d): bool, con $d en el formato de
 *                 mensaje_entrante(); devuelve false si el correo ya estaba
 *                 guardado → mensaje_entrante() + marcar_respuesta() del
 *                 envío cruzado.
 *
 * @return array{rebote:callable, respuesta:callable}
 */
function imap_acciones_por_defecto(): array
{
    return [
        'rebote' => static function (string $direccion, string $detalle, bool $duro): void {
            marcar_rebote($direccion, $detalle, $duro);
        },
        'respuesta' => static function (array $d): bool {
            if (mensaje_entrante($d) === null) { return false; }
            if (!empty($d['envio_id'])) {
                marcar_respuesta((string)$d['de'], (string)$d['asunto'], (int)$d['envio_id']);
            }
            return true;
        },
    ];
}

/**
 * Recorre los mensajes nuevos de INBOX en una conexión ya abierta.
 *
 * La primera pasada mira los últimos N días; las siguientes, sólo lo que llegó
 * después del último UID visto. Sin eso, el cron cada 5 minutos releería la
 * casilla entera todo el tiempo.
 *
 * UIDVALIDITY: si el servidor anuncia uno distinto del guardado, renumeró la
 * casilla y la marca vieja no sirve (quedaría por encima de todo y no se
 * leería nada más). Se vuelve a la ventana de la primera vez; lo ya guardado
 * no se duplica porque el guardado ignora los Message-ID repetidos.
 *
 * Qué se hace con cada mensaje nuevo (imap_procesar_mensaje):
 *   · si es un rebote, se busca de quién era: primero por el Message-ID del
 *     envío que trae la copia del original, después por el DSN y, como
 *     último recurso, por las direcciones del aviso que figuren en `envios`;
 *   · si cruza con un envío por In-Reply-To/References (o con un mensaje ya
 *     guardado de la conversación), es una respuesta: se baja el correo, se
 *     guarda y se marca el envío. Sin cruce por Message-ID, el respaldo es
 *     la dirección del remitente, sólo contra envíos de los últimos
 *     IMAP_RESPALDO_DIAS días;
 *   · todo lo demás (correo personal, spam) se ignora sin guardarse.
 *
 * La marca avanza después de CADA mensaje. Un mensaje que no se puede
 * procesar (charset imposible, dato que la base rechaza) se registra en la
 * auditoría y se saltea; sólo un problema de conexión (IMAP que no contesta,
 * base caída) corta la vuelta sin avanzar, para reintentar ese mismo mensaje
 * en la corrida siguiente.
 *
 * @param resource   $fp             conexión de imap_conectar() (o un doble en las pruebas)
 * @param array      $c              host/port/user/pass/cuenta_id
 * @param string     $etiquetaBuzon  clave en imap_estado
 * @param int        $diasPrimeraVez ventana de la primera lectura
 * @param array|null $acciones       null = imap_acciones_por_defecto()
 * @return array{rebotes:int, duros:int, respuestas:int, revisados:int, error:?string}
 */
function imap_procesar_buzon($fp, array $c, string $etiquetaBuzon, int $diasPrimeraVez = 30, ?array $acciones = null): array
{
    $res = ['rebotes' => 0, 'duros' => 0, 'respuestas' => 0, 'revisados' => 0, 'error' => null];
    $acciones = ($acciones ?? []) + imap_acciones_por_defecto();

    $sel = imap_cmd($fp, 'SELECT "INBOX"');
    if (!$sel['ok']) {
        $res['error'] = 'No se pudo abrir INBOX: ' . $sel['texto'];
        return $res;
    }

    $ultimo  = imap_ultimo_uid($etiquetaBuzon);
    $validez = imap_uidvalidity($sel['lineas']);
    if ($validez !== null) {
        $guardada = imap_uidvalidity_guardado($etiquetaBuzon);
        $renumerada = $guardada !== null && $guardada !== $validez;
        if ($renumerada) {
            log_app('imap.uidvalidity', ['tipo' => 'sistema',
                'detalle' => 'El servidor renumeró ' . $etiquetaBuzon . ' (UIDVALIDITY ' . $guardada . ' → '
                           . $validez . '). Se vuelve a leer desde los últimos ' . $diasPrimeraVez . ' días.']);
            $ultimo = 0;
        }
        if ($renumerada || $guardada === null) { imap_guardar_uidvalidity($etiquetaBuzon, $validez, $renumerada); }
    }

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
    $uids = array_values(array_unique(array_filter($uids, fn($u) => $u > $ultimo)));
    sort($uids);
    // Tope por corrida: el cron pasa cada pocos minutos, no hace falta
    // procesar miles de una y arriesgar el tiempo de ejecución.
    $uids   = array_slice($uids, 0, 300);
    $propio = normalizar_email((string)($c['user'] ?? ''));

    foreach ($uids as $uid) {
        $res['revisados']++;
        try {
            $p = imap_procesar_mensaje($fp, $uid, $propio, $c, $acciones);
        } catch (\PDOException $e) {
            // Un dato que la base rechaza (clase 22: demasiado largo, fecha
            // inválida; clase 23: clave repetida) es culpa de ESTE mensaje: se
            // saltea. Cualquier otro error de base (conexión caída) corta sin
            // avanzar y se reintenta en la próxima corrida.
            $clase = substr((string)($e->errorInfo[0] ?? $e->getCode()), 0, 2);
            if ($clase !== '22' && $clase !== '23') { throw $e; }
            imap_registrar_salteado($etiquetaBuzon, $uid, $e);
            $p = ['salteado' => true];
        } catch (\Throwable $e) {
            imap_registrar_salteado($etiquetaBuzon, $uid, $e);
            $p = ['salteado' => true];
        }

        if (!empty($p['reintentar'])) {
            // No se pudo leer. El puntero NO avanza: si avanzara, este
            // mensaje quedaría dado por procesado y ese rebote —o esa
            // respuesta de un cliente— se perdería sin que nadie se entere.
            // Se corta acá y la próxima corrida reintenta desde este mismo.
            log_app('imap.lectura_fallida', ['tipo' => 'error',
                'detalle' => 'No se pudo leer el mensaje ' . $uid . ' de ' . $etiquetaBuzon
                           . '. Se reintenta en la próxima corrida.']);
            break;
        }
        foreach (['rebotes', 'duros', 'respuestas'] as $k) { $res[$k] += (int)($p[$k] ?? 0); }
        imap_guardar_uid($etiquetaBuzon, $uid);
    }
    return $res;
}

/**
 * Deja constancia de un mensaje que no se pudo procesar y se salteó. El
 * detalle técnico va a la auditoría (sólo la ven los administradores).
 */
function imap_registrar_salteado(string $buzon, int $uid, \Throwable $e): void
{
    log_app('imap.mensaje_salteado', ['tipo' => 'error',
        'detalle' => 'El mensaje ' . $uid . ' de ' . $buzon . ' no se pudo procesar y se salteó: '
                   . get_class($e) . ' · ' . recortar($e->getMessage(), 300)]);
}

/**
 * Procesa un mensaje de la casilla: decide si es rebote, respuesta o nada,
 * y dispara la acción que corresponda.
 *
 * @return array{rebotes?:int, duros?:int, respuestas?:int, reintentar?:bool}
 *         'reintentar' = falló la lectura por IMAP: no hay que avanzar la marca
 */
function imap_procesar_mensaje($fp, int $uid, string $propio, array $c, array $acciones): array
{
    $h = imap_cmd($fp, "UID FETCH $uid (BODY.PEEK[HEADER])", 262144);
    if (!$h['ok']) { return ['reintentar' => true]; }
    // Si el mensaje se borró entre la búsqueda y la lectura, el servidor no
    // manda nada: queda sin cabeceras y se saltea.
    $cab    = $h['literal'];
    $de     = imap_direccion(imap_cabecera($cab, 'From'));
    $asunto = imap_texto(imap_cabecera($cab, 'Subject'));

    // Lo que manda la propia casilla (copias, pruebas) no es ni rebote
    // ni respuesta.
    if ($de === '' || $de === $propio) { return []; }

    if (es_rebote($de, $asunto)) {
        // Sólo acá hace falta el cuerpo: es donde viene el DSN con la
        // dirección que falló y el código de error.
        $b = imap_cmd($fp, "UID FETCH $uid (BODY.PEEK[TEXT]<0." . IMAP_BYTES_REBOTE . '>)', IMAP_BYTES_REBOTE + 65536);
        if (!$b['ok']) { return ['reintentar' => true]; }
        $cuerpo = $b['literal'];
        $duro   = es_rebote_duro($cuerpo);
        $out    = ['rebotes' => 0, 'duros' => 0];
        foreach (imap_rebote_destinatarios($cuerpo, $propio) as $direccion) {
            ($acciones['rebote'])($direccion, recortar($asunto, 200), $duro);
            $out['rebotes']++;
            if ($duro) { $out['duros']++; }
        }
        return $out;
    }

    $cruce = imap_cruzar_respuesta($cab, $de);
    if ($cruce === null) { return []; }

    // Se guarda el correo entero (hasta IMAP_BYTES_RESPUESTA: con un adjunto
    // grande se baja sólo el principio, donde van el texto y el HTML), no
    // sólo la marca de que respondió: así la respuesta se lee desde el CRM
    // sin tener que irse al correo.
    $b = imap_cmd($fp, "UID FETCH $uid (BODY.PEEK[]<0." . IMAP_BYTES_RESPUESTA . '>)', IMAP_BYTES_RESPUESTA + 65536);
    if (!$b['ok']) { return ['reintentar' => true]; }
    $partes = mime_partes($b['literal']);
    $fecha  = imap_cabecera($cab, 'Date');
    $ts     = $fecha !== '' ? strtotime($fecha) : false;
    $nuevo = ($acciones['respuesta'])([
        'de'          => $de,
        'de_nombre'   => imap_nombre(imap_cabecera($cab, 'From')),
        'para'        => $propio,
        'asunto'      => $asunto,
        'texto'       => $partes['texto'],
        'html'        => $partes['html'],
        'message_id'  => trim(imap_cabecera($cab, 'Message-ID')),
        'in_reply_to' => trim(imap_cabecera($cab, 'In-Reply-To')),
        'referencias' => trim(imap_cabecera($cab, 'References')),
        'fecha'       => date('Y-m-d H:i:s', $ts ?: time()),
        'cuenta_correo_id' => $c['cuenta_id'] ?? null,
        'envio_id'    => $cruce['envio_id'],
        'campana_id'  => $cruce['campana_id'],
        'contacto_id' => $cruce['contacto_id'] ?? null,
    ]);
    return ['respuestas' => $nuevo === false ? 0 : 1];
}

/**
 * ¿El correo es una respuesta a algo nuestro? Y si lo es, ¿a qué envío?
 *
 * Orden (DOCUMENTACION-MAESTRA §11, regla 5: el Message-ID es la forma de
 * cruzar):
 *   1. algún Message-ID de In-Reply-To/References es el de un envío de
 *      campaña → ese envío;
 *   2. es el de un mensaje ya guardado (por ejemplo, la respuesta que se
 *      mandó desde el CRM) → la misma conversación, con su envío y campaña;
 *   3. respaldo por dirección: el último envío 'enviado' a ese remitente en
 *      los últimos IMAP_RESPALDO_DIAS días (hay clientes de correo que no
 *      mandan las cabeceras del hilo).
 *
 * @return array{envio_id:?int, campana_id:?int, contacto_id?:?int, por:string}|null null = no es respuesta
 */
function imap_cruzar_respuesta(string $cabeceras, string $de): ?array
{
    $ids = imap_message_ids(imap_cabecera($cabeceras, 'In-Reply-To') . ' ' . imap_cabecera($cabeceras, 'References'));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $envio = q1("SELECT id, campana_id, contacto_id FROM envios WHERE message_id IN ($ph)
                     ORDER BY enviado_en DESC LIMIT 1", $ids);
        if ($envio) {
            return ['envio_id' => (int)$envio['id'], 'campana_id' => (int)$envio['campana_id'],
                    'contacto_id' => $envio['contacto_id'] !== null ? (int)$envio['contacto_id'] : null,
                    'por' => 'message_id'];
        }
        $msg = q1("SELECT envio_id, campana_id, contacto_id FROM mensajes WHERE message_id IN ($ph)
                   ORDER BY id DESC LIMIT 1", $ids);
        if ($msg) {
            return ['envio_id' => $msg['envio_id'] !== null ? (int)$msg['envio_id'] : null,
                    'campana_id' => $msg['campana_id'] !== null ? (int)$msg['campana_id'] : null,
                    'contacto_id' => $msg['contacto_id'] !== null ? (int)$msg['contacto_id'] : null,
                    'por' => 'hilo'];
        }
    }
    $envio = q1("SELECT id, campana_id, contacto_id FROM envios
                 WHERE email = ? AND estado = 'enviado' AND enviado_en >= ?
                 ORDER BY enviado_en DESC LIMIT 1",
                [$de, date('Y-m-d H:i:s', time() - IMAP_RESPALDO_DIAS * 86400)]);
    if ($envio) {
        return ['envio_id' => (int)$envio['id'], 'campana_id' => (int)$envio['campana_id'],
                'contacto_id' => $envio['contacto_id'] !== null ? (int)$envio['contacto_id'] : null,
                'por' => 'direccion'];
    }
    return null;
}

/**
 * A quién se le marca el rebote.
 *
 *   1. Si la copia del original trae el Message-ID de un envío nuestro, es
 *      la dirección de ESE envío, sin adivinar.
 *   2. Si no, las direcciones de extraer_destinatario_rebote() (DSN o el
 *      aviso sin la copia) que figuren en `envios`: los rebotes personales
 *      de la misma casilla no ensucian el CRM.
 * Nunca la propia casilla.
 *
 * @return array<int,string>
 */
function imap_rebote_destinatarios(string $cuerpo, string $propio): array
{
    $ids = imap_message_ids($cuerpo);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $porId = array_map(fn($f) => normalizar_email((string)$f['email']),
                           q("SELECT DISTINCT email FROM envios WHERE message_id IN ($ph)", $ids)->fetchAll());
        $porId = array_values(array_unique(array_filter($porId, fn($d) => $d !== '' && $d !== $propio)));
        if ($porId) { return $porId; }
    }
    $out = [];
    foreach (extraer_destinatario_rebote($cuerpo) as $direccion) {
        if ($direccion === $propio) { continue; }
        if (!qv('SELECT 1 FROM envios WHERE email = ? LIMIT 1', [$direccion])) { continue; }
        $out[] = $direccion;
    }
    return $out;
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

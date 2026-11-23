<?php
declare(strict_types=1);

// ============================================================
//  Conversaciones: leer y contestar sin salir del CRM.
//
//  Las respuestas a las campañas se guardan, se leen y se contestan desde
//  acá, y la conversación queda pegada al contacto. Si para leer y contestar
//  hubiera que irse al correo, se perdería el hilo: el que atiende mañana no
//  sabría qué se habló, porque quedó en la bandeja de alguien.
//
//  Módulo: Campañas (la pantalla es `conversacion`, ruta de ese módulo).
//  Quién lo usa:
//    · imap.php guarda acá cada respuesta que entra (mensaje_entrante);
//    · pages/conversacion.php muestra el hilo y contesta
//      (conversacion_responder → mensaje_responder);
//    · views/conversacion.php sanea y parte el HTML al mostrarlo.
//
//  Tablas: escribe y lee `mensajes` (migracion-004-mensajes.sql); lee
//  `contactos`, `envios`, `campanas`, `cuentas_correo` y `usuarios`.
//  Depende de: db.php, helpers.php (e, normalizar_email, email_valido,
//  recortar, log_app), actividad.php (log_error), envio.php (smtp_abrir,
//  smtp_enviar, smtp_cerrar, nuevo_message_id), cuentas_correo.php
//  (cuenta_a_config) y la extensión DOM (saneado del HTML).
//
//  Decisiones:
//    · La conversación se arma por dirección de correo, no por contacto: una
//      respuesta de alguien que no está cargado no se pierde.
//    · En la base se guarda el cuerpo tal cual llegó (texto y HTML, sin
//      sanear ni cortar la cita); el saneado y el corte se hacen al mostrar.
// ============================================================

/**
 * Guarda un correo que entró.
 *
 * El Message-ID es la clave única: si la casilla se relee (pasa cuando se
 * reinicia la marca de por dónde iba, o cuando el cron y el despachador la
 * leen a la vez), el mismo correo no se duplica. El INSERT es IGNORE: si dos
 * lecturas se pisan, la segunda no lanza, simplemente no guarda nada.
 *
 * Asignación: si quien llama ya cruzó el correo con su envío (imap.php lo
 * hace por In-Reply-To/References), vienen `envio_id`, `campana_id` y
 * `contacto_id` y se usan tal cual. Si no vienen, se busca como antes: el
 * contacto es el primero vivo con ese correo principal y el envío el último
 * 'enviado' a esa dirección. Sin contacto, el mensaje queda con contacto_id
 * NULL; igual aparece en la conversación porque esa se arma por dirección.
 *
 * Los textos se recortan al largo de cada columna (`message_id` e
 * `in_reply_to` son VARCHAR(255)): con MySQL en modo estricto, un encabezado
 * largo no puede hacer fallar el guardado.
 *
 * @param array $d de, de_nombre, para, asunto, texto, html, message_id,
 *                 in_reply_to, referencias, fecha ('Y-m-d H:i:s'), cuenta_correo_id
 *                 y opcionales envio_id, campana_id, contacto_id
 * @return int|null id del mensaje, o null si ya estaba guardado
 */
function mensaje_entrante(array $d): ?int
{
    $de = normalizar_email((string)($d['de'] ?? ''));
    if ($de === '') { return null; }

    // Sin Message-ID (pasa con algunos sistemas automáticos) se inventa uno
    // estable con remitente, asunto y fecha: si se relee, da el mismo y no
    // se duplica.
    $messageId = trim((string)($d['message_id'] ?? '')) ?: ('<sin-id-' . md5($de . ($d['asunto'] ?? '') . ($d['fecha'] ?? '')) . '@crm>');
    // Un Message-ID de más de 255 se reemplaza por uno estable derivado de él
    // (recortarlo podría hacer chocar a dos distintos).
    if (strlen($messageId) > 255) { $messageId = '<largo-' . sha1($messageId) . '@crm>'; }
    if (qv('SELECT 1 FROM mensajes WHERE message_id = ?', [$messageId])) { return null; }

    if (array_key_exists('envio_id', $d) || array_key_exists('campana_id', $d)) {
        $envio = ['id' => ($d['envio_id'] ?? null) ?: null, 'campana_id' => ($d['campana_id'] ?? null) ?: null];
    } else {
        $envio = q1("SELECT id, campana_id FROM envios WHERE email = ? AND estado = 'enviado'
                        ORDER BY enviado_en DESC LIMIT 1", [$de]);
    }
    $contactoId = ($d['contacto_id'] ?? null) ?: null;
    if (!$contactoId) {
        $contacto   = q1('SELECT id FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$de]);
        $contactoId = $contacto['id'] ?? null;
    }
    $inReplyTo = trim((string)($d['in_reply_to'] ?? ''));

    $st = q('INSERT IGNORE INTO mensajes (contacto_id, envio_id, campana_id, cuenta_correo_id, direccion,
                             de, de_nombre, para, asunto, cuerpo_texto, cuerpo_html,
                             message_id, in_reply_to, referencias, fecha)
       VALUES (?, ?, ?, ?, \'entrante\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$contactoId, $envio['id'] ?? null, $envio['campana_id'] ?? null,
       ($d['cuenta_correo_id'] ?? null) ?: null,
       mb_substr($de, 0, 190), recortar((string)($d['de_nombre'] ?? ''), 160),
       mb_substr(normalizar_email((string)($d['para'] ?? '')), 0, 190),
       recortar((string)($d['asunto'] ?? ''), 400),
       mensaje_cuerpo_tope((string)($d['texto'] ?? '')), mensaje_cuerpo_tope((string)($d['html'] ?? '')),
       $messageId, $inReplyTo !== '' ? mb_substr($inReplyTo, 0, 255) : null,
       ($d['referencias'] ?? null) ? mb_substr((string)$d['referencias'], 0, 20000) : null,
       $d['fecha'] ?? date('Y-m-d H:i:s')]);

    if ($st->rowCount() < 1) { return null; }   // otra lectura lo guardó primero
    return (int) last_id();
}

/**
 * Recorta un cuerpo al tope de MEDIUMTEXT (16 MB) con margen, sin partir
 * una letra de varios bytes. En la práctica nunca llega: imap.php baja como
 * mucho 2 MB por correo.
 */
function mensaje_cuerpo_tope(string $t): string
{
    return strlen($t) > 8000000 ? mb_strcut($t, 0, 8000000, 'UTF-8') : $t;
}

/**
 * La conversación con una persona: todo lo que entró y lo que salió, en orden.
 *
 * Se busca por dirección de correo y no por id de contacto: quien responde una
 * campaña puede no estar cargado como contacto todavía, y su respuesta no se
 * puede perder por eso.
 *
 * Trae lo que la persona escribió (`de` = su correo) y lo que se le mandó
 * (`para` = su correo), con el nombre del usuario que contestó. Devuelve los
 * $limite MÁS NUEVOS, ordenados de lo más viejo a lo más nuevo: en un hilo
 * largo quedan afuera los primeros, nunca los últimos (que son los que hay
 * que contestar). La migración 016 agrega los índices por `de` y por `para`.
 *
 * @return array<int,array> filas de `mensajes` + `usuario`; [] si el correo es vacío
 */
function conversacion_de(string $email, int $limite = 100): array
{
    $email = normalizar_email($email);
    if ($email === '') { return []; }
    $filas = q('SELECT m.*, u.nombre AS usuario
                FROM mensajes m LEFT JOIN usuarios u ON u.id = m.usuario_id
                WHERE m.de = ? OR m.para = ?
                ORDER BY m.fecha DESC, m.id DESC
                LIMIT ' . max(1, $limite), [$email, $email])->fetchAll();
    return array_reverse($filas);
}

/** Etiquetas que se conservan en el HTML de un correo entrante (lista blanca). */
const HTML_CORREO_ETIQUETAS = [
    'a', 'abbr', 'address', 'b', 'bdi', 'bdo', 'big', 'blockquote', 'br', 'caption', 'center', 'cite', 'code',
    'col', 'colgroup', 'dd', 'del', 'dfn', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'font', 'h1', 'h2',
    'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'mark', 'ol', 'p', 'pre', 'q', 's', 'samp',
    'small', 'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
    'tt', 'u', 'ul', 'wbr',
];
/**
 * Etiquetas que se tiran CON su contenido: ejecutan, cargan otra cosa, o su
 * contenido no es texto para leer. Las que no están en ninguna de las dos
 * listas se desarman: se va la etiqueta y queda lo de adentro.
 */
const HTML_CORREO_FUERA = [
    'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
    'select', 'textarea', 'option', 'link', 'meta', 'base', 'head', 'title', 'svg', 'math', 'noscript',
    'template', 'audio', 'video', 'source', 'track', 'canvas', 'map', 'area', 'xml', 'portal', 'dialog',
    'noembed', 'noframes', 'plaintext', 'xmp', 'slot',
];
/** Atributos que se conservan (todos los demás, incluidos los on*, se van). */
const HTML_CORREO_ATRIBUTOS = [
    'href', 'src', 'alt', 'title', 'width', 'height', 'align', 'valign', 'bgcolor', 'color', 'face', 'size',
    'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'dir', 'lang', 'style', 'class', 'span',
    'start', 'type', 'nowrap', 'hspace', 'vspace', 'cite', 'datetime',
];

/**
 * Deja el HTML de un correo en condiciones de mostrarse.
 *
 * El correo lo escribió alguien de afuera y puede traer cualquier cosa. Se
 * arma el árbol con DOMDocument y se reconstruye con una LISTA BLANCA:
 * sólo quedan las etiquetas de formato de HTML_CORREO_ETIQUETAS, con los
 * atributos de HTML_CORREO_ATRIBUTOS. Lo que ejecuta o carga otra cosa
 * (HTML_CORREO_FUERA: script, style, iframe, svg, math, form…) se va con su
 * contenido; cualquier otra etiqueta se desarma y queda el texto.
 *
 * Los enlaces y las imágenes se revisan después de que el parser decodificó
 * las entidades (así `&#106;avascript:` o `java&#x09;script:` se ven como lo
 * que son): un href sólo puede ser http, https, mailto, tel o un ancla; un
 * src, http, https, cid o una imagen data: (png, gif, jpeg, webp). Los
 * estilos en línea pierden todo lo que pueda ejecutar o cargar (`url()`,
 * `expression`, `@import`, `behavior`, escapes con barra). Los enlaces salen
 * con target="_blank" y rel="noopener noreferrer".
 *
 * Es la primera capa. La segunda es la vista: el marco va con sandbox SIN
 * allow-same-origin y con una política de contenido que no deja correr nada
 * que no sea el script propio que mide el alto.
 *
 * Las imágenes remotas no se tocan: se cargan al abrir la conversación (el
 * remitente puede saber que se leyó). Sin la extensión DOM, se devuelve el
 * texto plano escapado: nunca el HTML sin revisar.
 *
 * @return string el HTML saneado ('' si venía vacío)
 */
function mensaje_html_seguro(string $html): string
{
    if (trim($html) === '') { return ''; }
    if (!class_exists('DOMDocument')) {
        return nl2br(htmlspecialchars(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                                      ENT_QUOTES, 'UTF-8'));
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previo = libxml_use_internal_errors(true);
    // El meta charset delante hace que libxml lea UTF-8 (si no, asume
    // ISO-8859-1 y rompe los acentos).
    $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
                 . '</head><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previo);

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) { return ''; }

    $limpio = new DOMDocument('1.0', 'UTF-8');
    $raiz = $limpio->createElement('div');
    $limpio->appendChild($raiz);
    html_correo_copiar($body, $raiz, $limpio, 0);

    $out = '';
    foreach ($raiz->childNodes as $hijo) { $out .= $limpio->saveHTML($hijo); }
    return $out;
}

/**
 * Copia los hijos de $origen dentro de $destino aplicando la lista blanca.
 * Corta a los 60 niveles de anidamiento (un correo armado para colgar el
 * parser no pasa de ahí).
 */
function html_correo_copiar(DOMNode $origen, DOMNode $destino, DOMDocument $doc, int $nivel): void
{
    if ($nivel > 60) { return; }
    foreach ($origen->childNodes as $n) {
        if ($n instanceof DOMText) {
            // Incluye CDATA (DOMCDATASection hereda de DOMText): pasa como texto.
            $destino->appendChild($doc->createTextNode($n->data));
            continue;
        }
        if (!$n instanceof DOMElement) { continue; }   // comentarios, instrucciones: fuera
        $tag = strtolower($n->localName ?? $n->nodeName);
        if ($n->namespaceURI !== null && $n->namespaceURI !== '' && $n->namespaceURI !== 'http://www.w3.org/1999/xhtml') {
            continue;   // svg, math y cualquier otro espacio de nombres
        }
        if (in_array($tag, HTML_CORREO_FUERA, true)) { continue; }
        if (!in_array($tag, HTML_CORREO_ETIQUETAS, true)) {
            // Etiqueta desconocida (o html/body anidados): se desarma.
            html_correo_copiar($n, $destino, $doc, $nivel + 1);
            continue;
        }
        $nuevo = $doc->createElement($tag);
        foreach ($n->attributes ?? [] as $attr) {
            $nombre = strtolower($attr->nodeName);
            if (!in_array($nombre, HTML_CORREO_ATRIBUTOS, true)) { continue; }
            $valor = html_correo_atributo($tag, $nombre, (string)$attr->value);
            if ($valor !== null) { $nuevo->setAttribute($nombre, $valor); }
        }
        if ($tag === 'a' && $nuevo->hasAttribute('href')) {
            $nuevo->setAttribute('target', '_blank');
            $nuevo->setAttribute('rel', 'noopener noreferrer');
        }
        $destino->appendChild($nuevo);
        html_correo_copiar($n, $nuevo, $doc, $nivel + 1);
    }
}

/**
 * Revisa el valor de un atributo permitido. Devuelve el valor a conservar o
 * null para quitarlo. El valor ya viene con las entidades decodificadas.
 */
function html_correo_atributo(string $tag, string $nombre, string $valor): ?string
{
    if ($nombre === 'href' || $nombre === 'src' || $nombre === 'cite') {
        return html_correo_url($valor, $nombre === 'src' && $tag === 'img');
    }
    if ($nombre === 'style') {
        return html_correo_estilo($valor);
    }
    if ($nombre === 'type' && !in_array($tag, ['ol', 'ul', 'li'], true)) { return null; }
    return mb_substr($valor, 0, 500);
}

/**
 * ¿La dirección de un enlace o imagen es segura? Se mira el esquema después
 * de sacarle los espacios y caracteres de control que los navegadores
 * ignoran (`java\tscript:` es javascript: para el navegador).
 *
 * @param bool $imagen true para el src de una imagen (admite cid: y data:image)
 * @return string|null la dirección a conservar, o null
 */
function html_correo_url(string $url, bool $imagen): ?string
{
    $url = trim($url);
    $plana = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $url));
    if ($plana === '') { return null; }
    if (str_starts_with($plana, '#')) { return $imagen ? null : $url; }
    if (preg_match('#^https?://#', $plana)) { return $url; }
    if ($imagen) {
        if (str_starts_with($plana, 'cid:')) { return $url; }
        if (preg_match('#^data:image/(png|gif|jpe?g|webp);base64,#', $plana)) { return $url; }
        return null;
    }
    if (preg_match('#^(mailto|tel):#', $plana)) { return $url; }
    return null;
}

/**
 * Limpia un style en línea: se queda sólo con declaraciones `propiedad: valor`
 * sin nada que pueda ejecutar o cargar otra cosa. Null si no queda nada.
 */
function html_correo_estilo(string $css): ?string
{
    $plano = strtolower((string) preg_replace('/\s+/', '', $css));
    // Escapes con barra o comentarios pueden esconder cualquier palabra.
    if (str_contains($css, '\\') || str_contains($css, '/*') || str_contains($css, '<')) { return null; }
    foreach (['expression(', 'javascript:', 'vbscript:', '@import', 'behavior:', '-moz-binding', 'url('] as $malo) {
        if (str_contains($plano, $malo)) {
            // url() se saca de la declaración y el resto se conserva.
            if ($malo === 'url(') { break; }
            return null;
        }
    }
    $buenas = [];
    foreach (explode(';', $css) as $decl) {
        if (!preg_match('/^\s*([a-z\-]{1,40})\s*:\s*(.{1,300})$/is', $decl, $m)) { continue; }
        $prop = strtolower($m[1]);
        $val  = trim($m[2]);
        if (stripos(str_replace(' ', '', $val), 'url(') !== false) { continue; }
        if (in_array($prop, ['position', 'behavior', 'content'], true)) { continue; }   // nada encima del resto
        $buenas[] = $prop . ':' . $val;
    }
    return $buenas ? implode(';', $buenas) : null;
}

/**
 * Separa la respuesta de la cita, en la versión HTML.
 *
 * Los clientes de correo marcan la cita de forma reconocible: Gmail la envuelve
 * en `gmail_quote`, casi todos usan `blockquote`. Sin cortar ahí, una respuesta
 * de dos palabras aparece precedida de la campaña entera y hay que buscar con
 * la vista dónde empieza lo que la persona escribió.
 *
 * Se corta por posición en el texto, sin entender el árbol HTML: las
 * etiquetas que abrían antes del corte quedan sin cerrar en `limpio` (el
 * navegador las cierra solo dentro del iframe).
 *
 * @return array{limpio:string, citado:string}
 */
function mensaje_partir_cita_html(string $html): array
{
    // Se prueban en orden de precisión: la marca de Gmail, la de Outlook y,
    // si no hay ninguna, el primer blockquote.
    foreach (['/<div[^>]*class="[^"]*gmail_quote/i',
              '/<div[^>]*id="[^"]*(divRplyFwdMsg|appendonsend)/i',   // Outlook
              '/<blockquote/i'] as $re) {
        if (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
            $limpio = trim(substr($html, 0, $m[0][1]));
            // Arriba del corte tiene que haber algo que leer: texto o una
            // imagen. Si sólo quedaron etiquetas vacías (`<div><br></div>`),
            // se prueba la marca siguiente y, si ninguna sirve, se muestra
            // el mensaje entero sin plegar.
            $legible = trim(str_replace("\u{a0}", ' ', html_entity_decode(strip_tags($limpio, '<img>'), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($legible !== '') {
                return ['limpio' => $limpio, 'citado' => trim(substr($html, $m[0][1]))];
            }
        }
    }
    return ['limpio' => $html, 'citado' => ''];
}

/**
 * Marca como leída la conversación con alguien: todos los entrantes de esa
 * dirección que estaban sin leer. Es global, no por usuario: si uno la abre,
 * queda leída para todos.
 */
function conversacion_marcar_leida(string $email): void
{
    q("UPDATE mensajes SET leido_en = NOW()
       WHERE direccion = 'entrante' AND leido_en IS NULL AND de = ?", [normalizar_email($email)]);
}

/**
 * Contesta un mensaje desde el CRM.
 *
 * Sale por la MISMA casilla que recibió el original, no por la de siempre: si
 * la campaña salió de la cuenta de RRHH, la respuesta tiene que salir de RRHH.
 * Contestar desde otra dirección le llega a la persona como un correo de un
 * desconocido, y encima la siguiente respuesta ya no la ve el sistema.
 *
 * Las cabeceras In-Reply-To y References son las que hacen que al destinatario
 * le aparezca pegado al hilo en vez de suelto. Sin ellas, cada respuesta abre
 * una conversación nueva en su bandeja.
 *
 * Orden: se valida, se elige la casilla, se manda por SMTP y recién si salió
 * se guarda en `mensajes` como 'saliente' (ya leído), se marca leída la
 * conversación y se deja registro en la auditoría. Si el envío falla no se
 * guarda nada.
 *
 * Elección de la casilla: la que recibió el original; si no, la de la
 * campaña; si no, la predeterminada. Siempre una que siga activa y no esté
 * dada de baja. Sin ninguna, smtp_abrir() usa la configuración general.
 *
 * El texto se manda tal cual en la parte de texto y escapado (e + nl2br) en
 * la de HTML: lo que escribe el usuario nunca se interpreta como HTML.
 *
 * El envío en sí lo hace $enviar (por defecto, mensaje_enviar_smtp()): las
 * pruebas le pasan un doble y no sale nada por la red.
 *
 * @param int           $mensajeId id del mensaje al que se contesta (cualquiera, entrante o saliente)
 * @param string        $texto     lo que escribió el usuario
 * @param int|null      $usuarioId quién contesta; null = el usuario de la sesión
 * @param callable|null $enviar    fn(array $msg, ?array $cfg, ?string &$err): string ('enviado' si salió)
 * @return int id del mensaje saliente guardado
 * @throws RuntimeException si el mensaje no existe, el texto está vacío, el
 *                          remitente no es válido o el envío falla
 */
function mensaje_responder(int $mensajeId, string $texto, ?int $usuarioId = null, ?callable $enviar = null): int
{
    $orig = q1('SELECT * FROM mensajes WHERE id = ?', [$mensajeId]);
    if (!$orig)                        { throw new RuntimeException('El mensaje no existe.'); }
    if (trim($texto) === '')           { throw new RuntimeException('Escribí algo antes de mandar.'); }

    $para = normalizar_email((string)$orig['de']);
    if (!email_valido($para))          { throw new RuntimeException('El mensaje no tiene un remitente válido.'); }

    // La casilla por la que salió la campaña; si no se sabe, la predeterminada.
    $cuenta = null;
    if (!empty($orig['cuenta_correo_id'])) {
        $cuenta = q1('SELECT * FROM cuentas_correo WHERE id = ? AND activo = 1 AND eliminado_en IS NULL',
                     [(int)$orig['cuenta_correo_id']]);
    }
    if (!$cuenta && !empty($orig['campana_id'])) {
        $cuenta = q1('SELECT cc.* FROM campanas c JOIN cuentas_correo cc ON cc.id = c.cuenta_correo_id
                      WHERE c.id = ? AND cc.activo = 1 AND cc.eliminado_en IS NULL', [(int)$orig['campana_id']]);
    }
    if (!$cuenta) {
        $cuenta = q1('SELECT * FROM cuentas_correo WHERE activo = 1 AND eliminado_en IS NULL
                      ORDER BY predeterminada DESC, id LIMIT 1');
    }
    $cfg = $cuenta ? cuenta_a_config($cuenta) : null;

    // «Re:» una sola vez: si ya lo trae (en cualquier combinación de
    // mayúsculas) no se le agrega otro.
    $asunto = (string)$orig['asunto'];
    if (stripos($asunto, 're:') !== 0) { $asunto = 'Re: ' . $asunto; }

    // El hilo: se encadena el original y todo lo que ya venía encadenado.
    $refs = trim((string)($orig['referencias'] ?? '') . ' ' . (string)$orig['message_id']);

    $messageId = nuevo_message_id();

    // La firma de la casilla, si tiene: la respuesta sale con nombre, cargo y
    // teléfono, igual que desde Gmail.
    $firma = trim((string)($cfg['firma'] ?? ''));
    $textoFinal = $firma !== '' ? $texto . "

--
" . $firma : $texto;

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#222">'
          . nl2br(e($texto));
    if ($firma !== '') {
        $html .= '<div style="margin-top:18px;padding-top:12px;border-top:1px solid #e6e3ee;'
               . 'font-size:13px;line-height:1.5;color:#5a5566">' . nl2br(e($firma)) . '</div>';
    }
    $html .= '</div>';

    $err = null;
    $enviar = $enviar ?? 'mensaje_enviar_smtp';
    $estado = $enviar([
        'para'        => $para,
        'para_nombre' => (string)($orig['de_nombre'] ?? ''),
        'asunto'      => $asunto,
        'texto'       => $textoFinal,
        'html'        => $html,
        'message_id'  => $messageId,
        'in_reply_to' => (string)$orig['message_id'],
        'referencias' => $refs,
    ], $cfg, $err);
    if ($estado !== 'enviado') {
        throw new RuntimeException('No se pudo enviar: ' . ($err ?: $estado));
    }

    q('INSERT INTO mensajes (contacto_id, envio_id, campana_id, cuenta_correo_id, direccion,
                             de, de_nombre, para, asunto, cuerpo_texto, cuerpo_html,
                             message_id, in_reply_to, referencias, fecha, usuario_id, leido_en)
       VALUES (?, ?, ?, ?, \'saliente\', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())',
      [$orig['contacto_id'], $orig['envio_id'], $orig['campana_id'], $cuenta['id'] ?? null,
       $cfg['from'] ?? '', $cfg['from_name'] ?? '', $para, $asunto, $textoFinal, $html,
       $messageId, (string)$orig['message_id'], $refs, $usuarioId ?: current_user_id()]);

    // El id se toma ACÁ. Más abajo hay otra escritura —la auditoría— y
    // `last_id()` devuelve la última de la conexión, no la de esta tabla:
    // leído al final, devolvería el id del registro de auditoría.
    $nuevo = (int) last_id();

    // Contestada es leída: si no, la conversación queda marcada como pendiente
    // aunque ya se haya atendido.
    conversacion_marcar_leida($para);

    log_app('mensaje.responder', ['entidad' => 'contacto', 'entidad_id' => (int)($orig['contacto_id'] ?? 0),
        'detalle' => 'Respuesta enviada a ' . $para]);

    return $nuevo;
}

/**
 * El envío real de una respuesta: abre SMTP con la casilla elegida, manda y
 * cierra. Es el $enviar por defecto de mensaje_responder().
 *
 * @return string 'enviado' | 'rechazado' | 'error' (como smtp_enviar)
 * @throws RuntimeException si no se pudo conectar al servidor de salida
 */
function mensaje_enviar_smtp(array $msg, ?array $cfg, ?string &$err = null): string
{
    $fp = smtp_abrir($err, $cfg);
    if (!$fp) { throw new RuntimeException('No se pudo conectar al servidor de salida: ' . $err); }
    try {
        return smtp_enviar($fp, $msg, $err, $cfg);
    } finally {
        smtp_cerrar($fp);
    }
}

/**
 * Atiende el formulario «Responder» de la pantalla de conversación.
 *
 * Comprueba que el mensaje que se contesta sea de ESTA conversación (que la
 * persona sea su remitente o su destinatario): el id viene del formulario y,
 * sin el chequeo, se podía usar la pantalla de una persona para escribirle a
 * otra. Los errores esperables (texto vacío, envío rechazado) se muestran
 * tal cual; cualquier otro (la base, por ejemplo) se registra con
 * log_error() y se muestra un mensaje genérico.
 *
 * @param array         $post   $_POST (mensaje_id, texto)
 * @param string        $email  la persona de la conversación (normalizada)
 * @param callable|null $enviar doble de envío para las pruebas (ver mensaje_responder)
 * @return array{0:string, 1:string} [tipo de aviso, texto]
 */
function conversacion_responder(array $post, string $email, ?callable $enviar = null): array
{
    $id = (int)($post['mensaje_id'] ?? 0);
    try {
        $m = $id > 0 ? q1('SELECT id, de, para FROM mensajes WHERE id = ?', [$id]) : null;
        if (!$m || $email === '' || ($m['de'] !== $email && $m['para'] !== $email)) {
            return ['error', 'Ese mensaje no es de esta conversación. Recargá la página y volvé a intentar.'];
        }
        mensaje_responder($id, (string)($post['texto'] ?? ''), null, $enviar);
        return ['success', 'Respuesta enviada.'];
    } catch (\PDOException $e) {
        // PDOException es una RuntimeException: va antes, para que el texto
        // de la base nunca llegue a la pantalla.
        log_error('mensaje.responder', $e->getMessage(), $e->getFile(), $e->getLine());
        return ['error', 'No se pudo enviar la respuesta por un error interno. Quedó registrado para revisarlo.'];
    } catch (\RuntimeException $e) {
        return ['error', 'No se pudo enviar: ' . $e->getMessage()];
    } catch (\Throwable $e) {
        log_error('mensaje.responder', $e->getMessage(), $e->getFile(), $e->getLine());
        return ['error', 'No se pudo enviar la respuesta por un error interno. Quedó registrado para revisarlo.'];
    }
}

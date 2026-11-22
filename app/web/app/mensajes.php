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
//    · pages/conversacion.php muestra el hilo y contesta (mensaje_responder);
//    · views/conversacion.php sanea y parte el HTML al mostrarlo.
//
//  Tablas: escribe y lee `mensajes` (migracion-004-mensajes.sql); lee
//  `contactos`, `envios`, `campanas`, `cuentas_correo` y `usuarios`.
//  Depende de: db.php, helpers.php (e, normalizar_email, email_valido,
//  recortar, log_app), envio.php (smtp_abrir, smtp_enviar, smtp_cerrar,
//  nuevo_message_id) y cuentas_correo.php (cuenta_a_config).
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
 * reinicia la marca de por dónde iba), el mismo correo no se duplica.
 *
 * Asignación: el contacto es el primero vivo con ese correo principal (no se
 * miran email2/email3) y el envío es el último 'enviado' a esa dirección, de
 * cualquier campaña. Si el contacto se carga después, el mensaje queda con
 * contacto_id NULL; igual aparece en la conversación porque esa se arma por
 * dirección.
 *
 * Trampas:
 *   · El chequeo previo y el INSERT no son atómicos: si dos lecturas de la
 *     casilla se pisan, la segunda choca con el UNIQUE `uq_msg` y lanza
 *     PDOException (que corta la vuelta de imap_revisar()).
 *   · `message_id` e `in_reply_to` son VARCHAR(255) y no se recortan acá: un
 *     encabezado más largo falla con MySQL en modo estricto.
 *
 * @param array $d de, de_nombre, para, asunto, texto, html, message_id,
 *                 in_reply_to, referencias, fecha ('Y-m-d H:i:s'), cuenta_correo_id
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
    if (qv('SELECT 1 FROM mensajes WHERE message_id = ?', [$messageId])) { return null; }

    $contacto = q1('SELECT id FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$de]);
    $envio    = q1("SELECT id, campana_id FROM envios WHERE email = ? AND estado = 'enviado'
                    ORDER BY enviado_en DESC LIMIT 1", [$de]);

    q('INSERT INTO mensajes (contacto_id, envio_id, campana_id, cuenta_correo_id, direccion,
                             de, de_nombre, para, asunto, cuerpo_texto, cuerpo_html,
                             message_id, in_reply_to, referencias, fecha)
       VALUES (?, ?, ?, ?, \'entrante\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$contacto['id'] ?? null, $envio['id'] ?? null, $envio['campana_id'] ?? null,
       ($d['cuenta_correo_id'] ?? null) ?: null,
       $de, recortar((string)($d['de_nombre'] ?? ''), 160), normalizar_email((string)($d['para'] ?? '')),
       recortar((string)($d['asunto'] ?? ''), 400),
       (string)($d['texto'] ?? ''), (string)($d['html'] ?? ''),
       $messageId, ($d['in_reply_to'] ?? null) ?: null, ($d['referencias'] ?? null) ?: null,
       $d['fecha'] ?? date('Y-m-d H:i:s')]);

    return (int) last_id();
}

/**
 * La conversación con una persona: todo lo que entró y lo que salió, en orden.
 *
 * Se busca por dirección de correo y no por id de contacto: quien responde una
 * campaña puede no estar cargado como contacto todavía, y su respuesta no se
 * puede perder por eso.
 *
 * Trae lo que la persona escribió (`de` = su correo) y lo que se le mandó
 * (`para` = su correo), con el nombre del usuario que contestó. Ordena de lo
 * más viejo a lo más nuevo y corta en $limite: en un hilo muy largo quedan
 * afuera los ÚLTIMOS, no los primeros. Las columnas `de` y `para` no tienen
 * índice: la consulta recorre la tabla entera.
 *
 * @return array<int,array> filas de `mensajes` + `usuario`; [] si el correo es vacío
 */
function conversacion_de(string $email, int $limite = 100): array
{
    $email = normalizar_email($email);
    if ($email === '') { return []; }
    return q('SELECT m.*, u.nombre AS usuario
              FROM mensajes m LEFT JOIN usuarios u ON u.id = m.usuario_id
              WHERE m.de = ? OR m.para = ?
              ORDER BY m.fecha, m.id
              LIMIT ' . max(1, $limite), [$email, $email])->fetchAll();
}

/**
 * Deja el HTML de un correo en condiciones de mostrarse.
 *
 * El correo lo escribió alguien de afuera y puede traer cualquier cosa. Se le
 * saca todo lo que ejecuta o carga: scripts, hojas de estilo que pisarían las
 * nuestras, marcos, formularios y los atributos `on*`. Después se muestra
 * dentro de un marco aislado, que es como lo hacen los clientes de correo:
 * así el mensaje se ve tal cual lo mandaron —con su firma, sus colores y su
 * diseño— sin poder tocar nada de la pantalla que lo rodea.
 *
 * Es una lista negra hecha con expresiones regulares, no un saneador
 * completo: no cubre todo (por ejemplo, esquemas escritos con entidades
 * HTML como `&#106;avascript:`, o `href=javascript:…` sin comillas). La
 * protección de verdad es el iframe con sandbox sin `allow-scripts` de la
 * vista; esto es una segunda capa. Las imágenes remotas no se tocan: se
 * cargan al abrir la conversación (el remitente puede saber que se leyó).
 *
 * @return string el HTML filtrado ('' si venía vacío)
 */
function mensaje_html_seguro(string $html): string
{
    if (trim($html) === '') { return ''; }

    // Fuera lo que ejecuta o trae contenido de otro lado.
    $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*/?>#i', '', $html) ?? $html;
    // Fuera los manejadores de eventos y los enlaces que ejecutan código.
    $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
    $html = preg_replace('#(href|src)\s*=\s*("|\')\s*(javascript|vbscript|data:text/html)[^"\']*\2#i', '$1="#"', $html) ?? $html;

    return $html;
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
            // Ojo: con el `||` alcanza con que `limpio` no sea vacío, así que
            // el strip_tags no decide nada. Si arriba del corte quedaron sólo
            // etiquetas sin texto (`<div><br></div>`), se muestra la parte de
            // arriba en blanco y lo escrito queda plegado en la cita.
            if (strip_tags($limpio) !== '' || $limpio !== '') {
                return ['limpio' => $limpio, 'citado' => trim(substr($html, $m[0][1]))];
            }
        }
    }
    return ['limpio' => $html, 'citado' => ''];
}

/**
 * Cuántos mensajes entrantes hay sin leer (para el aviso en pantalla).
 * Nota: hoy no la llama ninguna pantalla; el aviso de respuestas llega por
 * las notificaciones que crea marcar_respuesta().
 */
function mensajes_sin_leer(): int
{
    return (int) qv("SELECT COUNT(*) FROM mensajes WHERE direccion = 'entrante' AND leido_en IS NULL");
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
 * Elección de la casilla: la que recibió el original (si sigue activa); si
 * no, la de la campaña (sin mirar si está activa o dada de baja); si no, la
 * predeterminada activa. Sin ninguna, smtp_abrir() usa la configuración
 * general.
 *
 * El texto se manda tal cual en la parte de texto y escapado (e + nl2br) en
 * la de HTML: lo que escribe el usuario nunca se interpreta como HTML.
 *
 * @param int      $mensajeId id del mensaje al que se contesta (cualquiera, entrante o saliente)
 * @param string   $texto     lo que escribió el usuario
 * @param int|null $usuarioId quién contesta; null = el usuario de la sesión
 * @return int id del mensaje saliente guardado
 * @throws RuntimeException si el mensaje no existe, el texto está vacío, el
 *                          remitente no es válido o el envío falla
 */
function mensaje_responder(int $mensajeId, string $texto, ?int $usuarioId = null): int
{
    $orig = q1('SELECT * FROM mensajes WHERE id = ?', [$mensajeId]);
    if (!$orig)                        { throw new RuntimeException('El mensaje no existe.'); }
    if (trim($texto) === '')           { throw new RuntimeException('Escribí algo antes de mandar.'); }

    $para = normalizar_email((string)$orig['de']);
    if (!email_valido($para))          { throw new RuntimeException('El mensaje no tiene un remitente válido.'); }

    // La casilla por la que salió la campaña; si no se sabe, la predeterminada.
    $cuenta = null;
    if (!empty($orig['cuenta_correo_id'])) {
        $cuenta = q1('SELECT * FROM cuentas_correo WHERE id = ? AND activo = 1', [(int)$orig['cuenta_correo_id']]);
    }
    if (!$cuenta && !empty($orig['campana_id'])) {
        $cuenta = q1('SELECT cc.* FROM campanas c JOIN cuentas_correo cc ON cc.id = c.cuenta_correo_id
                      WHERE c.id = ?', [(int)$orig['campana_id']]);
    }
    if (!$cuenta) {
        $cuenta = q1('SELECT * FROM cuentas_correo WHERE activo = 1 ORDER BY predeterminada DESC, id LIMIT 1');
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
    $fp  = smtp_abrir($err, $cfg);
    if (!$fp) { throw new RuntimeException('No se pudo conectar al servidor de salida: ' . $err); }
    try {
        $estado = smtp_enviar($fp, [
            'para'        => $para,
            'para_nombre' => (string)($orig['de_nombre'] ?? ''),
            'asunto'      => $asunto,
            'texto'       => $textoFinal,
            'html'        => $html,
            'message_id'  => $messageId,
            'in_reply_to' => (string)$orig['message_id'],
            'referencias' => $refs,
        ], $err, $cfg);
    } finally {
        smtp_cerrar($fp);
    }
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

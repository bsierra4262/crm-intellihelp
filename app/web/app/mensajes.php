<?php
declare(strict_types=1);

// ============================================================
//  Conversaciones: leer y contestar sin salir del CRM.
//
//  Antes, cuando alguien respondía una campaña, lo único que se podía hacer
//  era marcar la alerta como leída. El sistema te avisaba de algo que después
//  no te podía mostrar: para leer qué había dicho la persona había que irse al
//  correo, y para contestarle también. Ahí se pierde el hilo: el que atiende
//  mañana no sabe qué se habló, porque quedó en la bandeja de alguien.
//
//  Con esto el mensaje se guarda, se lee acá y se contesta desde acá, y la
//  conversación queda pegada al contacto.
// ============================================================

/**
 * Guarda un correo que entró.
 *
 * El Message-ID es la clave única: si la casilla se relee (pasa cuando se
 * reinicia la marca de por dónde iba), el mismo correo no se duplica.
 *
 * @return int|null id del mensaje, o null si ya estaba guardado
 */
function mensaje_entrante(array $d): ?int
{
    $de = normalizar_email((string)($d['de'] ?? ''));
    if ($de === '') { return null; }

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
 */
function mensaje_partir_cita_html(string $html): array
{
    foreach (['/<div[^>]*class="[^"]*gmail_quote/i',
              '/<div[^>]*id="[^"]*(divRplyFwdMsg|appendonsend)/i',   // Outlook
              '/<blockquote/i'] as $re) {
        if (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
            $limpio = trim(substr($html, 0, $m[0][1]));
            if (strip_tags($limpio) !== '' || $limpio !== '') {
                return ['limpio' => $limpio, 'citado' => trim(substr($html, $m[0][1]))];
            }
        }
    }
    return ['limpio' => $html, 'citado' => ''];
}

/** Cuántos mensajes entrantes hay sin leer (para el aviso en pantalla). */
function mensajes_sin_leer(): int
{
    return (int) qv("SELECT COUNT(*) FROM mensajes WHERE direccion = 'entrante' AND leido_en IS NULL");
}

/** Marca como leída la conversación con alguien. */
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

    $asunto = (string)$orig['asunto'];
    if (stripos($asunto, 're:') !== 0) { $asunto = 'Re: ' . $asunto; }

    // El hilo: se encadena el original y todo lo que ya venía encadenado.
    $refs = trim((string)($orig['referencias'] ?? '') . ' ' . (string)$orig['message_id']);

    $messageId = nuevo_message_id();

    // La firma de la casilla, si tiene. Es lo que hace que la respuesta salga
    // igual que desde Gmail: con el nombre, el cargo y el teléfono, siempre los
    // mismos. Un correo comercial sin firma se nota, y se nota justo cuando
    // del otro lado están decidiendo si trabajar con nosotros.
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
    // `last_id()` devuelve la última de la conexión, no la de esta tabla: leído
    // al final, el id que volvía era el del registro de auditoría y quien
    // llamaba se quedaba con un mensaje que no existía.
    $nuevo = (int) last_id();

    // Contestada es leída: si no, la conversación queda marcada como pendiente
    // para siempre después de haberla atendido.
    conversacion_marcar_leida($para);

    log_app('mensaje.responder', ['entidad' => 'contacto', 'entidad_id' => (int)($orig['contacto_id'] ?? 0),
        'detalle' => 'Respuesta enviada a ' . $para]);

    return $nuevo;
}

<?php
declare(strict_types=1);

// ============================================================
//  Ruta: /px?t=<token del envío>  (pública; no pertenece a ningún módulo).
//  La imagen invisible que campana_armar_mensaje() pega al final de cada
//  correo de campaña. La primera vez que se pide llena `envios.abierto_en`
//  y registra un evento 'apertura' en `envio_eventos`; las siguientes no
//  hacen nada (sólo cuenta la primera apertura).
//  Límites del dato: los clientes que bloquean imágenes no la piden (la
//  apertura no se cuenta) y los que las descargan solos al recibir (Apple
//  Mail con protección de privacidad, algunos antivirus corporativos) la
//  piden aunque nadie haya abierto el correo: las aperturas son un dato
//  aproximado, inflado por eso, y no hay forma de distinguirlas desde acá.
// ============================================================
//
// Píxel de apertura. Devuelve siempre un GIF transparente de 1×1, pase lo que
// pase: si fallara, el destinatario vería un ícono de imagen rota en el correo.
// Sólo un token del largo exacto (32) llega a la base: cualquier otra cosa
// se contesta con el GIF sin consultar nada. La marca se pone con un UPDATE
// condicional (sólo si estaba vacía), así que pedidos repetidos o
// simultáneos no duplican el evento. La IP se guarda como hash, no en claro.
$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if (strlen($token) === 32) {
    try {
        $envio = q1('SELECT id, abierto_en FROM envios WHERE token = ?', [$token]);
        if ($envio && empty($envio['abierto_en'])
            && q('UPDATE envios SET abierto_en = NOW() WHERE id = ? AND abierto_en IS NULL', [(int)$envio['id']])->rowCount() === 1) {
            q("INSERT INTO envio_eventos (envio_id, tipo, ip) VALUES (?, 'apertura', ?)",
              [(int)$envio['id'], campana_ip_registro()]);
        }
    } catch (\Throwable $e) {
        // Silencio: el píxel nunca debe romper la vista del correo.
    }
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
// GIF de 1×1 transparente, embebido para no leer ningún archivo.
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;

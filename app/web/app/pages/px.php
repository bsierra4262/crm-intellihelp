<?php
declare(strict_types=1);

// Píxel de apertura. Devuelve siempre un GIF transparente de 1×1, pase lo que
// pase: si fallara, el destinatario vería un ícono de imagen rota en el correo.
$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if ($token !== '') {
    try {
        $envio = q1('SELECT id, abierto_en FROM envios WHERE token = ?', [$token]);
        if ($envio && empty($envio['abierto_en'])) {
            q('UPDATE envios SET abierto_en = NOW() WHERE id = ?', [(int)$envio['id']]);
            q("INSERT INTO envio_eventos (envio_id, tipo, ip) VALUES (?, 'apertura', ?)",
              [(int)$envio['id'], client_ip()]);
        }
    } catch (\Throwable $e) {
        // Silencio: el píxel nunca debe romper la vista del correo.
    }
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;

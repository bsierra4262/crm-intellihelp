<?php
declare(strict_types=1);

// Sirve una imagen de las campañas. Es PÚBLICA a propósito: el correo la pide
// desde el cliente del destinatario, que obviamente no tiene sesión.
$archivo = preg_replace('/[^a-f0-9]+\.(jpg|png|gif|webp)$/i', '$0', (string)($_GET['a'] ?? ''));
if (!preg_match('/^[a-f0-9]{20}\.(jpg|png|gif|webp)$/i', (string)$archivo)) {
    http_response_code(404);
    exit;
}

$im = q1('SELECT * FROM imagenes WHERE archivo = ?', [$archivo]);
$ruta = rtrim(STORAGE, '/') . '/imagenes/' . $archivo;
if (!$im || !is_file($ruta)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $im['mime']);
header('Content-Length: ' . filesize($ruta));
// Caché larga: el archivo tiene nombre aleatorio, así que si cambia la imagen
// cambia la URL. No hay riesgo de servir una versión vieja.
header('Cache-Control: public, max-age=31536000, immutable');
readfile($ruta);
exit;

<?php
declare(strict_types=1);

// ============================================================
//  Ruta: GET /img?a=<20 hex>.<ext> (pública). Imágenes de las campañas.
//
//  Lee la fila de `imagenes` (para saber que existe y su tipo) y sirve el
//  archivo desde STORAGE/imagenes/, fuera del web root. Las sube la pantalla
//  `imagenes` (administración).
// ============================================================

// Sirve una imagen de las campañas. Es PÚBLICA a propósito: el correo la pide
// desde el cliente del destinatario, que obviamente no tiene sesión.
// Este reemplazo no cambia nada (pone cada coincidencia en su lugar con $0):
// el control real es el preg_match de abajo, que exige exactamente 20 hex y
// una extensión de imagen, y con eso no hay forma de salirse de la carpeta.
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

// El tipo sale de la base (lo guardó la subida), no de lo que pida la URL.
header('Content-Type: ' . $im['mime']);
header('Content-Length: ' . filesize($ruta));
// Caché larga: el archivo tiene nombre aleatorio, así que si cambia la imagen
// cambia la URL. No hay riesgo de servir una versión vieja.
header('Cache-Control: public, max-age=31536000, immutable');
readfile($ruta);
exit;

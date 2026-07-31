<?php
declare(strict_types=1);
require_login();

// Sirve un archivo adjunto. Los archivos están fuera del web root a propósito:
// si estuvieran adentro, cualquiera con la dirección podría bajarse un
// presupuesto sin tener sesión.

$a = q1('SELECT * FROM adjuntos WHERE id = ? AND eliminado_en IS NULL', [(int)($_GET['id'] ?? 0)]);
if (!$a) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Ese archivo ya no está',
        'mensaje' => 'Puede que lo hayan borrado.'], 'No encontrado');
    exit;
}

$ruta = adjuntos_dir() . '/' . $a['ruta'];
if (!is_file($ruta)) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'No encontramos el archivo',
        'mensaje' => 'Está registrado pero no está en el disco. Volvé a subirlo.'], 'No encontrado');
    exit;
}

log_app($a['entidad'] . '.adjunto_ver', ['entidad' => $a['entidad'],
    'entidad_id' => (int)$a['entidad_id'], 'detalle' => 'Descargó ' . $a['nombre']]);

// Se fuerza la descarga y un tipo genérico: si el servidor devolviera el tipo
// declarado por quien subió, un archivo HTML se ejecutaría en el navegador con
// la sesión de quien lo abre.
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string)$a['nombre']) . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
exit;

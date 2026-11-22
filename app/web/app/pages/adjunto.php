<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: GET /adjunto?id=N (requiere sesión; módulo Adjuntos).
//
//  Sirve un archivo adjunto. Los archivos están fuera del web root a
//  propósito: si estuvieran adentro, cualquiera con la dirección podría
//  bajarse un presupuesto sin tener sesión.
//
//  La base se comparte en el equipo: cualquier usuario con sesión baja los
//  adjuntos de cualquier ficha. Lo que sí se controla es que el registro del
//  que cuelga exista, no esté en la papelera y sea de un módulo encendido
//  (un administrador lo puede bajar igual aunque el registro esté en la
//  papelera, para revisarlo antes de restaurarlo).
//  Cada descarga queda en la auditoría.
// ============================================================

$a = q1('SELECT * FROM adjuntos WHERE id = ? AND eliminado_en IS NULL', [(int)($_GET['id'] ?? 0)]);
if (!$a) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Ese archivo ya no está',
        'mensaje' => 'Puede que lo hayan borrado.'], 'No encontrado');
    exit;
}

// El registro del que cuelga: sin él (o con su módulo apagado), el archivo no
// se ofrece. Una entidad fuera de la lista blanca tampoco.
$entA = (string)$a['entidad'];
$vivo = entidad_valida($entA) && entidad_habilitada($entA)
     && (registro_vivo($entA, (int)$a['entidad_id']) !== null
         || (auth_is_admin() && entidad_tabla($entA) !== null));
if (!$vivo) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Ese archivo no está disponible',
        'mensaje' => 'La ficha a la que pertenece ya no existe o está en la papelera.'], 'No encontrado');
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
// Nombre en ASCII para los navegadores viejos y en UTF-8 (filename*, RFC 6266)
// para que los acentos lleguen bien. Ver descarga_disposicion() en helpers.php.
header('Content-Disposition: ' . descarga_disposicion((string)$a['nombre']));
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
exit;

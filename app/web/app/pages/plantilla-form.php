<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$p  = $id ? plantilla_get($id) : ['activo' => 1];
if ($id && !$p) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Plantilla inexistente', 'mensaje' => ''], 'No encontrado');
    exit;
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    if (trim((string)($d['nombre'] ?? '')) === '')      { $errores['nombre'] = 'Poné un nombre.'; }
    if (trim((string)($d['asunto'] ?? '')) === '')      { $errores['asunto'] = 'Poné un asunto.'; }
    if (trim((string)($d['cuerpo_html'] ?? '')) === '') { $errores['cuerpo_html'] = 'El cuerpo no puede quedar vacío.'; }
    if (!$errores) {
        $nuevoId = plantilla_guardar($d, $id ?: null);
        flash($id ? 'Plantilla actualizada.' : 'Plantilla creada.', 'success');
        redirect('plantilla-form?id=' . $nuevoId);
    }
    $p = array_merge($p ?? [], $d);
}

render('plantilla-form', ['p' => $p, 'id' => $id, 'errores' => $errores],
       $id ? 'Editar plantilla' : 'Nueva plantilla');

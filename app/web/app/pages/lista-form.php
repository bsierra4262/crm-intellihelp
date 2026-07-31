<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$l  = $id ? lista_get($id) : null;
if ($id && !$l) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Lista inexistente', 'mensaje' => ''], 'No encontrado');
    exit;
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    if (trim((string)($d['nombre'] ?? '')) === '') { $errores['nombre'] = 'Poné un nombre.'; }
    if (!$errores) {
        $nuevoId = lista_guardar($d, $id ?: null);
        flash($id ? 'Lista actualizada.' : 'Lista creada. Ahora cargala con contactos.', 'success');
        redirect('lista-ver?id=' . $nuevoId);
    }
    $l = array_merge($l ?? [], $d);
}

render('lista-form', ['l' => $l, 'id' => $id, 'errores' => $errores,
                      'categorias' => categorias_listar()],
       $id ? 'Editar lista' : 'Nueva lista');

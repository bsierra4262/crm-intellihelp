<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$p  = $id ? posible_get($id) : null;
if ($id && !$p) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Posible cliente inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    if (trim((string)($d['apellido'] ?? '')) === '') { $errores['apellido'] = 'El apellido es obligatorio.'; }
    if (trim((string)($d['empresa']  ?? '')) === '') { $errores['empresa']  = 'La empresa es obligatoria.'; }
    if (trim((string)($d['email'] ?? '')) !== '' && !email_valido($d['email'])) {
        $errores['email'] = 'La dirección de correo no es válida.';
    }
    if (!$errores) {
        $nuevoId = posible_guardar($d, $id ?: null);
        flash($id ? 'Posible cliente actualizado.' : 'Posible cliente creado.', 'success');
        redirect('posible-ver?id=' . $nuevoId);
    }
    $p = array_merge($p ?? [], $d);
}

render('posible-form', [
    'p'        => $p,
    'id'       => $id,
    'errores'  => $errores,
    'usuarios' => users_activos(),
], $id ? 'Editar posible cliente' : 'Nuevo posible cliente');

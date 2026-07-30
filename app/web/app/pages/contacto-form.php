<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? contacto_get($id) : null;
if ($id && !$c) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Contacto inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;

    if (trim((string)($d['apellido'] ?? '')) === '') {
        $errores['apellido'] = 'El apellido es obligatorio.';
    }
    foreach (['email' => 'Correo', 'email2' => 'Correo secundario', 'email3' => 'Correo terciario'] as $k => $et) {
        if (trim((string)($d[$k] ?? '')) !== '' && !email_valido($d[$k])) {
            $errores[$k] = "$et: la dirección no es válida.";
        }
    }
    // Alta de empresa al vuelo: si escribieron un nombre nuevo, se crea la
    // cuenta en el momento en vez de obligar a salir del formulario.
    if (empty($d['cuenta_id']) && trim((string)($d['cuenta_nueva'] ?? '')) !== '') {
        $d['cuenta_id'] = cuenta_asegurar(trim((string)$d['cuenta_nueva']),
            ['propietario_id' => $d['propietario_id'] ?: null]);
    }

    if (!$errores) {
        $nuevoId = contacto_guardar($d, $id ?: null);
        flash($id ? 'Contacto actualizado.' : 'Contacto creado.', 'success');
        redirect('contacto-ver?id=' . $nuevoId);
    }
    $c = array_merge($c ?? [], $d);
}

render('contacto-form', [
    'c'        => $c,
    'id'       => $id,
    'errores'  => $errores,
    'usuarios' => users_activos(),
    'cuentas'  => q('SELECT id, nombre FROM cuentas WHERE eliminado_en IS NULL ORDER BY nombre LIMIT 3000')->fetchAll(),
], $id ? 'Editar contacto' : 'Nuevo contacto');

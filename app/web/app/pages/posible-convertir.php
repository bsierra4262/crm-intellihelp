<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$p  = $id ? posible_get($id) : null;
if (!$p) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Posible cliente inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}
if ($p['convertido_en']) {
    flash('Este posible cliente ya se había convertido.', 'info');
    redirect('posible-ver?id=' . $id);
}

$error = null;

if (is_post()) {
    verify_csrf();
    try {
        $r = posible_convertir($id, [
            'crear_negocio'   => !empty($_POST['crear_negocio']),
            'negocio_nombre'  => $_POST['negocio_nombre'] ?? '',
            'negocio_importe' => str_replace(',', '.', (string)($_POST['negocio_importe'] ?? '0')),
            'negocio_moneda'  => $_POST['negocio_moneda'] ?? 'ARS',
            'negocio_cierre'  => $_POST['negocio_cierre'] ?? null,
        ]);
        flash('Convertido: se creó la empresa y el contacto' . ($r['negocio_id'] ? ' y el negocio' : '') . '.', 'success');
        redirect($r['negocio_id'] ? 'negocio-ver?id=' . $r['negocio_id'] : 'contacto-ver?id=' . $r['contacto_id']);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        log_app('posible.convertir_error', ['tipo' => 'error', 'entidad' => 'posible_cliente',
            'entidad_id' => $id, 'detalle' => $e->getMessage()]);
    }
}

// ¿La empresa y el contacto ya existen? Se avisa para que nadie se sorprenda
// de que no se dupliquen.
$cuentaExistente   = cuenta_por_nombre((string)$p['empresa']);
$contactoExistente = $p['email']
    ? q1('SELECT id, nombre_completo FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$p['email']])
    : null;

render('posible-convertir', compact('p', 'error', 'cuentaExistente', 'contactoExistente'), 'Convertir posible cliente');

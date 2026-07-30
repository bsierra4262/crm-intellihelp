<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$n  = $id ? negocio_get($id) : null;
if ($id && !$n) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Negocio inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

// Alta desde la ficha de un contacto o de una empresa: vienen precargados.
if (!$id) {
    $n = [
        'cuenta_id'    => $_GET['cuenta_id'] ?? '',
        'contacto_id'  => $_GET['contacto_id'] ?? '',
        'fecha_cierre' => date('Y-m-d', strtotime('+30 days')),
        'moneda'       => 'ARS',
    ];
    if (!empty($n['contacto_id']) && empty($n['cuenta_id'])) {
        $ct = contacto_get((int)$n['contacto_id']);
        if ($ct) {
            $n['cuenta_id'] = $ct['cuenta_id'];
            $n['nombre']    = $ct['cuenta'] ?: $ct['nombre_completo'];
        }
    } elseif (!empty($n['cuenta_id'])) {
        $cu = cuenta_get((int)$n['cuenta_id']);
        if ($cu) { $n['nombre'] = $cu['nombre']; }
    }
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    if (trim((string)($d['nombre'] ?? '')) === '') { $errores['nombre'] = 'Poné un nombre al negocio.'; }
    if (!$errores) {
        $nuevoId = negocio_guardar($d, $id ?: null);
        flash($id ? 'Negocio actualizado.' : 'Negocio creado.', 'success');
        redirect('negocio-ver?id=' . $nuevoId);
    }
    $n = array_merge($n ?? [], $d);
}

$emb = embudo_predeterminado();

render('negocio-form', [
    'n'         => $n,
    'id'        => $id,
    'errores'   => $errores,
    'usuarios'  => users_activos(),
    'etapas'    => $emb ? etapas_de((int)$emb['id']) : [],
    'cuentas'   => q('SELECT id, nombre FROM cuentas WHERE eliminado_en IS NULL ORDER BY nombre LIMIT 3000')->fetchAll(),
    'contactos' => q('SELECT id, nombre_completo, cuenta_id FROM contactos WHERE eliminado_en IS NULL
                      ORDER BY apellido, nombre LIMIT 3000')->fetchAll(),
    'campanas'  => q('SELECT id, nombre FROM campanas WHERE eliminado_en IS NULL ORDER BY creado_en DESC LIMIT 200')->fetchAll(),
], $id ? 'Editar negocio' : 'Nuevo negocio');

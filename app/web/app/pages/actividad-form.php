<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$a  = $id ? actividad_get($id) : null;
if ($id && !$a) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Actividad inexistente',
        'mensaje' => 'Puede que la hayan eliminado.'], 'No encontrado');
    exit;
}

// Alta desde la ficha de un registro: viene precargado el vínculo.
if (!$id) {
    $a = [
        'tipo'               => $_GET['tipo'] ?? 'tarea',
        'contacto_id'        => $_GET['contacto_id'] ?? '',
        'posible_cliente_id' => $_GET['posible_cliente_id'] ?? '',
        'cuenta_id'          => $_GET['cuenta_id'] ?? '',
        'negocio_id'         => $_GET['negocio_id'] ?? '',
        'vence_el'           => date('Y-m-d\TH:i', strtotime('+1 day 10:00')),
        'inicia_el'          => date('Y-m-d\TH:i', strtotime('+1 day 10:00')),
        'prioridad'          => 'Normal',
        'estado'             => 'No iniciada',
        'propietario_id'     => current_user_id(),
    ];
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    if (trim((string)($d['asunto'] ?? '')) === '') { $errores['asunto'] = 'Poné un asunto.'; }
    // Los input datetime-local mandan "Y-m-dTH:i": la base necesita espacio.
    foreach (['vence_el', 'inicia_el', 'termina_el'] as $k) {
        if (!empty($d[$k])) { $d[$k] = str_replace('T', ' ', (string)$d[$k]) . ':00'; }
    }
    if (!$errores) {
        $nuevoId = actividad_guardar($d, $id ?: null);
        flash($id ? 'Actividad actualizada.' : 'Actividad creada.', 'success');
        $volver = $d['volver'] ?? '';
        redirect($volver !== '' ? $volver : 'actividades');
    }
    $a = array_merge($a ?? [], $d);
}

render('actividad-form', [
    'a'         => $a,
    'id'        => $id,
    'errores'   => $errores,
    'usuarios'  => users_activos(),
    'contactos' => q('SELECT id, nombre_completo FROM contactos WHERE eliminado_en IS NULL
                      ORDER BY apellido, nombre LIMIT 3000')->fetchAll(),
    'cuentas'   => q('SELECT id, nombre FROM cuentas WHERE eliminado_en IS NULL ORDER BY nombre LIMIT 3000')->fetchAll(),
    'negocios'  => q('SELECT id, nombre FROM negocios WHERE eliminado_en IS NULL ORDER BY creado_en DESC LIMIT 1000')->fetchAll(),
], $id ? 'Editar actividad' : 'Nueva actividad');

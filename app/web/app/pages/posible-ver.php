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

// Cambio rápido de estado desde la ficha: es la acción más frecuente del día.
if (is_post() && ($_POST['accion'] ?? '') === 'estado') {
    verify_csrf();
    $estado = (string)($_POST['estado'] ?? '');
    if (in_array($estado, estados_posible_cliente(), true)) {
        q('UPDATE posibles_clientes SET estado = ?, ultima_actividad_en = NOW() WHERE id = ?', [$estado, $id]);
        log_app('posible.estado', ['entidad' => 'posible_cliente', 'entidad_id' => $id,
            'detalle' => 'Estado: «' . $p['estado'] . '» → «' . $estado . '»']);
        flash('Estado actualizado.', 'success');
    }
    redirect('posible-ver?id=' . $id);
}

render('posible-ver', [
    'p'           => $p,
    'notas'       => notas_de('posible_cliente', $id),
    'actividades' => actividades_de('posible_cliente_id', $id),
    'linea'       => timeline('posible_cliente', $id),
], trim(($p['nombre'] ?? '') . ' ' . $p['apellido']));

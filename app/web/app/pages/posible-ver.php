<?php
declare(strict_types=1);

// ============================================================
//  Página `posible-ver?id=N`: la ficha de un posible cliente.
//
//  GET dibuja views/posible-ver.php con las notas, actividades y la línea de
//  tiempo de auditoría. Único POST: `accion=estado`, el cambio rápido de
//  estado desde la ficha.
//
//  Tablas: lee `posibles_clientes`, `notas`, `actividades`, `actividad`;
//  el POST escribe `posibles_clientes.estado/ultima_actividad_en`.
//  Módulo: `posibles`.
//  Permisos: cualquier usuario con sesión.
//
//  Las actividades se consultan siempre, aunque el módulo de actividades
//  esté apagado (la vista tampoco mira si está encendido).
// ============================================================

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
    // Sólo un estado de la lista; uno desconocido se ignora sin aviso. No se
    // impide cambiar el estado de un posible cliente ya convertido.
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

<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$n  = $id ? negocio_get($id) : null;
if (!$n) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Negocio inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

render('negocio-ver', [
    'n'           => $n,
    'etapas'      => etapas_de((int)$n['embudo_id']),
    'historial'   => q('SELECT ne.*, e.nombre AS etapa, e.color, u.nombre AS usuario
                        FROM negocio_etapas ne
                        JOIN etapas e ON e.id = ne.etapa_id
                        LEFT JOIN usuarios u ON u.id = ne.usuario_id
                        WHERE ne.negocio_id = ? ORDER BY ne.desde', [$id])->fetchAll(),
    'notas'       => notas_de('negocio', $id),
    'actividades' => actividades_de('negocio_id', $id),
    'linea'       => timeline('negocio', $id),
], $n['nombre']);

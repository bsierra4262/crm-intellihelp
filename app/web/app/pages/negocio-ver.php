<?php
declare(strict_types=1);
// ============================================================
//  Ruta `negocio-ver?id=N`: la ficha de un negocio.
//
//  Muestra importe, ponderado, cierre estimado y etapa; el formulario «Mover
//  de etapa» (que postea a negocio-etapa con las etapas del embudo del
//  propio negocio), el recorrido por el embudo (negocio_etapas), notas,
//  actividades, etiquetas, adjuntos y la línea de tiempo del log.
//  404 si no existe o está en la papelera.
//
//  Módulo «negocios». Sólo lectura: no escribe nada.
//  Vista: views/negocio-ver.php.
// ============================================================
require_login();

$id = (int)($_GET['id'] ?? 0);
$n  = $id ? negocio_get($id) : null;
if (!$n) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Negocio inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

// El historial usa JOIN (no LEFT JOIN) con etapas: si una etapa se borró,
// sus tramos dejan de mostrarse (negocio_etapas no tiene clave foránea a
// etapas).
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

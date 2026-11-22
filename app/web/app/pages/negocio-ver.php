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

// El historial usa LEFT JOIN con etapas: negocio_etapas no tiene clave foránea
// a etapas, y el tramo de una etapa que se borró se sigue mostrando como
// «Etapa eliminada».
// Las etapas para «Mover» incluyen la actual aunque esté desactivada: si no,
// el desplegable marcaría otra y un clic en «Mover» la cambiaría.
render('negocio-ver', [
    'n'           => $n,
    'etapas'      => etapas_para_negocio((int)$n['embudo_id'], (int)$n['etapa_id']),
    'historial'   => q("SELECT ne.*, COALESCE(e.nombre, 'Etapa eliminada') AS etapa,
                               COALESCE(e.color, '#8a8597') AS color, u.nombre AS usuario
                        FROM negocio_etapas ne
                        LEFT JOIN etapas e ON e.id = ne.etapa_id
                        LEFT JOIN usuarios u ON u.id = ne.usuario_id
                        WHERE ne.negocio_id = ? ORDER BY ne.desde, ne.id", [$id])->fetchAll(),
    'notas'       => notas_de('negocio', $id),
    'actividades' => actividades_de('negocio_id', $id),
    'linea'       => timeline('negocio', $id),
], $n['nombre']);

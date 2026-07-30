<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? contacto_get($id) : null;
if (!$c) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Contacto inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

render('contacto-ver', [
    'c'           => $c,
    'notas'       => notas_de('contacto', $id),
    'actividades' => actividades_de('contacto_id', $id),
    'negocios'    => q('SELECT n.*, e.nombre AS etapa, e.tipo AS etapa_tipo, e.color
                        FROM negocios n JOIN etapas e ON e.id = n.etapa_id
                        WHERE n.contacto_id = ? AND n.eliminado_en IS NULL
                        ORDER BY n.creado_en DESC', [$id])->fetchAll(),
    'envios'      => q('SELECT en.*, ca.nombre AS campana
                        FROM envios en JOIN campanas ca ON ca.id = en.campana_id
                        WHERE en.contacto_id = ? ORDER BY en.id DESC LIMIT 20', [$id])->fetchAll(),
    'linea'       => timeline('contacto', $id),
], $c['nombre_completo']);

<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? cuenta_get($id) : null;
if (!$c) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Empresa inexistente',
        'mensaje' => 'Puede que la hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

// Lo que no está en marcha no se muestra y tampoco se consulta.
$verNegocios    = ruta_habilitada('negocios');
$verActividades = ruta_habilitada('actividades');
$verAdjuntos    = ruta_habilitada('adjunto');

render('cuenta-ver', [
    'verNegocios'    => $verNegocios,
    'verActividades' => $verActividades,
    'verAdjuntos'    => $verAdjuntos,
    'c'           => $c,
    'contactos'   => q('SELECT * FROM contactos WHERE cuenta_id = ? AND eliminado_en IS NULL
                        ORDER BY apellido, nombre', [$id])->fetchAll(),
    'negocios'    => q('SELECT n.*, e.nombre AS etapa, e.color, e.tipo AS etapa_tipo
                        FROM negocios n JOIN etapas e ON e.id = n.etapa_id
                        WHERE n.cuenta_id = ? AND n.eliminado_en IS NULL
                        ORDER BY n.creado_en DESC', [$id])->fetchAll(),
    'notas'       => notas_de('cuenta', $id),
    'actividades' => actividades_de('cuenta_id', $id),
    'linea'       => timeline('cuenta', $id),
], $c['nombre']);

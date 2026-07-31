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

// Volver a habilitar a alguien que se había dado de baja. La pantalla de baja
// le promete al destinatario que puede pedir el alta otra vez, así que tiene
// que haber una forma de hacerlo desde acá.
if (is_post() && ($_POST['accion'] ?? '') === 'reactivar') {
    verify_csrf();
    require_admin();
    $ok = reactivar_suscripcion((string)$c['email']);
    flash($ok
        ? 'Listo: ' . $c['email'] . ' vuelve a recibir campañas.'
        : 'No figuraba dado de baja, así que no hizo falta cambiar nada.',
        $ok ? 'success' : 'info');
    redirect('contacto-ver?id=' . $id);
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

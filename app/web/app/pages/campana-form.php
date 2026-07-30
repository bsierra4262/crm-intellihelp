<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? campana_get($id) : null;
if ($id && !$c) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Campaña inexistente', 'mensaje' => ''], 'No encontrado');
    exit;
}
if (!$id) {
    $smtp = smtp_cfg();
    $u    = auth_user();
    $c = [
        'pausa_min_seg'    => (int) setting_get('campana_pausa_min', '25'),
        'pausa_max_seg'    => (int) setting_get('campana_pausa_max', '45'),
        'limite_diario'    => (int) setting_get('campana_limite_diario', '300'),
        'remitente_nombre' => $u['nombre'] ?? '',
        'remitente_email'  => $smtp['from'] ?? $smtp['user'] ?? '',
        'responder_a'      => $u['email'] ?? '',
        'enviar_desde'     => '09:00',
        'enviar_hasta'     => '18:30',
    ];
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    if (trim((string)($d['nombre'] ?? '')) === '') { $errores['nombre'] = 'Poné un nombre a la campaña.'; }
    if (empty($d['lista_id']))     { $errores['lista_id']     = 'Elegí una lista de destinatarios.'; }
    if (empty($d['plantilla_id'])) { $errores['plantilla_id'] = 'Elegí una plantilla.'; }
    if (!$errores) {
        $nuevoId = campana_guardar($d, $id ?: null);
        flash($id ? 'Campaña actualizada.' : 'Campaña creada. Revisá los destinatarios y arrancá cuando quieras.', 'success');
        redirect('campana-ver?id=' . $nuevoId);
    }
    $c = array_merge($c ?? [], $d);
}

render('campana-form', [
    'c'          => $c,
    'id'         => $id,
    'errores'    => $errores,
    'usuarios'   => users_activos(),
    'listas'     => listas_listar(),
    'plantillas' => plantillas_listar(true),
], $id ? 'Editar campaña' : 'Nueva campaña');

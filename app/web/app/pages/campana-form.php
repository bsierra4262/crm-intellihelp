<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: /campana-form[?id=N]  (con sesión; módulo «campanas»).
//  Alta y edición de una campaña. GET muestra el formulario
//  (views/campana-form.php); POST valida lo mínimo (nombre, lista y
//  plantilla), guarda con campana_guardar() y redirige a campana-ver.
//  Una campaña nueva arranca con las pausas y el límite de Ajustes
//  (`campana_pausa_min`, `campana_pausa_max`, `campana_limite_diario`), el
//  usuario actual como remitente y responsable, y la franja 09:00 a 18:30.
//  Cualquier usuario con sesión puede editar cualquier campaña, también una
//  que ya está enviando: los cambios valen desde el correo siguiente.
// ============================================================

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? campana_get($id) : null;
// Lo de otro usuario no se muestra (salvo al administrador): como si no existiera.
if ($c && !campana_visible($c)) { $c = null; }
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
        // Ojo: precargar el correo del usuario en «Responder a» es justo lo
        // que el paso «Las respuestas se van a detectar» marca como problema
        // cuando ese correo no es la casilla que lee el sistema.
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
    // Con errores se vuelve a mostrar el formulario con lo que se escribió.
    $c = array_merge($c ?? [], $d);
}

render('campana-form', [
    'c'          => $c,
    'id'         => $id,
    'errores'    => $errores,
    'usuarios'   => users_activos(),
    'listas'     => listas_listar(),
    'plantillas' => plantillas_listar(true),
    'cuentas'    => cuentas_correo_listar(true),
    'categorias' => categorias_listar(),
], $id ? 'Editar campaña' : 'Nueva campaña');

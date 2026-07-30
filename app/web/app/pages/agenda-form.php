<?php
declare(strict_types=1);
require_login();

$id  = (int)($_GET['id'] ?? 0);
$uid = (int)current_user_id();
$t   = $id ? agenda_tipo_get($id) : null;

if ($id && (!$t || (int)$t['usuario_id'] !== $uid)) {
    http_response_code(403);
    render('error', ['codigo' => 403, 'titulo' => 'No es tu agenda',
        'mensaje' => 'Sólo podés editar los tipos de reunión que creaste vos.'], 'Sin acceso');
    exit;
}
if (!$id) {
    $t = ['duracion_min' => 30, 'margen_min' => 10, 'aviso_min_horas' => 4,
          'ventana_dias' => 30, 'con_meet' => 1, 'activo' => 1];
}

$horarios = $id ? agenda_horarios_de($id) : [];
$porDia   = [];
foreach ($horarios as $h) {
    $porDia[(int)$h['dia']] = [substr((string)$h['desde'], 0, 5), substr((string)$h['hasta'], 0, 5)];
}
if (!$id) {
    // Por defecto, lunes a viernes de 9 a 18.
    foreach ([1, 2, 3, 4, 5] as $d) { $porDia[$d] = ['09:00', '18:00']; }
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    $d['usuario_id'] = $uid;
    if (trim((string)($d['nombre'] ?? '')) === '') { $errores['nombre'] = 'Poné un nombre.'; }
    if (empty($d['dias'])) { $errores['dias'] = 'Marcá al menos un día de la semana.'; }
    if (!$errores) {
        $nuevoId = agenda_tipo_guardar($d, $id ?: null);
        flash($id ? 'Tipo de reunión actualizado.' : 'Tipo de reunión creado. Ya podés compartir el enlace.', 'success');
        redirect('agenda');
    }
    $t = array_merge($t ?? [], $d);
    $porDia = [];
    foreach ((array)($d['dias'] ?? []) as $dia) {
        $porDia[(int)$dia] = [$d['desde'][$dia] ?? '09:00', $d['hasta'][$dia] ?? '18:00'];
    }
}

render('agenda-form', compact('t', 'id', 'errores', 'porDia'),
       $id ? 'Editar tipo de reunión' : 'Nuevo tipo de reunión');

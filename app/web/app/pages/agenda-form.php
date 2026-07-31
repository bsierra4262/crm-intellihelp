<?php
declare(strict_types=1);
require_login();

$id  = (int)($_GET['id'] ?? 0);
$uid = (int)current_user_id();
$t   = $id ? agenda_tipo_get($id) : null;

if ($id && (!$t || ((int)$t['usuario_id'] !== $uid && !auth_is_admin()))) {
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

// Quién es el dueño del calendario donde caen las reuniones. Hasta ahora esto
// no se veía en ningún lado: la reunión se creaba en el calendario de quien
// había armado el tipo, y no había forma de saber cuál era ni de comprobar que
// esa cuenta tuviera Google conectado. Si no lo tiene, la reserva se toma igual
// y el evento no se crea: el cliente recibe su enlace y la empresa no se entera.
$duenio   = (int)($t['usuario_id'] ?? $uid);
$duenioU  = q1('SELECT id, nombre, email FROM usuarios WHERE id = ?', [$duenio]) ?: [];
$conectado = $duenio ? google_conectado($duenio) : false;
$candidatos = auth_is_admin() ? users_activos() : [];

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    // Sólo un administrador puede poner el tipo de reunión sobre el calendario
    // de otra persona. Para el resto, siempre el propio: si no, bastaría con
    // cambiar el número en el formulario para agendar sobre el calendario ajeno.
    $pedido = (int)($_POST['usuario_id'] ?? 0);
    $d['usuario_id'] = (auth_is_admin() && $pedido > 0) ? $pedido : $uid;
    if (trim((string)($d['nombre'] ?? '')) === '') { $errores['nombre'] = 'Poné un nombre.'; }
    if (empty($d['dias'])) { $errores['dias'] = 'Marcá al menos un día de la semana.'; }

    // El horario de cada día tiene que ir de menor a mayor. Antes, si estaba al
    // revés, la fila se descartaba sin decir nada y la pantalla avisaba que se
    // había guardado: ese día quedaba sin horarios y nadie entendía por qué.
    $diasNombre = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    foreach ((array)($d['dias'] ?? []) as $dia) {
        $desde = (string)($d['desde'][$dia] ?? '');
        $hasta = (string)($d['hasta'][$dia] ?? '');
        if ($desde !== '' && $hasta !== '' && $desde >= $hasta) {
            $errores['dias'] = 'En ' . ($diasNombre[(int)$dia] ?? 'ese día')
                             . ' la hora de fin tiene que ser posterior a la de inicio.';
            break;
        }
    }

    // Dos tipos con el mismo nombre generan el mismo enlace público y chocan
    // contra la restricción de la base: antes eso salía como un error 500.
    if (!isset($errores['nombre'])) {
        $slug = slugificar((string)(($d['slug'] ?? null) ?: $d['nombre'] ?? ''));
        $rep  = qv('SELECT id FROM agenda_tipos WHERE usuario_id = ? AND slug = ?'
                 . ($id ? ' AND id <> ?' : ''),
                   $id ? [$uid, $slug, $id] : [$uid, $slug]);
        if ($rep) {
            $errores['nombre'] = 'Ya tenés un tipo de reunión con ese nombre, y el enlace público '
                               . 'sería el mismo. Poné otro nombre.';
        }
    }

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

render('agenda-form', compact('t', 'id', 'errores', 'porDia',
                              'duenioU', 'conectado', 'candidatos'),
       $id ? 'Editar tipo de reunión' : 'Nuevo tipo de reunión');

<?php
declare(strict_types=1);

// ============================================================
//  Alta y edición de un tipo de reunión (ruta `agenda-form?id=N`, módulo
//  'agenda', con login). Sin id, crea uno nuevo.
//
//  GET: arma el formulario con el tipo y su horario laboral (o con los
//  valores por defecto: 30 min, lunes a viernes de 9 a 18, zona de Argentina).
//  POST: valida (CSRF; agenda_tipo_validar(): nombre de hasta 120, al menos
//  un día, días 0-6, horas 'HH:MM' en orden, zona que exista; slug que no
//  repita otro tipo del mismo dueño ni el de otro usuario con la misma parte
//  local del correo) y guarda con agenda_tipo_guardar(), que además recorta
//  el slug a 60; si sale bien, redirige a `agenda`.
//
//  Permisos: cada quien edita sus tipos; un administrador, los de todos, y es
//  el único que puede elegir sobre qué calendario (usuario) cae el tipo.
//
//  Escribe agenda_tipos y agenda_horarios (a través de agenda.php).
//  Vista: views/agenda-form.php, que recibe t, id, errores, porDia, duenioU,
//  conectado y candidatos.
// ============================================================

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
          'ventana_dias' => 30, 'con_meet' => 1, 'activo' => 1,
          'zona_horaria' => AGENDA_ZONA_DEFECTO];
}

// porDia: dia (0 = domingo) => ['H:i', 'H:i']. Si un día tuviera varias
// franjas en la base, el formulario sólo muestra la última (admite una por día).
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
// esa cuenta tuviera Google conectado. Si no lo tiene, la agenda no puede leer
// lo ocupado (gcal_ocupado() devuelve null) y no ofrece ningún horario: ni la
// página pública ni la web toman reservas. Lo que agenda el equipo sin validar
// el hueco sí se guarda, pero sin evento ni Meet.
$duenio   = (int)($t['usuario_id'] ?? $uid);
$duenioU  = q1('SELECT id, nombre, email FROM usuarios WHERE id = ?', [$duenio]) ?: [];
$conectado = $duenio ? google_conectado($duenio) : false;
// Sólo el administrador ve el selector de calendario. Si el dueño actual ya
// no está activo, se suma a la lista (marcado «inactivo») para que el
// selector arranque en él: si no, guardar sin mirar cambiaba el dueño del tipo.
$candidatos = auth_is_admin() ? users_activos() : [];
if ($candidatos && $duenioU && !in_array((int)$duenioU['id'], array_map('intval', array_column($candidatos, 'id')), true)) {
    array_unshift($candidatos, ['id' => (int)$duenioU['id'], 'nombre' => $duenioU['nombre'] . ' (inactivo)',
                                'email' => $duenioU['email']]);
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    // Sólo un administrador puede poner el tipo de reunión sobre el calendario
    // de otra persona. Para el resto, siempre el propio: si no, bastaría con
    // cambiar el número en el formulario para agendar sobre el calendario ajeno.
    $pedido = (int)($_POST['usuario_id'] ?? 0);
    $d['usuario_id'] = (auth_is_admin() && $pedido > 0) ? $pedido : $uid;
    // Nombre, días, horas y zona: lo mismo que limita el formulario, pero
    // controlado acá, porque un POST armado a mano llega igual.
    $errores = agenda_tipo_validar($d);

    // Dos tipos con el mismo nombre generan el mismo enlace público y chocan
    // contra la restricción de la base: antes eso salía como un error 500.
    // Se comprueba contra el dueño del calendario elegido, que no siempre es
    // quien edita: un administrador puede armar el tipo para otra persona.
    // También contra otro usuario con la misma parte local del correo en otro
    // dominio: la URL /r/<usuario>/<slug> sería la misma para los dos.
    if (!isset($errores['nombre'])) {
        $slugPedido = $d['slug'] ?? null;
        $slug = slugificar((string)((is_string($slugPedido) && $slugPedido !== '' ? $slugPedido : null)
                                    ?: (string)($d['nombre'] ?? '')));
        $rep  = qv('SELECT id FROM agenda_tipos WHERE usuario_id = ? AND slug = ?'
                 . ($id ? ' AND id <> ?' : ''),
                   $id ? [(int)$d['usuario_id'], $slug, $id] : [(int)$d['usuario_id'], $slug]);
        if ($rep) {
            $errores['nombre'] = 'Ya tenés un tipo de reunión con ese nombre, y el enlace público '
                               . 'sería el mismo. Poné otro nombre.';
        } elseif (agenda_slug_choca_usuario((int)$d['usuario_id'], $slug, $id ?: null)) {
            $errores['nombre'] = 'Otra persona del equipo con el mismo usuario de correo ya usa ese enlace '
                               . 'público. Poné otro nombre o cambiá la dirección del enlace.';
        }
    }

    if (!$errores) {
        $nuevoId = agenda_tipo_guardar($d, $id ?: null);
        flash($id ? 'Tipo de reunión actualizado.' : 'Tipo de reunión creado. Ya podés compartir el enlace.', 'success');
        redirect('agenda');
    }
    // Con errores se vuelve a dibujar el formulario con lo que se mandó.
    $t = array_merge($t ?? [], $d);
    $porDia = [];
    foreach ((array)($d['dias'] ?? []) as $dia) {
        if (!is_scalar($dia)) { continue; }
        $desde = $d['desde'][$dia] ?? '09:00';
        $hasta = $d['hasta'][$dia] ?? '18:00';
        $porDia[(int)$dia] = [is_string($desde) ? $desde : '09:00', is_string($hasta) ? $hasta : '18:00'];
    }
}

render('agenda-form', compact('t', 'id', 'errores', 'porDia',
                              'duenioU', 'conectado', 'candidatos'),
       $id ? 'Editar tipo de reunión' : 'Nuevo tipo de reunión');

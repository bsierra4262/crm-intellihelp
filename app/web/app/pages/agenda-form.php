<?php
declare(strict_types=1);

// ============================================================
//  Alta y edición de un tipo de reunión (ruta `agenda-form?id=N`, módulo
//  'agenda', con login). Sin id, crea uno nuevo.
//
//  GET: arma el formulario con el tipo y su horario laboral (o con los
//  valores por defecto: 30 min, lunes a viernes de 9 a 18, zona de Argentina).
//  POST: valida (CSRF, nombre, al menos un día, horas en orden, zona que
//  exista, slug que no repita otro tipo del mismo dueño) y guarda con
//  agenda_tipo_guardar(); si sale bien, redirige a `agenda`.
//
//  Permisos: cada quien edita sus tipos; un administrador, los de todos, y es
//  el único que puede elegir sobre qué calendario (usuario) cae el tipo.
//
//  Escribe agenda_tipos y agenda_horarios (a través de agenda.php).
//  Vista: views/agenda-form.php, que recibe t, id, errores, porDia, duenioU,
//  conectado y candidatos.
//
//  No se valida del lado del servidor: el largo del nombre (120) ni del slug
//  generado (60), el formato de las horas ni que el día esté entre 0 y 6. El
//  formulario lo limita, pero un POST armado a mano llega igual a la base.
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
// no está activo, no figura en la lista y el selector arranca en otra persona:
// guardar sin mirar cambia el dueño del tipo.
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

    // El horario de cada día tiene que ir de menor a mayor. Si viene al revés se
    // rechaza con un error en el campo, en vez de descartar la fila en silencio.
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

    // La zona tiene que existir: con una inventada, el cálculo de los horarios
    // caía en la de Argentina sin que nadie se enterara.
    if (!in_array((string)($d['zona_horaria'] ?? ''), DateTimeZone::listIdentifiers(), true)) {
        $errores['zona_horaria'] = 'Elegí la zona horaria de la lista.';
    }

    // Dos tipos con el mismo nombre generan el mismo enlace público y chocan
    // contra la restricción de la base: antes eso salía como un error 500.
    // Se comprueba contra el dueño del calendario elegido, que no siempre es
    // quien edita: un administrador puede armar el tipo para otra persona.
    if (!isset($errores['nombre'])) {
        $slug = slugificar((string)(($d['slug'] ?? null) ?: $d['nombre'] ?? ''));
        $rep  = qv('SELECT id FROM agenda_tipos WHERE usuario_id = ? AND slug = ?'
                 . ($id ? ' AND id <> ?' : ''),
                   $id ? [(int)$d['usuario_id'], $slug, $id] : [(int)$d['usuario_id'], $slug]);
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
    // Con errores se vuelve a dibujar el formulario con lo que se mandó.
    $t = array_merge($t ?? [], $d);
    $porDia = [];
    foreach ((array)($d['dias'] ?? []) as $dia) {
        $porDia[(int)$dia] = [$d['desde'][$dia] ?? '09:00', $d['hasta'][$dia] ?? '18:00'];
    }
}

render('agenda-form', compact('t', 'id', 'errores', 'porDia',
                              'duenioU', 'conectado', 'candidatos'),
       $id ? 'Editar tipo de reunión' : 'Nuevo tipo de reunión');

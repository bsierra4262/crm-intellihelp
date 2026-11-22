<?php
declare(strict_types=1);

// ============================================================
//  Página `actividad-form`: alta (sin `id`) y edición (`?id=N`) de una
//  actividad.
//
//  El alta puede llegar desde una ficha con el vínculo en la dirección:
//  `?contacto_id=`, `?posible_cliente_id=`, `?cuenta_id=`, `?negocio_id=` y
//  `?tipo=`. POST valida el asunto, guarda con actividad_guardar()
//  (actividades.php) y redirige.
//
//  Tablas: escribe `actividades` (y la auditoría en `actividad`); lee
//  `contactos`, `cuentas`, `negocios` y `usuarios` para los selectores.
//  Módulo: `actividades`.
//  Permisos: cualquier usuario con sesión crea o edita cualquier actividad,
//  y puede asignársela a otro.
//
//  Validación: actividad_validar() (actividades.php) controla asunto, fechas
//  (formato y fin después del inicio), estado, prioridad y que los ids
//  vinculados existan.
//
//  Reuniones reservadas por la agenda: si la actividad tiene una reserva
//  confirmada (agenda_reservas.actividad_id), el tipo y las fechas no se
//  cambian desde acá: cambiarlas movía sólo la actividad y dejaba la reserva,
//  el evento de Google y la invitación del cliente en el horario viejo. Se
//  ofrece el enlace a «Mover la reunión» (reserva-mover), que mueve todo junto.
//
//  `volver`: en un alta desde una ficha, a esa ficha; si no, al listado.
// ============================================================

require_login();

$id = (int)($_GET['id'] ?? 0);
$a  = $id ? actividad_get($id) : null;
if ($id && !$a) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Actividad inexistente',
        'mensaje' => 'Puede que la hayan eliminado.'], 'No encontrado');
    exit;
}

// Alta desde la ficha de un registro: viene precargado el vínculo.
// Vencimiento e inicio se proponen para mañana a las 10.
if (!$id) {
    $a = [
        'tipo'               => $_GET['tipo'] ?? 'tarea',
        'contacto_id'        => $_GET['contacto_id'] ?? '',
        'posible_cliente_id' => $_GET['posible_cliente_id'] ?? '',
        'cuenta_id'          => $_GET['cuenta_id'] ?? '',
        'negocio_id'         => $_GET['negocio_id'] ?? '',
        'vence_el'           => date('Y-m-d\TH:i', strtotime('+1 day 10:00')),
        'inicia_el'          => date('Y-m-d\TH:i', strtotime('+1 day 10:00')),
        'prioridad'          => 'Normal',
        'estado'             => 'No iniciada',
        'propietario_id'     => current_user_id(),
    ];
}

// La reserva de la agenda que creó esta reunión, si sigue confirmada. La
// consulta no rompe la pantalla si la tabla de la agenda no está.
$reserva = null;
if ($id) {
    try {
        $reserva = q1("SELECT id, usuario_id FROM agenda_reservas
                       WHERE actividad_id = ? AND estado = 'confirmada' ORDER BY id DESC LIMIT 1", [$id]);
    } catch (\Throwable $e) {
        $reserva = null;
    }
}
$fechaBloqueada = (bool)$reserva;

// A dónde volver después de guardar: una ruta interna, sin barras, puntos ni
// dos puntos (redirect() le antepone la base de la aplicación). En un alta
// desde una ficha se vuelve a esa ficha.
$limpiarVolver = static fn(string $v): string => (string) preg_replace('/[^a-z0-9\-_?=&]/i', '', $v);
$volver = '';
if (!$id) {
    foreach (['contacto_id' => 'contacto-ver', 'posible_cliente_id' => 'posible-ver',
              'cuenta_id' => 'cuenta-ver', 'negocio_id' => 'negocio-ver'] as $col => $ruta) {
        if ((int)($a[$col] ?? 0) > 0) { $volver = $ruta . '?id=' . (int)$a[$col]; break; }
    }
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    $volver = $limpiarVolver((string)($d['volver'] ?? ''));
    if ($fechaBloqueada) {
        // Tipo y horario de una reunión reservada no se tocan desde acá.
        foreach (['tipo', 'inicia_el', 'termina_el'] as $k) { $d[$k] = (string)($a[$k] ?? ''); }
    }
    [$d, $errores] = actividad_validar($d);
    if (!$errores) {
        $nuevoId = actividad_guardar($d, $id ?: null);
        flash($id ? 'Actividad actualizada.' : 'Actividad creada.', 'success');
        redirect($volver !== '' ? $volver : 'actividades');
    }
    $a = array_merge($a ?? [], $d);
}

// Listas recortadas para los selectores: un vínculo que quede fuera de estos
// límites no se puede mostrar y se pierde al guardar (ver la vista).

render('actividad-form', [
    'a'         => $a,
    'id'        => $id,
    'errores'   => $errores,
    'reserva'   => $reserva,
    'volver'    => $volver,
    'usuarios'  => users_activos(),
    'contactos' => q('SELECT id, nombre_completo FROM contactos WHERE eliminado_en IS NULL
                      ORDER BY apellido, nombre LIMIT 3000')->fetchAll(),
    'cuentas'   => q('SELECT id, nombre FROM cuentas WHERE eliminado_en IS NULL ORDER BY nombre LIMIT 3000')->fetchAll(),
    'negocios'  => q('SELECT id, nombre FROM negocios WHERE eliminado_en IS NULL ORDER BY creado_en DESC LIMIT 1000')->fetchAll(),
], $id ? 'Editar actividad' : 'Nueva actividad');

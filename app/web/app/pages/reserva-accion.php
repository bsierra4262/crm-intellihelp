<?php
declare(strict_types=1);
require_login();

// Cancelar o mover una reunión desde el CRM. Hasta ahora estas dos cosas sólo
// se podían hacer desde el enlace que recibía el invitado por correo: si
// llamaba por teléfono para correr la reunión, no había forma de hacerlo desde
// acá y había que entrar al calendario de Google a mano.

if (!is_post()) { redirect('reservas'); }
verify_csrf();

$id     = (int)($_POST['id'] ?? 0);
$accion = (string)($_POST['accion'] ?? '');
$r      = $id ? q1('SELECT * FROM agenda_reservas WHERE id = ?', [$id]) : null;

if (!$r) {
    flash('No encontramos esa reunión.', 'error');
    redirect('reservas');
}

// Cada quien maneja sus reuniones; el administrador, las de todos.
if ((int)$r['usuario_id'] !== current_user_id() && !auth_is_admin()) {
    flash('Esa reunión es de otra persona del equipo.', 'error');
    redirect('reservas');
}

try {
    if ($accion === 'cancelar') {
        agenda_cancelar((string)$r['token'], (string)($_POST['motivo'] ?? ''))
            ? flash('Reunión cancelada. Le avisamos por correo y la sacamos del calendario.', 'success')
            : flash('Esa reunión ya no estaba activa.', 'info');

    } elseif ($accion === 'reprogramar') {
        // El horario viaja como marca de tiempo: un texto «Y-m-d H:i» se leía en
        // la zona de la instalación y no en la del tipo de reunión.
        $ts = (int)($_POST['inicio'] ?? 0);
        if ($ts <= 0) {
            $cuando = trim((string)($_POST['cuando'] ?? ''));
            $ts     = $cuando !== '' ? (int)strtotime($cuando) : 0;
        }
        if (!$ts) {
            throw new RuntimeException('Elegí el día y la hora nuevos.');
        }
        agenda_reprogramar((string)$r['token'], $ts);
        flash('Reunión movida al ' . fecha_hora(date('Y-m-d H:i:s', $ts))
            . '. Le mandamos la invitación actualizada por correo.', 'success');

    } else {
        flash('Acción desconocida.', 'error');
    }
} catch (\Throwable $e) {
    flash($e->getMessage(), 'error');
}

redirect('reservas');

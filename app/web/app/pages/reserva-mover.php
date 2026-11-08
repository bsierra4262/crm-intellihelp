<?php
declare(strict_types=1);
require_login();

// Mover una reunión a otro horario, desde adentro del CRM. Muestra los huecos
// reales del vendedor —los mismos que ve el cliente en la agenda pública— para
// no tener que adivinar cuáles están libres.

$id = (int)($_GET['id'] ?? 0);
$r  = $id ? q1('SELECT * FROM agenda_reservas WHERE id = ?', [$id]) : null;

if (!$r || $r['estado'] !== 'confirmada') {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Esa reunión ya no está activa',
        'mensaje' => 'Puede que la hayan cancelado o movido antes.'], 'No encontrado');
    exit;
}
if ((int)$r['usuario_id'] !== current_user_id() && !auth_is_admin()) {
    http_response_code(403);
    render('error', ['codigo' => 403, 'titulo' => 'Esa reunión es de otra persona',
        'mensaje' => 'Sólo la puede mover quien la tiene agendada, o un administrador.'], 'Sin permiso');
    exit;
}

$tipo = agenda_tipo_de_reserva($r);
if (!$tipo) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'El tipo de reunión ya no existe',
        'mensaje' => 'No podemos calcular los horarios libres sin él.'], 'No encontrado');
    exit;
}

// Se le pasa la reserva que se mueve para que su propio horario no cuente
// como ocupado: si no, los huecos pegados a la reunión actual no aparecían.
$motivo = null;
$huecos = agenda_huecos($tipo, 0, $motivo, (int)$r['id']);

render('reserva-mover', [
    'r'      => $r,
    'tipo'   => $tipo,
    'huecos' => $huecos,
    'motivo' => $motivo,
], 'Mover la reunión');

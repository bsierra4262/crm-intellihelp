<?php
declare(strict_types=1);

// ============================================================
//  Página PÚBLICA de reserva de reuniones (nuestro Calendly).
//    /r/<usuario>/<tipo>     → elegir día y hora
//    /r/cancelar?t=<token>   → cancelar una reserva
// ============================================================

$resto = trim((string)($_GET['_ruta'] ?? ''), '/');
$bodyClass = 'publica';

// ---- Cancelación desde el enlace del correo ----
if ($resto === 'cancelar') {
    $token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
    $r = $token !== '' ? q1('SELECT * FROM agenda_reservas WHERE token = ?', [$token]) : null;
    $ok = false;
    if ($r && $r['estado'] === 'confirmada' && is_post()) {
        $ok = agenda_cancelar($token, (string)($_POST['motivo'] ?? ''));
    }
    render('agenda-cancelar', ['r' => $r, 'ok' => $ok, 'bodyClass' => $bodyClass], 'Cancelar reunión');
    exit;
}

$partes  = explode('/', $resto);
$usuario = $partes[0] ?? '';
$slug    = $partes[1] ?? '';
$tipo    = ($usuario !== '' && $slug !== '') ? agenda_tipo_por_slug($usuario, $slug) : null;

if (!$tipo) {
    http_response_code(404);
    render('agenda-error', [
        'titulo'  => 'No encontramos esa agenda',
        'mensaje' => 'El enlace puede estar viejo. Pedile a tu contacto uno nuevo.',
        'bodyClass' => $bodyClass,
    ], 'Agenda');
    exit;
}

$huecos = agenda_huecos($tipo);
$dias   = array_keys($huecos);
$diaSel = (string)($_GET['dia'] ?? ($dias[0] ?? ''));

render('agenda-publica', [
    'tipo'      => $tipo,
    'huecos'    => $huecos,
    'diaSel'    => $diaSel,
    'conectado' => gcal_disponible((int)$tipo['usuario_id']),
    'campana'   => (int)($_GET['c'] ?? 0),
    'bodyClass' => $bodyClass,
], $tipo['nombre'] . ' · ' . $tipo['usuario']);

<?php
declare(strict_types=1);

// Confirmación de una reserva desde la página pública. No requiere sesión.
$bodyClass = 'publica';

if (!is_post()) { redirect('login'); }

$tipo = agenda_tipo_get((int)($_POST['tipo_id'] ?? 0));
if (!$tipo || (int)$tipo['activo'] !== 1) {
    render('agenda-error', ['titulo' => 'Agenda no disponible',
        'mensaje' => 'El enlace ya no está activo.', 'bodyClass' => $bodyClass], 'Agenda');
    exit;
}

try {
    $reserva = agenda_reservar($tipo, [
        'inicio'     => (int)($_POST['inicio'] ?? 0),
        'nombre'     => trim((string)($_POST['nombre'] ?? '')),
        'email'      => (string)($_POST['email'] ?? ''),
        'empresa'    => trim((string)($_POST['empresa'] ?? '')),
        'telefono'   => trim((string)($_POST['telefono'] ?? '')),
        'comentario' => trim((string)($_POST['comentario'] ?? '')),
        'campana_id' => (int)($_POST['campana_id'] ?? 0) ?: null,
    ]);
    render('agenda-ok', ['r' => $reserva, 'tipo' => $tipo, 'bodyClass' => $bodyClass], 'Reunión confirmada');
} catch (\Throwable $e) {
    render('agenda-error', ['titulo' => 'No pudimos agendar la reunión',
        'mensaje' => $e->getMessage(), 'bodyClass' => $bodyClass], 'Agenda');
}

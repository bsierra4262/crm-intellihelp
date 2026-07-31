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

// El render de la confirmación va FUERA del try. Adentro, si algo fallaba
// después de haberla dibujado —al mandar el correo, por ejemplo— el catch
// dibujaba la página de error pegada abajo de la de éxito, y el visitante veía
// las dos cosas a la vez.
$reserva = null;
$error   = null;
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
} catch (RuntimeException $e) {
    // Motivo entendible para el visitante: el horario ya lo tomaron, el correo
    // está mal escrito, la fecha pasó.
    $error = $e->getMessage();
} catch (\Throwable $e) {
    // Falla nuestra: no se le explica al visitante, se registra para nosotros.
    log_error('reservar.error', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    $error = 'Tuvimos un problema al agendar. Probá de nuevo en un momento; '
           . 'si sigue pasando, escribinos y lo coordinamos a mano.';
}

if ($reserva) {
    render('agenda-ok', ['r' => $reserva, 'tipo' => $tipo, 'bodyClass' => $bodyClass], 'Reunión confirmada');
} else {
    render('agenda-error', ['titulo' => 'No pudimos agendar la reunión',
        'mensaje' => $error, 'bodyClass' => $bodyClass], 'Agenda');
}

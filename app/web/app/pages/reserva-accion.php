<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Cancelar o mover una reunión desde el CRM (POST `reserva-accion`, módulo
//  'agenda', con login y CSRF). Lo llaman los botones de views/reservas.php
//  (cancelar) y de views/reserva-mover.php (cada horario).
//
//  Hasta ahora estas dos cosas sólo se podían hacer desde el enlace que
//  recibía el invitado por correo: si llamaba por teléfono para correr la
//  reunión, no había forma de hacerlo desde acá y había que entrar al
//  calendario de Google a mano.
//
//  POST: id (de agenda_reservas), accion = cancelar | reprogramar, motivo
//  (cancelar), inicio en segundos Unix (reprogramar; `cuando` como texto es
//  el camino viejo, y se lee en la zona del tipo de reunión).
//
//  Llama a agenda_cancelar() o agenda_reprogramar() con el token de la
//  reserva, que manda al invitado el aviso por correo. Siempre termina con un
//  mensaje (flash) y redirige a `reservas`.
//
//  Al usuario sólo se le muestra el mensaje de una AgendaExcepcion (pensado
//  para él). Cualquier otro error se registra con log_error() y se muestra un
//  mensaje general, sin el texto técnico.
// ============================================================

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
        $avisado = false;
        if (agenda_cancelar((string)$r['token'], (string)($_POST['motivo'] ?? ''), $avisado)) {
            flash($avisado
                ? 'Reunión cancelada. Le avisamos por correo y la sacamos del calendario.'
                : 'Reunión cancelada. No pudimos mandarle el aviso por correo: avisale vos.', 'success');
        } else {
            flash('Esa reunión ya no estaba activa.', 'info');
        }

    } elseif ($accion === 'reprogramar') {
        // El horario viaja como marca de tiempo: un texto «Y-m-d H:i» se leía en
        // la zona de la instalación y no en la del tipo de reunión. El camino
        // de respaldo (`cuando`) se lee en la zona del tipo.
        $tipo = agenda_tipo_de_reserva($r);
        $ts   = (int)($_POST['inicio'] ?? 0);
        if ($ts <= 0) {
            $cuando = trim((string)($_POST['cuando'] ?? ''));
            try {
                $ts = $cuando !== ''
                    ? (new DateTimeImmutable($cuando, $tipo ? agenda_zona($tipo) : null))->getTimestamp()
                    : 0;
            } catch (\Throwable $e) {
                $ts = 0;
            }
        }
        if ($ts <= 0) {
            flash('Elegí el día y la hora nuevos.', 'error');
            redirect('reservas');
        }
        // Sin validar el hueco: el equipo puede moverla a cualquier hora que
        // no pise otra reserva del CRM (ni feriado). El mensaje muestra la
        // hora en la zona del tipo, la misma de los botones de reserva-mover.
        $nueva = agenda_reprogramar((string)$r['token'], $ts);
        $local = $tipo ? agenda_local($ts, $tipo) : date('Y-m-d H:i:s', $ts);
        flash('Reunión movida al ' . fecha_hora($local)
            . ($tipo ? ' (' . mb_strtolower(agenda_zona_etiqueta($tipo)) . ')' : '')
            . (!empty($nueva['avisada_en'])
                ? '. Le mandamos la invitación actualizada por correo.'
                : (smtp_configurado()
                    ? '. El correo con la invitación nueva no salió todavía: se reintenta solo.'
                    : '. No hay correo configurado para mandarle la invitación nueva: avisale vos.')), 'success');

    } else {
        flash('Acción desconocida.', 'error');
    }
} catch (AgendaExcepcion $e) {
    flash($e->getMessage(), 'error');
} catch (\Throwable $e) {
    log_error('reserva.accion', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    flash('No pudimos completar el cambio. Probá de nuevo en un momento.', 'error');
}

redirect('reservas');

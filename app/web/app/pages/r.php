<?php
declare(strict_types=1);

// ============================================================
//  Página PÚBLICA de reserva de reuniones (nuestro Calendly).
//    /r/<usuario>/<tipo>     → elegir día y hora
//    /r/mover?t=<token>      → cambiar el horario de una reserva
//    /r/cancelar?t=<token>   → cancelar una reserva
//
//  Los enlaces de mover y cancelar llegan sólo al correo de quien reservó.
//  Sirven aunque sean de un correo viejo: si la reunión ya se movió, se
//  sigue la cadena hasta la reserva vigente (agenda_reserva_vigente).
// ============================================================

$resto = trim((string)($_GET['_ruta'] ?? ''), '/');
$bodyClass = 'publica';

// Con la Agenda apagada, lo público también se apaga: si /r/ siguiera
// respondiendo, entrarían reservas que nadie del equipo ve. Se resuelve acá y
// no en la lista de rutas del módulo para que la pantalla sea la pública y no
// la del sistema.
if (!modulo_activo('agenda') || !agenda_esquema_listo()) {
    http_response_code(modulo_activo('agenda') ? 503 : 404);
    render('agenda-error', [
        'titulo'    => 'Agenda no disponible',
        'mensaje'   => 'En este momento no se pueden reservar reuniones por acá. Escribinos a '
                     . setting_get('empresa_email', 'info@intellihelp.tech') . ' y lo coordinamos.',
        'bodyClass' => $bodyClass,
    ], 'Agenda');
    exit;
}

// Cada carga de estas páginas consulta el calendario del vendedor. Con la
// caché de horarios y este límite por visitante, martillarlas no agota la
// cuota de Google ni deja la agenda sin horarios para los demás.
if (!is_post() && agenda_limite_vistas()) {
    http_response_code(429);
    header('Retry-After: 600');
    render('agenda-error', [
        'titulo'    => 'Demasiadas consultas seguidas',
        'mensaje'   => 'Esperá unos minutos y volvé a abrir el enlace, o escribinos a '
                     . setting_get('empresa_email', 'info@intellihelp.tech') . ' y lo coordinamos.',
        'bodyClass' => $bodyClass,
    ], 'Agenda');
    exit;
}

/** Mensaje para el visitante cuando el token del formulario no sirve. */
$avisoToken = static fn(?string $m): string => match ($m) {
    'vencido' => 'La página estuvo abierta mucho tiempo. Revisá el horario y confirmá de nuevo.',
    'rapido'  => 'Confirmaste antes de que termináramos de cargar. Probá de nuevo, por favor.',
    default   => 'No pudimos validar el formulario. Probá de nuevo, por favor.',
};

// ---- Cancelación desde el enlace del correo ----
if ($resto === 'cancelar') {
    $r     = agenda_reserva_vigente((string)($_GET['t'] ?? ''));
    $ok    = false;
    $error = null;
    if ($r && $r['estado'] === 'confirmada' && is_post()) {
        $m = null;
        if (!agenda_form_token_valido((string)($_POST['form_token'] ?? ''), (int)$r['tipo_id'], $m, 'cancelar', true)) {
            $error = $avisoToken($m);
        } elseif (agenda_limite_publico()) {
            http_response_code(429);
            $error = 'Hiciste varios cambios seguidos. Esperá un rato o escribinos y lo resolvemos.';
        } else {
            $ok = agenda_cancelar((string)$r['token'], recortar(trim((string)($_POST['motivo'] ?? '')), 1000));
            $r  = q1('SELECT * FROM agenda_reservas WHERE id = ?', [(int)$r['id']]) ?? $r;
        }
    }
    $tipo = $r ? agenda_tipo_get((int)$r['tipo_id']) : null;
    render('agenda-cancelar', [
        'r'         => $r,
        'tipo'      => $tipo,
        'ok'        => $ok,
        'error'     => $error,
        'formToken' => $r ? agenda_form_token((int)$r['tipo_id'], 'cancelar') : '',
        'bodyClass' => $bodyClass,
    ], 'Cancelar reunión');
    exit;
}

// ---- Cambio de horario desde el enlace del correo ----
// Hasta ahora el invitado sólo podía cancelar: para correr la reunión tenía
// que escribir y esperar a que alguien la moviera desde el CRM.
if ($resto === 'mover') {
    $r     = agenda_reserva_vigente((string)($_GET['t'] ?? ''));
    $tipo  = $r ? agenda_tipo_get((int)$r['tipo_id']) : null;
    $error = null;

    if ($r && $tipo && $r['estado'] === 'confirmada' && is_post()) {
        $m = null;
        if (!agenda_form_token_valido((string)($_POST['form_token'] ?? ''), (int)$r['tipo_id'], $m, 'mover', true)) {
            $error = $avisoToken($m);
        } elseif (agenda_limite_publico()) {
            http_response_code(429);
            $error = 'Hiciste varios cambios seguidos. Esperá un rato o escribinos y lo resolvemos.';
        } else {
            try {
                $nueva = agenda_reprogramar((string)$r['token'], (int)($_POST['inicio'] ?? 0), true);
                render('agenda-ok', ['r' => $nueva, 'tipo' => $tipo, 'movida' => true,
                                     'bodyClass' => $bodyClass], 'Horario cambiado');
                exit;
            } catch (AgendaExcepcion $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                log_error('reservar.mover', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
                $error = 'Tuvimos un problema al cambiar el horario. Probá de nuevo en un momento; '
                       . 'si sigue pasando, escribinos y lo coordinamos a mano.';
            }
        }
    }

    $motivo = null;
    $huecos = ($r && $tipo && $r['estado'] === 'confirmada')
        ? agenda_huecos($tipo, 0, $motivo, (int)$r['id'], ['cache' => AGENDA_CACHE_HORARIOS])
        : [];
    $dias   = array_keys($huecos);
    $diaSel = (string)($_GET['dia'] ?? '');
    if (!isset($huecos[$diaSel])) { $diaSel = (string)($dias[0] ?? ''); }

    render('agenda-mover', [
        'r'         => $r,
        'tipo'      => $tipo,
        'huecos'    => $huecos,
        'diaSel'    => $diaSel,
        'motivo'    => $motivo,
        'error'     => $error,
        'formToken' => $r ? agenda_form_token((int)$r['tipo_id'], 'mover') : '',
        'bodyClass' => $bodyClass,
    ], 'Cambiar el horario');
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

agenda_render_publica($tipo);

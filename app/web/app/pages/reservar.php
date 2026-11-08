<?php
declare(strict_types=1);

// Confirmación de una reserva desde la página pública. No requiere sesión.
//
// Es un formulario abierto a cualquiera, así que antes de tocar el calendario
// pasa por cuatro filtros: el módulo Agenda en marcha, el campo trampa que una
// persona no ve (`sitio_web`), el token firmado del formulario (sirve de CSRF y
// descarta lo que se manda en menos de 3 segundos) y un límite de envíos por
// hash de IP. Sin esto un programa podía llenar el calendario del vendedor.
$bodyClass = 'publica';

if (!is_post()) { redirect('login'); }

if (!modulo_activo('agenda') || !agenda_esquema_listo()) {
    http_response_code(modulo_activo('agenda') ? 503 : 404);
    render('agenda-error', ['titulo' => 'Agenda no disponible',
        'mensaje' => 'En este momento no se pueden reservar reuniones por acá.',
        'bodyClass' => $bodyClass], 'Agenda');
    exit;
}

$tipo = agenda_tipo_get((int)($_POST['tipo_id'] ?? 0));
if (!$tipo || (int)$tipo['activo'] !== 1) {
    render('agenda-error', ['titulo' => 'Agenda no disponible',
        'mensaje' => 'El enlace ya no está activo.', 'bodyClass' => $bodyClass], 'Agenda');
    exit;
}

$usuarioSlug = explode('@', (string)$tipo['usuario_email'])[0];
$campanaId   = (int)($_POST['campana_id'] ?? 0) ?: null;
$origenPost  = agenda_origen_de_post();
$inicio      = (int)($_POST['inicio'] ?? 0);
$viejo = [
    'nombre'     => trim((string)($_POST['nombre'] ?? '')),
    'email'      => trim((string)($_POST['email'] ?? '')),
    'empresa'    => trim((string)($_POST['empresa'] ?? '')),
    'telefono'   => trim((string)($_POST['telefono'] ?? '')),
    'comentario' => trim((string)($_POST['comentario'] ?? '')),
    'acepto'     => !empty($_POST['acepto']),
    'inicio'     => $inicio,
];

/** Vuelve a mostrar la página con un aviso y lo que la persona ya había escrito. */
$volver = static function (string $error, ?string $campo = null, bool $conHorario = true)
    use ($tipo, $viejo, $campanaId, $origenPost, $inicio): void {
    if (!$conHorario) { $viejo['inicio'] = 0; }
    agenda_render_publica($tipo, [
        'error'   => $error,
        'campo'   => $campo,
        'viejo'   => $viejo,
        'campana' => (int)$campanaId,
        'origen'  => $origenPost,
        'diaSel'  => $inicio > 0 ? agenda_local($inicio, $tipo, 'Y-m-d') : null,
    ]);
    exit;
};

// 1) Campo trampa: una persona no lo ve, un programa lo completa. No se le
//    explica nada: la respuesta es la misma que cuando algo sale mal.
if (trim((string)($_POST['sitio_web'] ?? '')) !== '') {
    log_app('agenda.descartada', ['tipo' => 'sistema', 'entidad' => 'agenda_tipo',
        'entidad_id' => (int)$tipo['id'], 'detalle' => 'Reserva descartada: completó el campo oculto.']);
    render('agenda-error', ['titulo' => 'No pudimos agendar la reunión',
        'mensaje' => 'Probá de nuevo en un momento.', 'bodyClass' => $bodyClass], 'Agenda');
    exit;
}

// 2) Token del formulario.
$m = null;
if (!agenda_form_token_valido((string)($_POST['form_token'] ?? ''), (int)$tipo['id'], $m, 'reservar', true)) {
    $volver(match ($m) {
        'vencido' => 'La página estuvo abierta mucho tiempo y los horarios pueden haber cambiado. '
                   . 'Elegí el horario de nuevo.',
        'rapido'  => 'Confirmaste antes de que termináramos de cargar. Revisá los datos y confirmá de nuevo.',
        default   => 'No pudimos validar el formulario. Elegí el horario y confirmá de nuevo.',
    });
}

// 3) Lo que se puede revisar sin tocar el calendario, antes de contar el envío:
//    un correo mal escrito no tiene por qué gastar uno de los intentos.
if (!$viejo['acepto']) {
    $volver('Para agendar la reunión tenés que aceptar el uso de tus datos.', 'acepto');
}
if (mb_strlen($viejo['nombre']) < 2) { $volver('Escribí tu nombre y apellido.', 'nombre'); }
if (!email_valido($viejo['email']))  { $volver('El correo no es válido.', 'email'); }
if (!telefono_valido($viejo['telefono'] !== '' ? $viejo['telefono'] : null)) {
    $volver('El teléfono no parece un número. Poné sólo números; se aceptan también + ( ) - . y espacios.', 'telefono');
}

// 4) Límite de envíos por hash de IP.
if (agenda_limite_publico()) {
    http_response_code(429);
    render('agenda-error', ['titulo' => 'Demasiados intentos seguidos',
        'mensaje' => 'Esperá un rato y probá de nuevo, o escribinos a '
                   . setting_get('empresa_email', 'info@intellihelp.tech') . ' y lo coordinamos.',
        'bodyClass' => $bodyClass], 'Agenda');
    exit;
}

// La entrada: de dónde llegó y con qué consentimiento. Si la tabla todavía no
// está o falla, la reserva se toma igual: es el registro de origen, no la
// reunión.
$canal   = $campanaId ? 'Campaña de correo' : 'Enlace directo';
$pagina  = 'crm:/r/' . $usuarioSlug . '/' . $tipo['slug'];
$consEn  = date('Y-m-d H:i:s');
$entrada = 0;
try {
    $entrada = entrada_web_guardar([
        'idempotencia'           => 'r-' . token32(),
        'token_id'               => null,
        'tipo'                   => 'reserva',
        'nombre'                 => $viejo['nombre'],
        'empresa'                => $viejo['empresa'] ?: null,
        'email'                  => normalizar_email($viejo['email']),
        'telefono'               => $viejo['telefono'] ?: null,
        'mensaje'                => $viejo['comentario'] ?: null,
        'novedades'              => 0,
        'consentimiento_en'      => $consEn,
        'consentimiento_version' => AGENDA_CONSENTIMIENTO_VERSION,
        'consentimiento_hash'    => hash('sha256', AGENDA_CONSENTIMIENTO_TEXTO),
        'origen_pagina'          => $pagina,
        'canal'                  => $canal,
        'utm_source'             => $origenPost['utm_source'] ?? null,
        'utm_medium'             => $origenPost['utm_medium'] ?? null,
        'utm_campaign'           => $origenPost['utm_campaign'] ?? null,
        'utm_term'               => $origenPost['utm_term'] ?? null,
        'utm_content'            => $origenPost['utm_content'] ?? null,
        'referrer'               => $origenPost['referrer'] ?? null,
        'ip_hash'                => agenda_ip_visitante(),
        'tipo_reunion_id'        => (int)$tipo['id'],
        'inicio_pedido'          => $inicio > 0 ? date('Y-m-d H:i:s', $inicio) : null,
        'propietario_id'         => (int)$tipo['usuario_id'],
    ]);
} catch (\Throwable $e) {
    log_error('reservar.entrada', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    $entrada = 0;
}

$resumen = '';
try {
    $resumen = entrada_web_resumen(['pagina' => $pagina, 'canal' => $canal] + $origenPost);
} catch (\Throwable $e) {
    $resumen = 'Llegó desde la agenda pública · ' . $canal;
}

// El render de la confirmación va FUERA del try. Adentro, si algo fallaba
// después de haberla dibujado —al mandar el correo, por ejemplo— el catch
// dibujaba la página de error pegada abajo de la de éxito, y el visitante veía
// las dos cosas a la vez.
$reserva = null;
try {
    $reserva = agenda_reservar($tipo, [
        'inicio'     => $inicio,
        'nombre'     => $viejo['nombre'],
        'email'      => $viejo['email'],
        'empresa'    => $viejo['empresa'],
        'telefono'   => $viejo['telefono'],
        'comentario' => $viejo['comentario'],
        'campana_id' => $campanaId,
    ], [
        'origen'                 => 'publica',
        'consentimiento_en'      => $consEn,
        'consentimiento_version' => AGENDA_CONSENTIMIENTO_VERSION,
        'novedades'              => false,
        'descripcion_extra'      => $resumen,
        'entrada_id'             => $entrada ?: null,
        'pagina'                 => $pagina,
    ]);
    agenda_entrada_marcar($entrada, ['estado' => 'ok']);
} catch (AgendaExcepcion $e) {
    // Motivo entendible para el visitante: el horario ya lo tomaron, el correo
    // está mal escrito, la fecha pasó. Vuelve al formulario con lo que había
    // escrito; si el problema es el horario, con los horarios recalculados.
    agenda_entrada_marcar($entrada, ['estado' => 'error', 'error' => $e->motivo]);
    if (in_array($e->motivo, ['sin_calendario', 'ocupado', 'limite'], true)) {
        render('agenda-error', ['titulo' => 'No pudimos agendar la reunión',
            'mensaje' => $e->getMessage(), 'bodyClass' => $bodyClass], 'Agenda');
        exit;
    }
    $volver($e->getMessage(), $e->campo, !in_array($e->motivo, ['horario_tomado', 'horario_invalido'], true));
} catch (\Throwable $e) {
    // Falla nuestra: no se le explica al visitante, se registra para nosotros.
    log_error('reservar.error', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    agenda_entrada_marcar($entrada, ['estado' => 'error', 'error' => 'error_interno']);
    render('agenda-error', ['titulo' => 'No pudimos agendar la reunión',
        'mensaje' => 'Tuvimos un problema al agendar. Probá de nuevo en un momento; '
                   . 'si sigue pasando, escribinos y lo coordinamos a mano.',
        'bodyClass' => $bodyClass], 'Agenda');
    exit;
}

// Evento de Google con Meet y correo de confirmación. Acá se espera, porque
// la pantalla de confirmación muestra el botón de la Meet.
$reserva = agenda_reserva_completar((int)$reserva['id']) ?: $reserva;
// «Con» dice quién la atiende de verdad: el dueño o quien lo cubre ese día.
$tipo = agenda_tipo_de_reserva($reserva) ?? $tipo;
render('agenda-ok', ['r' => $reserva, 'tipo' => $tipo, 'bodyClass' => $bodyClass], 'Reunión confirmada');

<?php
declare(strict_types=1);

require_once __DIR__ . '/gcal.php';

// ============================================================
//  Agenda propia de reuniones — nuestro reemplazo de Calendly.
//
//  Cómo funciona, de punta a punta:
//    1. Cada vendedor define uno o varios «tipos de reunión» (duración,
//       horario laboral, antelación mínima, hasta cuántos días a futuro).
//    2. El enlace público /r/<usuario>/<tipo> muestra los huecos reales:
//       horario laboral MENOS lo que ya está ocupado en su Google Calendar
//       MENOS lo que ya reservaron por acá.
//    3. Al reservar se crea el evento en Google Calendar con Google Meet,
//       se manda la confirmación y queda una Reunión en el CRM.
//
//  No dependemos de ningún servicio externo ni de ninguna cuenta paga.
// ============================================================

function agenda_tipos_de(int $usuarioId, bool $soloActivos = true): array
{
    $w = 'WHERE usuario_id = ?' . ($soloActivos ? ' AND activo = 1' : '');
    return q("SELECT * FROM agenda_tipos $w ORDER BY nombre", [$usuarioId])->fetchAll();
}

function agenda_tipo_get(int $id): ?array
{
    return q1('SELECT t.*, u.nombre AS usuario, u.email AS usuario_email
               FROM agenda_tipos t JOIN usuarios u ON u.id = t.usuario_id
               WHERE t.id = ?', [$id]);
}

/** Busca un tipo por la URL pública: /r/<usuario>/<slug>. */
function agenda_tipo_por_slug(string $usuario, string $slug): ?array
{
    return q1("SELECT t.*, u.nombre AS usuario, u.email AS usuario_email
               FROM agenda_tipos t JOIN usuarios u ON u.id = t.usuario_id
               WHERE t.slug = ? AND t.activo = 1 AND u.activo = 1
                 AND SUBSTRING_INDEX(u.email, '@', 1) = ?
               LIMIT 1", [$slug, $usuario]);
}

function agenda_horarios_de(int $tipoId): array
{
    return q('SELECT * FROM agenda_horarios WHERE tipo_id = ? ORDER BY dia, desde', [$tipoId])->fetchAll();
}

/** Convierte un nombre en un slug apto para la URL pública. */
function slugificar(string $texto): string
{
    $s = plano($texto);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'reunion';
}

function agenda_tipo_guardar(array $d, ?int $id = null): int
{
    $campos = [
        'usuario_id'      => (int)($d['usuario_id'] ?? current_user_id()),
        'slug'            => slugificar((string)(($d['slug'] ?? null) ?: $d['nombre'] ?? '')),
        'nombre'          => trim((string)($d['nombre'] ?? '')),
        'descripcion'     => ($d['descripcion'] ?? null) ?: null,
        'duracion_min'    => max(5,  (int)($d['duracion_min'] ?? 30)),
        'margen_min'      => max(0,  (int)($d['margen_min'] ?? 10)),
        'aviso_min_horas' => max(0,  (int)($d['aviso_min_horas'] ?? 4)),
        'ventana_dias'    => max(1,  (int)($d['ventana_dias'] ?? 30)),
        'con_meet'        => !empty($d['con_meet']) ? 1 : 0,
        'activo'          => !empty($d['activo']) ? 1 : 0,
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE agenda_tipos SET $sets WHERE id = ?", [...array_values($campos), $id]);
    } else {
        $cols = implode(', ', array_keys($campos));
        $ph   = implode(', ', array_fill(0, count($campos), '?'));
        q("INSERT INTO agenda_tipos ($cols) VALUES ($ph)", array_values($campos));
        $id = last_id();
    }
    // Horario laboral: se reemplaza completo en cada guardado.
    q('DELETE FROM agenda_horarios WHERE tipo_id = ?', [$id]);
    foreach ((array)($d['dias'] ?? []) as $dia) {
        $desde = $d['desde'][$dia] ?? '09:00';
        $hasta = $d['hasta'][$dia] ?? '18:00';
        if ($desde >= $hasta) { continue; }
        q('INSERT INTO agenda_horarios (tipo_id, dia, desde, hasta) VALUES (?, ?, ?, ?)',
          [$id, (int)$dia, $desde . ':00', $hasta . ':00']);
    }
    log_app('agenda.tipo_guardar', ['entidad' => 'agenda_tipo', 'entidad_id' => $id,
        'detalle' => $campos['nombre'] . ' · ' . $campos['duracion_min'] . ' min']);
    return $id;
}

/**
 * Huecos disponibles de un tipo de reunión, día por día.
 *
 * Si no se puede consultar el calendario de Google devuelve una lista vacía y
 * deja el motivo en $motivo. NO publica los horarios «por las dudas»: sin ver
 * el calendario no hay forma de saber qué está ocupado, y ofrecer todo a ciegas
 * termina con dos reuniones encima de la misma hora. Es preferible que la
 * página diga que no hay horarios a que agende sobre algo que ya estaba.
 *
 * @param string|null $motivo sale con el motivo cuando no se pudo consultar
 * @return array<string,array<int,int>> fecha (Y-m-d) => lista de timestamps de inicio
 */
function agenda_huecos(array $tipo, int $dias = 0, ?string &$motivo = null): array
{
    $motivo = null;
    $dias = $dias > 0 ? $dias : (int)$tipo['ventana_dias'];
    $duracion = (int)$tipo['duracion_min'] * 60;
    $margen   = (int)$tipo['margen_min'] * 60;
    $desdeTs  = time() + ((int)$tipo['aviso_min_horas'] * 3600);
    $hastaTs  = strtotime('+' . $dias . ' days', strtotime('today')) ;

    // Horario laboral por día de la semana.
    $porDia = [];
    foreach (agenda_horarios_de((int)$tipo['id']) as $h) {
        $porDia[(int)$h['dia']][] = [$h['desde'], $h['hasta']];
    }
    if (!$porDia) { return []; }

    // Ocupado: Google Calendar + lo ya reservado en el CRM.
    $ocupado = gcal_ocupado((int)$tipo['usuario_id'], date('c', time()), date('c', $hastaTs));
    if ($ocupado === null) {
        $motivo = 'sin_calendario';
        return [];
    }
    foreach (q("SELECT inicia_el, termina_el FROM agenda_reservas
                WHERE usuario_id = ? AND estado = 'confirmada' AND termina_el >= NOW()",
               [(int)$tipo['usuario_id']])->fetchAll() as $r) {
        $ocupado[] = [strtotime((string)$r['inicia_el']), strtotime((string)$r['termina_el'])];
    }

    $libre = static function (int $ini, int $fin) use ($ocupado, $margen): bool {
        foreach ($ocupado as [$oi, $of]) {
            if ($ini < $of + $margen && $oi - $margen < $fin) { return false; }
        }
        return true;
    };

    $huecos = [];
    for ($d = 0; $d <= $dias; $d++) {
        $fecha = date('Y-m-d', strtotime("+$d days", strtotime('today')));
        $dow   = (int) date('w', strtotime($fecha));
        foreach ($porDia[$dow] ?? [] as [$desde, $hasta]) {
            $ini = strtotime("$fecha $desde");
            $fin = strtotime("$fecha $hasta");
            for ($t = $ini; $t + $duracion <= $fin; $t += $duracion) {
                if ($t < $desdeTs)            { continue; }
                if (!$libre($t, $t + $duracion)) { continue; }
                $huecos[$fecha][] = $t;
            }
        }
    }
    return $huecos;
}

/**
 * Registra una reserva: crea el evento en Google Calendar con Meet, deja la
 * reunión en el CRM y enlaza (o crea) el contacto.
 *
 * @return array la reserva creada
 */
function agenda_reservar(array $tipo, array $d): array
{
    $inicio = (int)($d['inicio'] ?? 0);
    $fin    = $inicio + ((int)$tipo['duracion_min'] * 60);
    $email  = normalizar_email((string)($d['email'] ?? ''));
    $nombre = trim((string)($d['nombre'] ?? ''));

    if (!email_valido($email)) { throw new RuntimeException('El correo no es válido.'); }
    if ($nombre === '')        { throw new RuntimeException('Falta el nombre.'); }
    if ($inicio < time())      { throw new RuntimeException('Ese horario ya pasó.'); }
    // El teléfono lo escribe alguien de afuera de la empresa, así que hay que
    // comprobarlo del lado del servidor: `type="tel"` en el formulario no
    // valida nada, es sólo una pista para el teclado del celular. Sin esto se
    // podían mandar letras y quedaban guardadas como número de contacto.
    if (!telefono_valido($d['telefono'] ?? null)) {
        throw new RuntimeException('El teléfono no parece un número. Poné sólo números; '
            . 'se aceptan también + ( ) - . y espacios.');
    }

    // Verificación final contra doble reserva: entre que el visitante eligió el
    // horario y apretó "confirmar", otro pudo haber tomado el mismo hueco.
    $choque = qv("SELECT 1 FROM agenda_reservas
                  WHERE usuario_id = ? AND estado = 'confirmada'
                    AND inicia_el < ? AND termina_el > ?",
                 [(int)$tipo['usuario_id'], date('Y-m-d H:i:s', $fin), date('Y-m-d H:i:s', $inicio)]);
    if ($choque) { throw new RuntimeException('Justo tomaron ese horario. Elegí otro, por favor.'); }

    $token = token32();
    q('INSERT INTO agenda_reservas (tipo_id, usuario_id, token, inicia_el, termina_el,
                                    invitado_nombre, invitado_email, invitado_empresa, invitado_telefono,
                                    comentario, campana_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        (int)$tipo['id'], (int)$tipo['usuario_id'], $token,
        date('Y-m-d H:i:s', $inicio), date('Y-m-d H:i:s', $fin),
        $nombre, $email, ($d['empresa'] ?? null) ?: null, ($d['telefono'] ?? null) ?: null,
        ($d['comentario'] ?? null) ?: null, ($d['campana_id'] ?? null) ?: null,
    ]);
    $reservaId = last_id();

    // Google Calendar + Meet. Si falla, la reserva NO se pierde: queda igual y
    // el vendedor la ve en el CRM con el aviso de que no se creó el evento.
    $eventId = ''; $meet = '';
    try {
        [$eventId, $meet] = gcal_crear_evento((int)$tipo['usuario_id'], [
            'inicio'      => $inicio,
            'fin'         => $fin,
            'titulo'      => $tipo['nombre'] . ' · ' . $nombre . ($d['empresa'] ? ' (' . $d['empresa'] . ')' : ''),
            'descripcion' => trim(($d['comentario'] ?? '') . "\n\nReserva desde el CRM de INTELLIHELP."),
            'invitados'   => [$email, $tipo['usuario_email']],
            'con_meet'    => (bool)$tipo['con_meet'],
        ]);
        q('UPDATE agenda_reservas SET gcal_event_id = ?, meet_url = ? WHERE id = ?', [$eventId, $meet, $reservaId]);
    } catch (\Throwable $e) {
        log_app('agenda.gcal_error', ['tipo' => 'error', 'entidad' => 'reserva', 'entidad_id' => $reservaId,
            'detalle' => $e->getMessage()]);
    }

    // Enlazar (o crear) el contacto para que la reunión quede en su ficha.
    $contacto = q1('SELECT id FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$email]);
    $contactoId = $contacto ? (int)$contacto['id'] : null;
    if (!$contactoId) {
        $partes = preg_split('/\s+/', $nombre) ?: [];
        $contactoId = contacto_guardar([
            'nombre'         => count($partes) > 1 ? $partes[0] : '',
            'apellido'       => count($partes) > 1 ? implode(' ', array_slice($partes, 1)) : $nombre,
            'email'          => $email,
            'telefono'       => $d['telefono'] ?? '',
            'propietario_id' => (int)$tipo['usuario_id'],
            'fuente'         => 'Sitio web',
            'cuenta_id'      => !empty($d['empresa']) ? cuenta_asegurar((string)($d['empresa'] ?? ''), [], (int)$tipo['usuario_id']) : null,
            'descripcion'    => 'Alta automática al reservar una reunión desde la agenda pública.',
        ]);
    }

    // La reunión, dentro del CRM.
    $actividadId = actividad_guardar([
        'tipo'           => 'reunion',
        'asunto'         => $tipo['nombre'] . ' con ' . $nombre,
        'propietario_id' => (int)$tipo['usuario_id'],
        'contacto_id'    => $contactoId,
        'inicia_el'      => date('Y-m-d H:i:s', $inicio),
        'termina_el'     => date('Y-m-d H:i:s', $fin),
        'estado'         => 'No iniciada',
        'lugar'          => $meet !== '' ? 'Google Meet' : 'A confirmar',
        'descripcion'    => trim((string)($d['comentario'] ?? '')),
    ]);
    q('UPDATE actividades SET gcal_event_id = ?, meet_url = ? WHERE id = ?', [$eventId ?: null, $meet ?: null, $actividadId]);
    q('INSERT INTO actividad_invitados (actividad_id, email, nombre) VALUES (?, ?, ?)', [$actividadId, $email, $nombre]);
    q('UPDATE agenda_reservas SET contacto_id = ?, actividad_id = ? WHERE id = ?',
      [$contactoId, $actividadId, $reservaId]);

    // Si la reserva vino de una campaña, cuenta como resultado de esa campaña.
    if (!empty($d['campana_id'])) {
        q('UPDATE campanas SET reuniones = reuniones + 1 WHERE id = ?', [(int)($d['campana_id'] ?? 0)]);
    }

    log_app('agenda.reserva', ['tipo' => 'sistema', 'entidad' => 'reserva', 'entidad_id' => $reservaId,
        'actor' => $email,
        'detalle' => $tipo['nombre'] . ' el ' . fecha_hora(date('Y-m-d H:i:s', $inicio))
                   . ($meet ? ' · Meet creado' : ' · sin Meet')]);

    $reserva = q1('SELECT * FROM agenda_reservas WHERE id = ?', [$reservaId]) ?? [];
    agenda_avisar($reserva, $tipo, 'confirmacion');
    return $reserva;
}

/**
 * Mueve una reunión a otro horario.
 *
 * La reserva vieja queda como «reprogramada» y se crea una nueva «confirmada»
 * con el horario nuevo. Se hace así, y no pisando la fila, por dos motivos: la
 * disponibilidad se calcula sobre las confirmadas, así que el hueco viejo se
 * libera solo y el nuevo se ocupa; y queda el historial de que esa reunión se
 * movió, que es justo lo que el filtro «Reprogramadas» mostraba vacío porque
 * nada generaba ese estado.
 *
 * El evento de Google es el mismo: se mueve, no se borra y se vuelve a crear,
 * así la reunión no desaparece del calendario de quien ya la había aceptado.
 *
 * @return array la reserva nueva
 */
function agenda_reprogramar(string $token, int $nuevoInicio): array
{
    $r = q1("SELECT * FROM agenda_reservas WHERE token = ? AND estado = 'confirmada'", [$token]);
    if (!$r)                    { throw new RuntimeException('Esa reunión ya no está activa.'); }
    if ($nuevoInicio < time())  { throw new RuntimeException('Ese horario ya pasó.'); }

    $tipo = agenda_tipo_get((int)$r['tipo_id']);
    if (!$tipo) { throw new RuntimeException('El tipo de reunión ya no existe.'); }
    $fin = $nuevoInicio + ((int)$tipo['duracion_min'] * 60);

    // Que no se pise con otra reunión ya tomada (sin contar la que se mueve).
    $choque = qv("SELECT 1 FROM agenda_reservas
                  WHERE usuario_id = ? AND estado = 'confirmada' AND id <> ?
                    AND inicia_el < ? AND termina_el > ?",
                 [(int)$r['usuario_id'], (int)$r['id'],
                  date('Y-m-d H:i:s', $fin), date('Y-m-d H:i:s', $nuevoInicio)]);
    if ($choque) { throw new RuntimeException('Ya hay otra reunión en ese horario.'); }

    if (!empty($r['gcal_event_id'])) {
        try {
            if (!gcal_mover_evento((int)$r['usuario_id'], (string)$r['gcal_event_id'], $nuevoInicio, $fin)) {
                log_app('agenda.gcal_mover_error', ['tipo' => 'error', 'entidad' => 'reserva',
                    'entidad_id' => (int)$r['id'],
                    'detalle' => 'La reunión se movió en el CRM pero Google no aceptó el cambio: '
                               . 'hay que corregirla a mano en el calendario.']);
            }
        } catch (\Throwable $e) {
            log_app('agenda.gcal_mover_error', ['tipo' => 'error', 'entidad' => 'reserva',
                'entidad_id' => (int)$r['id'], 'detalle' => $e->getMessage()]);
        }
    }

    q("UPDATE agenda_reservas SET estado = 'reprogramada' WHERE id = ?", [(int)$r['id']]);

    $nuevoToken = token32();
    q('INSERT INTO agenda_reservas (tipo_id, usuario_id, token, inicia_el, termina_el,
                                    invitado_nombre, invitado_email, invitado_empresa, invitado_telefono,
                                    comentario, campana_id, contacto_id, actividad_id, gcal_event_id, meet_url)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        (int)$r['tipo_id'], (int)$r['usuario_id'], $nuevoToken,
        date('Y-m-d H:i:s', $nuevoInicio), date('Y-m-d H:i:s', $fin),
        $r['invitado_nombre'], $r['invitado_email'], $r['invitado_empresa'], $r['invitado_telefono'],
        $r['comentario'], $r['campana_id'], $r['contacto_id'], $r['actividad_id'],
        $r['gcal_event_id'], $r['meet_url'],
    ]);
    $nueva = q1('SELECT * FROM agenda_reservas WHERE id = ?', [last_id()]) ?? [];

    if (!empty($r['actividad_id'])) {
        q('UPDATE actividades SET inicia_el = ?, termina_el = ? WHERE id = ?',
          [date('Y-m-d H:i:s', $nuevoInicio), date('Y-m-d H:i:s', $fin), (int)$r['actividad_id']]);
    }

    log_app('agenda.reprogramada', ['tipo' => 'accion', 'entidad' => 'reserva',
        'entidad_id' => (int)$nueva['id'],
        'detalle' => $r['invitado_nombre'] . ': de ' . fecha_hora((string)$r['inicia_el'])
                   . ' a ' . fecha_hora(date('Y-m-d H:i:s', $nuevoInicio))]);

    agenda_avisar($nueva, $tipo, 'confirmacion');
    return $nueva;
}

/** Cancela una reserva (desde el enlace del correo o desde el CRM). */
function agenda_cancelar(string $token, string $motivo = ''): bool
{
    $r = q1("SELECT * FROM agenda_reservas WHERE token = ? AND estado = 'confirmada'", [$token]);
    if (!$r) { return false; }
    if (!empty($r['gcal_event_id'])) {
        // El fallo de Google acá se tragaba con un catch vacío y encima se
        // ignoraba el valor de retorno: la reunión quedaba cancelada en el CRM
        // y viva en el calendario del vendedor, que la seguía viendo ocupada.
        try {
            if (!gcal_borrar_evento((int)$r['usuario_id'], (string)$r['gcal_event_id'])) {
                log_app('agenda.gcal_borrar_error', ['tipo' => 'error', 'entidad' => 'reserva',
                    'entidad_id' => (int)$r['id'],
                    'detalle' => 'La reunión se canceló en el CRM pero Google no borró el evento: '
                               . 'hay que sacarlo del calendario a mano.']);
            }
        } catch (\Throwable $e) {
            log_app('agenda.gcal_borrar_error', ['tipo' => 'error', 'entidad' => 'reserva',
                'entidad_id' => (int)$r['id'], 'detalle' => $e->getMessage()]);
        }
    }
    q("UPDATE agenda_reservas SET estado = 'cancelada' WHERE id = ?", [(int)$r['id']]);
    if (!empty($r['actividad_id'])) {
        q('UPDATE actividades SET eliminado_en = NOW() WHERE id = ?', [(int)$r['actividad_id']]);
    }
    log_app('agenda.cancelacion', ['tipo' => 'sistema', 'entidad' => 'reserva', 'entidad_id' => (int)$r['id'],
        'actor' => $r['invitado_email'],
        'detalle' => 'Reunión cancelada' . ($motivo !== '' ? ': ' . recortar($motivo, 200) : '')]);

    // Aviso de la baja con la invitación de cancelación: sin esto, a quien ya
    // había aceptado le quedaba la reunión en el calendario para siempre.
    $tipo = agenda_tipo_get((int)$r['tipo_id']);
    if ($tipo) {
        $r['estado'] = 'cancelada';
        agenda_avisar($r, $tipo, 'cancelacion');
    }
    return true;
}

/**
 * Arma la invitación de calendario (formato iCalendar) de una reserva.
 *
 * Esto es lo que hace que Gmail y Outlook muestren los botones «Sí / No /
 * Quizás» arriba del correo y que la reunión se pueda meter en el calendario
 * con un clic. Antes el correo avisaba de la reunión pero no había forma de
 * aceptarla: la única invitación real la mandaba Google, y si la API de
 * Calendar no respondía —que es justo lo que estaba pasando— no llegaba nada.
 * Esta invitación se arma acá, así que funciona aunque Google esté caído.
 *
 * @param string $metodo REQUEST para invitar, CANCEL para dar de baja
 */
function agenda_ics(array $reserva, array $tipo, string $metodo = 'REQUEST'): string
{
    $utc = static fn(string $f): string => gmdate('Ymd\THis\Z', strtotime($f));

    // El identificador tiene que ser SIEMPRE el mismo para la misma reunión:
    // es lo que permite que una cancelación pise a la invitación original en
    // vez de aparecer como un evento nuevo.
    $uid   = 'reserva-' . (int)$reserva['id'] . '-' . $reserva['token'] . '@intellihelp.tech';

    // El organizador tiene que ser la MISMA dirección desde la que sale el
    // correo. Outlook y Exchange comparan las dos y descartan la invitación
    // cuando no coinciden: se veía el adjunto pero no aparecían los botones
    // para aceptar. El nombre que se muestra sigue siendo el de la persona con
    // la que es la reunión.
    $smtp  = smtp_cfg();
    $orgEm = normalizar_email((string)(($smtp['from'] ?? '') ?: ($smtp['user'] ?? '')))
          ?: (string)($tipo['usuario_email'] ?? setting_get('empresa_email', 'info@intellihelp.tech'));
    $meet  = (string)($reserva['meet_url'] ?? '');
    $lugar = $meet !== '' ? 'Google Meet' : 'A confirmar';

    $desc = 'Reunión coordinada desde ' . setting_get('empresa_nombre', 'INTELLIHELP') . '.';
    if ($meet !== '')                     { $desc .= '\\n\\nEnlace: ' . $meet; }
    if (!empty($reserva['comentario']))   { $desc .= '\\n\\nComentario: ' . (string)$reserva['comentario']; }
    $desc .= '\\n\\nPara cancelar: ' . url_abs('r/cancelar?t=' . $reserva['token']);

    // Escapado del formato: coma, punto y coma y barra son caracteres de control.
    $esc = static fn(string $s): string => str_replace(
        ["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], trim($s));

    $lineas = [
        'BEGIN:VCALENDAR',
        'PRODID:-//INTELLIHELP//CRM//ES',
        'VERSION:2.0',
        'CALSCALE:GREGORIAN',
        'METHOD:' . $metodo,
        'BEGIN:VEVENT',
        'UID:' . $uid,
        // La secuencia sube en la cancelación para que el cliente entienda que
        // es una actualización de la invitación anterior y no otra reunión.
        'SEQUENCE:' . ($metodo === 'CANCEL' ? 1 : 0),
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $utc((string)$reserva['inicia_el']),
        'DTEND:'   . $utc((string)$reserva['termina_el']),
        'SUMMARY:' . $esc((string)($tipo['nombre'] ?? 'Reunión') . ' · ' . setting_get('empresa_nombre', 'INTELLIHELP')),
        'DESCRIPTION:' . $esc($desc),
        'LOCATION:' . $esc($meet !== '' ? $meet : $lugar),
        'ORGANIZER;CN=' . $esc((string)($tipo['usuario'] ?? 'INTELLIHELP')) . ':mailto:' . $orgEm,
        'ATTENDEE;CN=' . $esc((string)$reserva['invitado_nombre'])
            . ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . (string)$reserva['invitado_email'],
        'STATUS:' . ($metodo === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED'),
        'BEGIN:VALARM',
        'TRIGGER:-PT30M',
        'ACTION:DISPLAY',
        'DESCRIPTION:Recordatorio',
        'END:VALARM',
        'END:VEVENT',
        'END:VCALENDAR',
    ];

    return ics_plegar($lineas);
}

/**
 * Manda el correo de confirmación, el recordatorio o el aviso de cancelación.
 *
 * Usa el mismo motor de bloques que las campañas, así que sale con el diseño de
 * la empresa y respeta las reglas del correo (tablas y no divs, estilos en
 * línea, ancho de 600, versión en texto plano). Antes era HTML escrito a mano
 * que llegaba como un título violeta y tres párrafos sueltos.
 */
function agenda_avisar(array $reserva, array $tipo, string $clase = 'confirmacion'): bool
{
    if (!smtp_configurado() || empty($reserva['invitado_email'])) { return false; }

    $inicio  = strtotime((string)$reserva['inicia_el']);
    $cuando  = ucfirst(fecha_larga((string)$reserva['inicia_el'])) . ' a las ' . date('H:i', $inicio) . ' h';
    $cancel  = url_abs('r/cancelar?t=' . $reserva['token']);
    $meet    = (string)($reserva['meet_url'] ?? '');
    $conQuien = (string)($tipo['usuario'] ?? setting_get('empresa_nombre', 'INTELLIHELP'));

    $titulos = [
        'confirmacion' => 'Reunión confirmada',
        'recordatorio' => 'Te recordamos tu reunión',
        'cancelacion'  => 'Reunión cancelada',
    ];
    $titulo = $titulos[$clase] ?? $titulos['confirmacion'];

    $entrada = match ($clase) {
        'recordatorio' => 'Te escribimos para recordarte la reunión que tenemos coordinada.',
        'cancelacion'  => 'Te confirmamos que la reunión quedó cancelada. No tenés que hacer nada más.',
        default        => 'Listo, ya está reservado el horario. Te dejamos los datos por las dudas.',
    };

    // Los datos de la reunión van en un recuadro para que se lean de un vistazo
    // en el celular, que es donde se abre la mayoría de estos correos.
    $ficha = 'Reunión: ' . (string)($tipo['nombre'] ?? '') . "\n"
           . 'Con: ' . $conQuien . "\n"
           . 'Cuándo: ' . $cuando . ' (hora de Argentina)' . "\n"
           . 'Duración: ' . (int)($tipo['duracion_min'] ?? 30) . ' minutos';

    $bloques = [
        ['tipo' => 'encabezado', 'alto' => 34],
        ['tipo' => 'titulo', 'texto' => $titulo],
        ['tipo' => 'texto',  'texto' => 'Hola ' . (string)$reserva['invitado_nombre'] . ','],
        ['tipo' => 'texto',  'texto' => $entrada],
        ['tipo' => 'destacado', 'texto' => $ficha],
    ];

    if ($clase !== 'cancelacion') {
        if ($meet !== '') {
            $bloques[] = ['tipo' => 'boton', 'texto' => 'Entrar por Google Meet', 'enlace' => $meet,
                          'fondo' => '#663399', 'color' => '#ffffff', 'alineacion' => 'center'];
            $bloques[] = ['tipo' => 'texto', 'texto' => 'Si el botón no te funciona, copiá este enlace: ' . $meet];
        } else {
            $bloques[] = ['tipo' => 'texto',
                          'texto' => 'Te vamos a mandar el enlace de la videollamada antes de la reunión.'];
        }
        if ($clase === 'confirmacion') {
            $bloques[] = ['tipo' => 'texto',
                          'texto' => 'La invitación va adjunta a este correo: aceptala y te queda en tu calendario.'];
        }
        $bloques[] = ['tipo' => 'texto', 'texto' => 'Si no podés asistir, cancelala acá: ' . $cancel];
    }

    $bloques[] = ['tipo' => 'firma', 'cierre' => 'Nos vemos,',
                  'firma' => $conQuien, 'cargo' => (string) setting_get('empresa_nombre', 'INTELLIHELP')];
    $bloques[] = ['tipo' => 'contacto'];

    $ajustes = ['fondo' => '#f6f5fa', 'tarjeta' => '#ffffff', 'marca' => '#663399',
                'acento' => '#9CC930', 'texto' => '#3a3f4b', 'titulo' => '#161922',
                'preencabezado' => $cuando];

    // Sin este contexto el correo sale sin logo y el bloque de contacto queda
    // vacío, aunque los datos estén cargados en Ajustes.
    $ctx   = contexto_marca();
    $html  = bloques_render($bloques, $ajustes, $ctx);
    $texto = bloques_texto($bloques, $ctx);

    // La invitación no se manda con la cancelación desde el CRM porque ahí ya
    // se avisó por Google; acá va el CANCEL para que se borre del calendario
    // de quien la había aceptado.
    // El método de la invitación cambia según el aviso:
    //   confirmación → REQUEST: es la invitación de verdad, con los botones
    //                  «Sí / No / Quizás».
    //   recordatorio → PUBLISH: informativo. Con REQUEST el cliente volvía a
    //                  pedirle al invitado que aceptara algo que ya había
    //                  aceptado, y en Outlook aparecía como una invitación
    //                  nueva y duplicada.
    //   cancelación  → CANCEL: saca la reunión del calendario de quien la
    //                  tenía aceptada.
    $metodo = match ($clase) {
        'cancelacion'  => 'CANCEL',
        'recordatorio' => 'PUBLISH',
        default        => 'REQUEST',
    };
    $extra = ['texto' => $texto, 'ics' => agenda_ics($reserva, $tipo, $metodo), 'ics_metodo' => $metodo];

    // Responder al correo tiene que llegarle a la persona con la que es la
    // reunión, no a la casilla del sistema: si alguien contesta «no puedo, lo
    // corremos», esa respuesta no puede caer en un buzón que nadie mira.
    if (!empty($tipo['usuario_email'])) {
        $extra['responder_a'] = (string)$tipo['usuario_email'];
    }

    $err = null;
    if (!enviar_correo_simple((string)$reserva['invitado_email'],
                              $titulo . ' · ' . (string)($tipo['nombre'] ?? ''), $html, $err, $extra)) {
        log_app('agenda.correo_error', ['tipo' => 'error', 'entidad' => 'reserva',
            'entidad_id' => (int)$reserva['id'], 'detalle' => (string)$err]);
        return false;
    }
    return true;
}

/** Manda los recordatorios de las reuniones de las próximas 24 h (lo llama el cron). */
function agenda_recordatorios(): int
{
    $filas = q("SELECT r.*, t.nombre, t.duracion_min, t.con_meet, u.nombre AS usuario, u.email AS usuario_email
                FROM agenda_reservas r
                JOIN agenda_tipos t ON t.id = r.tipo_id
                JOIN usuarios u     ON u.id = r.usuario_id
                WHERE r.estado = 'confirmada' AND r.recordatorio_enviado_en IS NULL
                  AND r.inicia_el BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)")->fetchAll();
    $n = 0;
    foreach ($filas as $r) {
        // Sólo se marca si SALIÓ. Antes se marcaba siempre, así que un correo
        // que falló —o que ni se intentó porque no había casilla configurada—
        // quedaba como enviado y no se reintentaba nunca: el recordatorio se
        // perdía y la base decía que había salido.
        if (!agenda_avisar($r, $r, 'recordatorio')) { continue; }
        q('UPDATE agenda_reservas SET recordatorio_enviado_en = NOW() WHERE id = ?', [(int)$r['id']]);
        $n++;
    }
    return $n;
}

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
        'slug'            => slugificar((string)($d['slug'] ?: $d['nombre'] ?? '')),
        'nombre'          => trim((string)($d['nombre'] ?? '')),
        'descripcion'     => $d['descripcion'] ?: null,
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
 * @return array<string,array<int,int>> fecha (Y-m-d) => lista de timestamps de inicio
 */
function agenda_huecos(array $tipo, int $dias = 0): array
{
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
    $inicio = (int)$d['inicio'];
    $fin    = $inicio + ((int)$tipo['duracion_min'] * 60);
    $email  = normalizar_email((string)($d['email'] ?? ''));
    $nombre = trim((string)($d['nombre'] ?? ''));

    if (!email_valido($email)) { throw new RuntimeException('El correo no es válido.'); }
    if ($nombre === '')        { throw new RuntimeException('Falta el nombre.'); }
    if ($inicio < time())      { throw new RuntimeException('Ese horario ya pasó.'); }

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
        $nombre, $email, $d['empresa'] ?: null, $d['telefono'] ?: null,
        $d['comentario'] ?: null, $d['campana_id'] ?: null,
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
            'cuenta_id'      => !empty($d['empresa']) ? cuenta_asegurar((string)$d['empresa'], [], (int)$tipo['usuario_id']) : null,
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
        q('UPDATE campanas SET reuniones = reuniones + 1 WHERE id = ?', [(int)$d['campana_id']]);
    }

    log_app('agenda.reserva', ['tipo' => 'sistema', 'entidad' => 'reserva', 'entidad_id' => $reservaId,
        'actor' => $email,
        'detalle' => $tipo['nombre'] . ' el ' . fecha_hora(date('Y-m-d H:i:s', $inicio))
                   . ($meet ? ' · Meet creado' : ' · sin Meet')]);

    $reserva = q1('SELECT * FROM agenda_reservas WHERE id = ?', [$reservaId]) ?? [];
    agenda_avisar($reserva, $tipo, 'confirmacion');
    return $reserva;
}

/** Cancela una reserva (desde el enlace del correo o desde el CRM). */
function agenda_cancelar(string $token, string $motivo = ''): bool
{
    $r = q1("SELECT * FROM agenda_reservas WHERE token = ? AND estado = 'confirmada'", [$token]);
    if (!$r) { return false; }
    if (!empty($r['gcal_event_id'])) {
        try { gcal_borrar_evento((int)$r['usuario_id'], (string)$r['gcal_event_id']); } catch (\Throwable $e) {}
    }
    q("UPDATE agenda_reservas SET estado = 'cancelada' WHERE id = ?", [(int)$r['id']]);
    if (!empty($r['actividad_id'])) {
        q('UPDATE actividades SET eliminado_en = NOW() WHERE id = ?', [(int)$r['actividad_id']]);
    }
    log_app('agenda.cancelacion', ['tipo' => 'sistema', 'entidad' => 'reserva', 'entidad_id' => (int)$r['id'],
        'actor' => $r['invitado_email'],
        'detalle' => 'Reunión cancelada' . ($motivo !== '' ? ': ' . recortar($motivo, 200) : '')]);
    return true;
}

/** Manda el correo de confirmación o el recordatorio. */
function agenda_avisar(array $reserva, array $tipo, string $clase = 'confirmacion'): void
{
    if (!smtp_configurado() || empty($reserva['invitado_email'])) { return; }

    $cuando  = fecha_larga((string)$reserva['inicia_el']) . ' a las ' . date('H:i', strtotime((string)$reserva['inicia_el'])) . ' h';
    $cancel  = url_abs('r/cancelar?t=' . $reserva['token']);
    $titulo  = $clase === 'recordatorio' ? 'Te recordamos tu reunión' : 'Reunión confirmada';
    $meet    = (string)($reserva['meet_url'] ?? '');

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#161922;max-width:560px">'
          . '<h2 style="color:#663399;margin:0 0 12px">' . e($titulo) . '</h2>'
          . '<p>Hola ' . e((string)$reserva['invitado_nombre']) . ',</p>'
          . '<p>Tu reunión <strong>' . e((string)$tipo['nombre']) . '</strong> con '
          . e((string)$tipo['usuario']) . ' quedó agendada para el <strong>' . e($cuando) . '</strong> '
          . '(hora de Argentina), con una duración de ' . (int)$tipo['duracion_min'] . ' minutos.</p>';
    if ($meet !== '') {
        $html .= '<p style="margin:20px 0"><a href="' . e($meet) . '" '
               . 'style="background:#663399;color:#fff;padding:12px 20px;border-radius:10px;'
               . 'text-decoration:none;font-weight:bold;display:inline-block">Entrar por Google Meet</a></p>'
               . '<p style="font-size:12px;color:#6f6a7d">O copiá este enlace: ' . e($meet) . '</p>';
    }
    $html .= '<p style="font-size:12px;color:#6f6a7d;margin-top:24px">'
           . 'Si no podés asistir, <a href="' . e($cancel) . '" style="color:#663399">cancelá la reunión acá</a>.</p>'
           . '<p style="font-size:12px;color:#8a8596">INTELLIHELP S.A.</p></div>';

    $err = null;
    if (!enviar_correo_simple((string)$reserva['invitado_email'], $titulo . ' · ' . $tipo['nombre'], $html, $err)) {
        log_app('agenda.correo_error', ['tipo' => 'error', 'entidad' => 'reserva',
            'entidad_id' => (int)$reserva['id'], 'detalle' => (string)$err]);
    }
}

/** Manda los recordatorios de las reuniones de las próximas 24 h (lo llama el cron). */
function agenda_recordatorios(): int
{
    $filas = q("SELECT r.*, t.nombre, t.duracion_min, u.nombre AS usuario
                FROM agenda_reservas r
                JOIN agenda_tipos t ON t.id = r.tipo_id
                JOIN usuarios u     ON u.id = r.usuario_id
                WHERE r.estado = 'confirmada' AND r.recordatorio_enviado_en IS NULL
                  AND r.inicia_el BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)")->fetchAll();
    $n = 0;
    foreach ($filas as $r) {
        agenda_avisar($r, $r, 'recordatorio');
        q('UPDATE agenda_reservas SET recordatorio_enviado_en = NOW() WHERE id = ?', [(int)$r['id']]);
        $n++;
    }
    return $n;
}

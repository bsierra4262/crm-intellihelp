<?php
declare(strict_types=1);

// ============================================================
//  Actividades: tareas, llamadas y reuniones.
//  Van en una sola tabla porque comparten casi todos los campos y así la
//  «agenda del día» y la línea de tiempo de un registro salen de una consulta.
// ============================================================

function tipos_actividad(): array
{
    return ['tarea' => 'Tarea', 'llamada' => 'Llamada', 'reunion' => 'Reunión'];
}

function resultados_llamada(): array
{
    return ['Atendió', 'No atendió', 'Buzón de voz', 'Número equivocado',
            'Pidió que lo llamen después', 'Agendó reunión', 'No le interesa'];
}

function actividad_get(int $id): ?array
{
    return q1('SELECT a.*, u.nombre AS propietario, c.nombre_completo AS contacto,
                      cu.nombre AS cuenta, n.nombre AS negocio,
                      pc.empresa AS posible_empresa,
                      TRIM(CONCAT(COALESCE(pc.nombre,\'\'), \' \', pc.apellido)) AS posible
               FROM actividades a
               LEFT JOIN usuarios  u  ON u.id  = a.propietario_id
               LEFT JOIN contactos c  ON c.id  = a.contacto_id
               LEFT JOIN cuentas   cu ON cu.id = a.cuenta_id
               LEFT JOIN negocios  n  ON n.id  = a.negocio_id
               LEFT JOIN posibles_clientes pc ON pc.id = a.posible_cliente_id
               WHERE a.id = ? AND a.eliminado_en IS NULL', [$id]);
}

/** Actividades asociadas a un registro (para el panel lateral de la ficha). */
function actividades_de(string $columna, int $id, int $limite = 20): array
{
    if (!in_array($columna, ['contacto_id', 'cuenta_id', 'negocio_id', 'posible_cliente_id'], true)) {
        throw new InvalidArgumentException('Columna no permitida: ' . $columna);
    }
    return q("SELECT a.*, u.nombre AS propietario FROM actividades a
              LEFT JOIN usuarios u ON u.id = a.propietario_id
              WHERE a.$columna = ? AND a.eliminado_en IS NULL
              ORDER BY a.completada_en IS NOT NULL,
                       COALESCE(a.inicia_el, a.vence_el) DESC LIMIT ?", [$id, $limite])->fetchAll();
}

function actividades_where(array $f): array
{
    $w = ['a.eliminado_en IS NULL'];
    $p = [];
    if (!empty($f['tipo']))           { $w[] = 'a.tipo = ?';           $p[] = $f['tipo']; }
    if (!empty($f['propietario_id'])) { $w[] = 'a.propietario_id = ?'; $p[] = (int)$f['propietario_id']; }
    if (!empty($f['estado']))         { $w[] = 'a.estado = ?';         $p[] = $f['estado']; }
    if (!empty($f['q'])) {
        $w[] = '(a.asunto LIKE ? OR a.descripcion LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like);
    }
    // Vistas rápidas: lo vencido, lo de hoy, lo de la semana.
    $vista = $f['vista'] ?? '';
    if (($f['vencidas'] ?? '') === '1' || $vista === 'vencidas') {
        $w[] = 'a.completada_en IS NULL AND COALESCE(a.vence_el, a.inicia_el) < NOW()';
    } elseif ($vista === 'hoy') {
        $w[] = 'a.completada_en IS NULL AND DATE(COALESCE(a.vence_el, a.inicia_el)) = CURDATE()';
    } elseif ($vista === 'semana') {
        $w[] = 'a.completada_en IS NULL AND COALESCE(a.vence_el, a.inicia_el) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)';
    } elseif ($vista === 'completadas') {
        $w[] = 'a.completada_en IS NOT NULL';
    } else {
        $w[] = 'a.completada_en IS NULL';
    }
    return ['WHERE ' . implode(' AND ', $w), $p];
}

function actividades_listar(array $f): array
{
    [$where, $p] = actividades_where($f);
    $sql = "FROM actividades a
            LEFT JOIN usuarios u ON u.id = a.propietario_id
            LEFT JOIN contactos c ON c.id = a.contacto_id
            LEFT JOIN cuentas cu ON cu.id = a.cuenta_id $where";
    $total = (int) qv("SELECT COUNT(*) $sql", $p);
    $pg    = paginar($total, $f['page'] ?? 1, 25);
    $rows  = q("SELECT a.*, u.nombre AS propietario, c.nombre_completo AS contacto, cu.nombre AS cuenta $sql
                ORDER BY COALESCE(a.vence_el, a.inicia_el) IS NULL,
                         COALESCE(a.vence_el, a.inicia_el)
                LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

/** Alta o edición. Devuelve el id. */
function actividad_guardar(array $d, ?int $id = null): int
{
    $tipo = in_array($d['tipo'] ?? '', array_keys(tipos_actividad()), true) ? $d['tipo'] : 'tarea';

    // Una reunión sin fin explícito dura 30 minutos: es lo que evita eventos
    // sin duración en el calendario.
    $inicia  = ($d['inicia_el'] ?? null) ?: null;
    $termina = ($d['termina_el'] ?? null) ?: null;
    if ($tipo === 'reunion' && $inicia && !$termina) {
        $termina = date('Y-m-d H:i:s', strtotime($inicia) + 1800);
    }

    $campos = [
        'tipo'               => $tipo,
        'asunto'             => trim((string)($d['asunto'] ?? '')),
        'propietario_id'     => ($d['propietario_id'] ?? null) ?: current_user_id(),
        'contacto_id'        => ($d['contacto_id'] ?? null) ?: null,
        'posible_cliente_id' => ($d['posible_cliente_id'] ?? null) ?: null,
        'cuenta_id'          => ($d['cuenta_id'] ?? null) ?: null,
        'negocio_id'         => ($d['negocio_id'] ?? null) ?: null,
        'estado'             => ($d['estado'] ?? null) ?: 'No iniciada',
        'prioridad'          => ($d['prioridad'] ?? null) ?: 'Normal',
        'vence_el'           => $tipo === 'tarea' ? (($d['vence_el'] ?? null) ?: null) : null,
        'inicia_el'          => $tipo === 'tarea' ? null : $inicia,
        'termina_el'         => $tipo === 'tarea' ? null : $termina,
        'duracion_min'       => ($d['duracion_min'] ?? '') !== '' ? (int)($d['duracion_min'] ?? 0) : null,
        'lugar'              => ($d['lugar'] ?? null) ?: null,
        'sentido'            => in_array($d['sentido'] ?? '', ['entrante', 'saliente'], true) ? $d['sentido'] : null,
        'resultado'          => ($d['resultado'] ?? null) ?: null,
        'recordar_min'       => ($d['recordar_min'] ?? '') !== '' ? (int)($d['recordar_min'] ?? 0) : null,
        'descripcion'        => ($d['descripcion'] ?? null) ?: null,
    ];

    // La marca de completada la lleva `completada_en`, no el estado: es la
    // columna que miran los listados, el panel y el contador de vencidas.
    // Elegir «Completada» en el formulario cambiaba sólo el texto del estado y
    // la actividad seguía apareciendo entre las pendientes.
    if ($campos['estado'] === 'Completada') {
        $campos['completada_en'] = date('Y-m-d H:i:s');
    } elseif ($id) {
        $campos['completada_en'] = null;   // se reabre si le sacan el estado
    }

    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE actividades SET $sets WHERE id = ?", [...array_values($campos), $id]);
        log_app('actividad.editar', ['entidad' => 'actividad', 'entidad_id' => $id,
            'detalle' => ucfirst($tipo) . ': ' . $campos['asunto']]);
        return $id;
    }
    $campos['creado_por'] = current_user_id();
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO actividades ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    log_app('actividad.crear', ['entidad' => 'actividad', 'entidad_id' => $nuevo,
        'detalle' => ucfirst($tipo) . ': ' . $campos['asunto']]);

    // La actividad también queda en la línea de tiempo del registro asociado.
    foreach (['contacto_id' => 'contacto', 'cuenta_id' => 'cuenta',
              'negocio_id' => 'negocio', 'posible_cliente_id' => 'posible_cliente'] as $col => $ent) {
        if (!empty($campos[$col])) {
            log_app($ent . '.actividad', ['entidad' => $ent, 'entidad_id' => (int)$campos[$col],
                'detalle' => ucfirst($tipo) . ' agendada: ' . $campos['asunto']]);
        }
    }
    return $nuevo;
}

/** Marca una actividad como completada (o la reabre). */
function actividad_completar(int $id, bool $completar = true, ?string $resultado = null): void
{
    q('UPDATE actividades SET completada_en = ?, estado = ?, resultado = COALESCE(?, resultado) WHERE id = ?',
      [$completar ? date('Y-m-d H:i:s') : null,
       $completar ? 'Completada' : 'En curso',
       $resultado, $id]);
    $a = actividad_get($id);
    log_app('actividad.' . ($completar ? 'completar' : 'reabrir'),
        ['entidad' => 'actividad', 'entidad_id' => $id,
         'detalle' => ($a['asunto'] ?? '') . ($resultado ? ' · ' . $resultado : '')]);

    // Que se vea también en la ficha del contacto/negocio.
    if ($a) {
        foreach (['contacto_id' => 'contacto', 'negocio_id' => 'negocio',
                  'cuenta_id' => 'cuenta', 'posible_cliente_id' => 'posible_cliente'] as $col => $ent) {
            if (!empty($a[$col])) {
                log_app($ent . '.actividad_ok', ['entidad' => $ent, 'entidad_id' => (int)$a[$col],
                    'detalle' => ucfirst((string)$a['tipo']) . ' completada: ' . $a['asunto']
                               . ($resultado ? ' · ' . $resultado : '')]);
            }
        }
        // Fecha de último contacto, para poder filtrar «sin tocar hace 30 días».
        if (!empty($a['contacto_id'])) {
            q('UPDATE contactos SET ultima_actividad_en = NOW() WHERE id = ?', [(int)$a['contacto_id']]);
        }
        if (!empty($a['posible_cliente_id'])) {
            q('UPDATE posibles_clientes SET ultima_actividad_en = NOW() WHERE id = ?', [(int)$a['posible_cliente_id']]);
        }
    }
}

/** Etiqueta y clase de una actividad según su vencimiento. */
function actividad_vencimiento(array $a): array
{
    $cuando = ($a['vence_el'] ?? null) ?: $a['inicia_el'];
    if (!$cuando) {
        return ['—', 'badge-neutral'];
    }
    if (!empty($a['completada_en'])) {
        return [fecha_hora($cuando), 'badge-ok'];
    }
    $t = strtotime($cuando);
    if ($t < time())                       { return [fecha_hora($cuando), 'badge-rej']; }
    if (date('Y-m-d', $t) === date('Y-m-d')) { return ['Hoy ' . date('H:i', $t), 'badge-pend']; }
    return [fecha_hora($cuando), 'badge-info'];
}

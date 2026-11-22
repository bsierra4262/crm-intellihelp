<?php
declare(strict_types=1);

// ============================================================
//  Actividades: tareas, llamadas y reuniones.
//  Van en una sola tabla porque comparten casi todos los campos y así la
//  «agenda del día» y la línea de tiempo de un registro salen de una consulta.
//
//  Tabla `actividades` (no confundir con `actividad`, la auditoría de
//  actividad.php). Cada fila puede colgar de un contacto, un posible
//  cliente, una empresa y/o un negocio (columnas *_id, todas opcionales).
//  Borrado lógico con `eliminado_en` (papelera, ver registro_eliminar()).
//
//  Qué marca una actividad como hecha: `completada_en`, NO el texto de
//  `estado`. Los listados, el panel de las fichas y el contador de vencidas
//  miran `completada_en`.
//  Fechas: una tarea usa `vence_el`; una llamada o reunión, `inicia_el` (y la
//  reunión también `termina_el`). Las consultas usan
//  COALESCE(vence_el, inicia_el) como «cuándo» de cualquier tipo.
//
//  Lo usan: pages/actividades.php, actividad-form.php,
//  actividad-completar.php, las fichas (panel lateral), la agenda (las
//  reuniones reservadas desde la web) y la entrada web (la tarea de
//  responder una consulta). Lo carga bootstrap.php siempre, aunque el módulo
//  `actividades` esté apagado.
//  Permisos: ninguna función de este archivo mira quién es el propietario.
// ============================================================

/** Tipos de actividad: clave guardada en la base => etiqueta en pantalla. */
function tipos_actividad(): array
{
    return ['tarea' => 'Tarea', 'llamada' => 'Llamada', 'reunion' => 'Reunión'];
}

/** Resultados posibles de una llamada (se guardan como texto en `resultado`). */
function resultados_llamada(): array
{
    return ['Atendió', 'No atendió', 'Buzón de voz', 'Número equivocado',
            'Pidió que lo llamen después', 'Agendó reunión', 'No le interesa'];
}

/**
 * Una actividad con los nombres de todo lo que tiene vinculado (propietario,
 * contacto, empresa, negocio y posible cliente), o null si no existe o está
 * en la papelera. Los vínculos se traen aunque el registro vinculado esté
 * eliminado.
 */
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

/**
 * Actividades asociadas a un registro (para el panel lateral de la ficha).
 *
 * Primero las pendientes y después las completadas; dentro de cada grupo,
 * de la más lejana en el tiempo a la más cercana. Con más de $limite
 * actividades, las que quedan afuera no se ven en la ficha (no hay «ver más»).
 *
 * @param string $columna contacto_id | cuenta_id | negocio_id | posible_cliente_id
 * @throws InvalidArgumentException con otra columna
 */
function actividades_de(string $columna, int $id, int $limite = 20): array
{
    // La columna va dentro del SQL (no se puede pasar como parámetro): por
    // eso sólo se aceptan las de la lista.
    if (!in_array($columna, ['contacto_id', 'cuenta_id', 'negocio_id', 'posible_cliente_id'], true)) {
        throw new InvalidArgumentException('Columna no permitida: ' . $columna);
    }
    return q("SELECT a.*, u.nombre AS propietario FROM actividades a
              LEFT JOIN usuarios u ON u.id = a.propietario_id
              WHERE a.$columna = ? AND a.eliminado_en IS NULL
              ORDER BY a.completada_en IS NOT NULL,
                       COALESCE(a.inicia_el, a.vence_el) DESC LIMIT ?", [$id, $limite])->fetchAll();
}

/**
 * WHERE + parámetros para el listado de actividades.
 *
 * Filtros: tipo, propietario_id, estado, q (asunto o descripción), vista
 * (hoy | semana | vencidas | completadas; cualquier otro valor = pendientes)
 * y vencidas=1 (equivale a vista=vencidas). Todo va como parámetro.
 * Las comparaciones con NOW()/CURDATE() usan la hora de MySQL, que db.php
 * alinea con el huso de PHP al conectar.
 *
 * @return array{0:string,1:array}
 */
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

/**
 * Lista paginada de actividades: las que tienen fecha primero, de la más
 * próxima (o la más vencida) a la más lejana; las sin fecha al final. Vale
 * el mismo orden para «Completadas», que entonces arranca por las más viejas.
 *
 * @return array rows (con propietario, contacto y cuenta), total y los datos de paginar()
 */
function actividades_listar(array $f): array
{
    [$where, $p] = actividades_where($f);
    $sql = "FROM actividades a
            LEFT JOIN usuarios u ON u.id = a.propietario_id
            LEFT JOIN contactos c ON c.id = a.contacto_id
            LEFT JOIN cuentas cu ON cu.id = a.cuenta_id $where";
    $total = (int) qv("SELECT COUNT(*) $sql", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    $rows  = q("SELECT a.*, u.nombre AS propietario, c.nombre_completo AS contacto, cu.nombre AS cuenta $sql
                ORDER BY COALESCE(a.vence_el, a.inicia_el) IS NULL,
                         COALESCE(a.vence_el, a.inicia_el)
                LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

/**
 * Alta o edición. Devuelve el id.
 *
 * $d trae los campos del formulario, con las fechas ya en 'Y-m-d H:i:s'
 * (la página convierte el formato de datetime-local). Un tipo desconocido se
 * guarda como 'tarea'. Según el tipo se descartan las fechas que no
 * corresponden: la tarea pierde inicio y fin; la llamada y la reunión, el
 * vencimiento.
 *
 * No valida que existan los ids vinculados ni el propietario, ni que estado
 * y prioridad sean de sus listas, ni el formato de las fechas: eso lo hace
 * antes actividad_validar() (el formulario); la agenda llama directo con
 * datos que ya armó ella. Lo que no cuadra sube como excepción.
 *
 * Efectos: escribe `actividades` y deja la auditoría ('actividad.crear' o
 * 'actividad.editar'). En un alta también deja una marca «agendada» en la
 * línea de tiempo de cada registro vinculado; una edición no.
 *
 * @param int|null $id null para un alta
 */
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
    // Editar una que ya estaba completada (y se deja así) conserva la fecha
    // en que se completó: antes se pisaba con la hora de la edición.
    if ($campos['estado'] === 'Completada') {
        $ya = $id ? qv('SELECT completada_en FROM actividades WHERE id = ?', [$id]) : null;
        $campos['completada_en'] = $ya ?: date('Y-m-d H:i:s');
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

/**
 * Marca una actividad como completada (o la reabre).
 *
 * Completar pone `completada_en` = ahora y estado «Completada»; reabrir
 * limpia `completada_en` y deja el estado en «En curso», sea cual fuera el
 * anterior. El resultado sólo se pisa si viene uno (COALESCE).
 *
 * Efectos: además de la auditoría de la actividad, al COMPLETAR deja una
 * marca en la línea de tiempo de cada registro vinculado y actualiza
 * `ultima_actividad_en` del contacto y del posible cliente. Reabrir no hace
 * ninguna de las dos cosas: no es un contacto con nadie, y ensuciaba el
 * filtro «sin tocar hace 30 días».
 *
 * Una actividad que no existe o está en la papelera no se toca.
 *
 * @return bool false si la actividad no existe o está en la papelera
 */
function actividad_completar(int $id, bool $completar = true, ?string $resultado = null): bool
{
    $a = actividad_get($id);
    if (!$a) { return false; }
    q('UPDATE actividades SET completada_en = ?, estado = ?, resultado = COALESCE(?, resultado) WHERE id = ?',
      [$completar ? date('Y-m-d H:i:s') : null,
       $completar ? 'Completada' : 'En curso',
       $resultado, $id]);
    log_app('actividad.' . ($completar ? 'completar' : 'reabrir'),
        ['entidad' => 'actividad', 'entidad_id' => $id,
         'detalle' => ($a['asunto'] ?? '') . ($resultado ? ' · ' . $resultado : '')]);

    // Que se vea también en la ficha del contacto/negocio.
    if ($completar) {
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
    return true;
}

/**
 * Convierte lo que manda un input datetime-local («2026-11-22T10:30», con o
 * sin segundos) al formato de la base. Vacío da ''; algo que no es una fecha
 * real (30 de febrero, texto) da null.
 */
function actividad_fecha_form(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') { return ''; }
    $v = str_replace('T', ' ', $v);
    foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $fmt) {
        $f = DateTime::createFromFormat($fmt, $v);
        $err = DateTime::getLastErrors();
        if ($f && !($err && ($err['warning_count'] || $err['error_count']))) {
            return $f->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/**
 * Valida lo que llega del formulario de actividades y deja las fechas en el
 * formato de la base. Es lo que antes no se controlaba y terminaba en un
 * error 500: una fecha imposible, un id vinculado que no existe, un estado o
 * una prioridad que no son de la lista.
 *
 * @param array $d el POST del formulario
 * @return array{0:array,1:array} [datos con las fechas convertidas, errores campo => mensaje]
 */
function actividad_validar(array $d): array
{
    $err = [];
    if (trim((string)($d['asunto'] ?? '')) === '') { $err['asunto'] = 'Poné un asunto.'; }
    if (mb_strlen(trim((string)($d['asunto'] ?? ''))) > 200) { $err['asunto'] = 'El asunto no puede pasar de 200 caracteres.'; }

    foreach (['vence_el' => 'El vencimiento', 'inicia_el' => 'El inicio', 'termina_el' => 'El fin'] as $k => $et) {
        $f = actividad_fecha_form(isset($d[$k]) ? (string)$d[$k] : null);
        if ($f === null) { $err[$k] = $et . ' no es una fecha válida.'; $d[$k] = ''; }
        else { $d[$k] = $f; }
    }
    if (($d['tipo'] ?? '') === 'reunion' && $d['inicia_el'] !== '' && $d['termina_el'] !== ''
        && $d['termina_el'] <= $d['inicia_el']) {
        $err['termina_el'] = 'La reunión tiene que terminar después de empezar.';
    }

    if (($d['estado'] ?? '') !== '' && !in_array($d['estado'], estados_actividad(), true)) {
        $err['estado'] = 'El estado no es uno de la lista.';
    }
    if (($d['prioridad'] ?? '') !== '' && !in_array($d['prioridad'], prioridades(), true)) {
        $err['prioridad'] = 'La prioridad no es una de la lista.';
    }
    foreach (['duracion_min' => 'La duración', 'recordar_min' => 'El aviso'] as $k => $et) {
        if (($d[$k] ?? '') !== '' && (!ctype_digit((string)$d[$k]) || (int)$d[$k] > 100000)) {
            $err[$k] = $et . ' tiene que ser una cantidad de minutos.';
        }
    }

    // Un id vinculado tiene que existir (aunque esté en la papelera): si no, la
    // clave foránea hace fallar el guardado.
    foreach (['contacto_id' => ['contactos', 'El contacto'], 'cuenta_id' => ['cuentas', 'La empresa'],
              'negocio_id' => ['negocios', 'El negocio'], 'posible_cliente_id' => ['posibles_clientes', 'El posible cliente'],
              'propietario_id' => ['usuarios', 'El responsable']] as $k => [$tabla, $et]) {
        $v = trim((string)($d[$k] ?? ''));
        if ($v === '') { continue; }
        if (!ctype_digit($v) || !qv("SELECT 1 FROM $tabla WHERE id = ?", [(int)$v])) {
            $err[$k] = $et . ' elegido ya no existe.';
        }
    }
    return [$d, $err];
}

/**
 * Etiqueta y clase de una actividad según su vencimiento.
 *
 *   · sin fecha → «—», neutral;
 *   · completada → la fecha, verde;
 *   · vencida → la fecha, rojo;
 *   · hoy → «Hoy HH:MM», amarillo;
 *   · más adelante → la fecha, azul.
 *
 * @return array{0:string,1:string} texto y clase CSS del distintivo
 */
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

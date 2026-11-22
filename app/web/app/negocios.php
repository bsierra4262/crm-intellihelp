<?php
declare(strict_types=1);

// ============================================================
//  Negocios y embudo de ventas.
//
//  Un negocio es una oportunidad de venta: tiene importe, moneda, fecha
//  estimada de cierre y está siempre en una etapa de un embudo. Las etapas
//  son de tres tipos: 'abierta' (en curso), 'ganada' y 'perdida'; el tipo es
//  lo que decide si el negocio está cerrado, no el nombre de la etapa.
//
//  Tablas: embudos, etapas, negocios, negocio_etapas (historial: un tramo por
//  cada etapa por la que pasó, con desde/hasta) y cuotas (objetivo mensual
//  por vendedor, para el pronóstico). `negocios.ingreso_esperado` es una
//  columna generada en la base (importe × probabilidad / 100): no se escribe
//  nunca desde PHP.
//
//  Lo carga bootstrap.php en todos los pedidos. Las pantallas que lo usan
//  (negocios, negocio-form, negocio-ver, negocio-etapa, embudo, embudos,
//  cuotas) son del módulo «negocios» de modulos.php, que en producción se
//  enciende aparte; reportes usa pronostico() y la conversión de posibles
//  clientes crea negocios por su cuenta (crm.php).
//
//  Montos: hay negocios en ARS y en USD, y las sumas de este archivo
//  (listado, Kanban, pronóstico) suman los importes sin convertir ni separar
//  por moneda.
// ============================================================

/** Los embudos activos; el predeterminado primero. */
function embudos(): array
{
    return q('SELECT * FROM embudos WHERE activo = 1 ORDER BY predeterminado DESC, orden, nombre')->fetchAll();
}

/**
 * El embudo que se usa cuando no se elige otro: el marcado como
 * predeterminado o, si ninguno activo lo está, el primero por orden.
 */
function embudo_predeterminado(): ?array
{
    return q1('SELECT * FROM embudos WHERE activo = 1 ORDER BY predeterminado DESC, orden LIMIT 1');
}

/** Etapas activas de un embudo, en el orden del tablero. */
function etapas_de(int $embudoId): array
{
    return q('SELECT * FROM etapas WHERE embudo_id = ? AND activo = 1 ORDER BY orden', [$embudoId])->fetchAll();
}

/**
 * Una etapa por id, esté activa o no y sea del embudo que sea. Quien la usa
 * para mover un negocio no verifica que esté activa.
 */
function etapa_get(int $id): ?array
{
    return q1('SELECT * FROM etapas WHERE id = ?', [$id]);
}

/**
 * Un negocio vivo con los nombres de su etapa, embudo, cuenta, contacto,
 * propietario y campaña. Null si no existe o está en la papelera.
 */
function negocio_get(int $id): ?array
{
    return q1('SELECT n.*, e.nombre AS etapa, e.tipo AS etapa_tipo, e.color AS etapa_color,
                      em.nombre AS embudo, cu.nombre AS cuenta, c.nombre_completo AS contacto,
                      u.nombre AS propietario, ca.nombre AS campana
               FROM negocios n
               JOIN etapas  e  ON e.id  = n.etapa_id
               JOIN embudos em ON em.id = n.embudo_id
               LEFT JOIN cuentas   cu ON cu.id = n.cuenta_id
               LEFT JOIN contactos c  ON c.id  = n.contacto_id
               LEFT JOIN usuarios  u  ON u.id  = n.propietario_id
               LEFT JOIN campanas  ca ON ca.id = n.campana_id
               WHERE n.id = ? AND n.eliminado_en IS NULL', [$id]);
}

/**
 * WHERE + parámetros de los filtros del listado de negocios.
 *
 * @param array $f q, embudo_id, etapa_id, propietario_id, cuenta_id,
 *                 estado ('abiertos' por defecto, 'ganados', 'perdidos' o
 *                 cualquier otro valor para todos), desde/hasta sobre la
 *                 fecha estimada de cierre
 * @return array{0:string,1:array} [cláusula WHERE, parámetros]
 */
function negocios_where(array $f): array
{
    $w = ['n.eliminado_en IS NULL'];
    $p = [];
    if (!empty($f['q'])) {
        $w[] = '(n.nombre LIKE ? OR cu.nombre LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like);
    }
    if (!empty($f['embudo_id']))      { $w[] = 'n.embudo_id = ?';      $p[] = (int)$f['embudo_id']; }
    if (!empty($f['etapa_id']))       { $w[] = 'n.etapa_id = ?';       $p[] = (int)$f['etapa_id']; }
    if (!empty($f['propietario_id'])) { $w[] = 'n.propietario_id = ?'; $p[] = (int)$f['propietario_id']; }
    if (!empty($f['cuenta_id']))      { $w[] = 'n.cuenta_id = ?';      $p[] = (int)$f['cuenta_id']; }
    // El estado se deduce del tipo de la etapa, así que la consulta que use este
    // WHERE tiene que unir `etapas e`.
    $estado = $f['estado'] ?? 'abiertos';
    if ($estado === 'abiertos')      { $w[] = "e.tipo = 'abierta'"; }
    elseif ($estado === 'ganados')   { $w[] = "e.tipo = 'ganada'"; }
    elseif ($estado === 'perdidos')  { $w[] = "e.tipo = 'perdida'"; }
    if (!empty($f['desde'])) { $w[] = 'n.fecha_cierre >= ?'; $p[] = $f['desde']; }
    if (!empty($f['hasta'])) { $w[] = 'n.fecha_cierre <= ?'; $p[] = $f['hasta']; }
    return ['WHERE ' . implode(' AND ', $w), $p];
}

/**
 * Listado paginado de negocios, los de cierre más próximo primero y los sin
 * fecha al final.
 *
 * @return array lo de paginar() más 'rows', 'total' y 'suma' (importe y
 *               ponderado de TODOS los que cumplen el filtro, no sólo de la
 *               página; mezcla ARS y USD)
 */
function negocios_listar(array $f): array
{
    [$where, $p] = negocios_where($f);
    $sql = "FROM negocios n
            JOIN etapas e ON e.id = n.etapa_id
            LEFT JOIN cuentas cu ON cu.id = n.cuenta_id
            LEFT JOIN usuarios u ON u.id = n.propietario_id $where";
    $total = (int) qv("SELECT COUNT(*) $sql", $p);
    $suma  = q1("SELECT COALESCE(SUM(n.importe),0) importe, COALESCE(SUM(n.ingreso_esperado),0) ponderado $sql", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    $rows  = q("SELECT n.*, e.nombre AS etapa, e.color AS etapa_color, e.tipo AS etapa_tipo,
                       cu.nombre AS cuenta, u.nombre AS propietario $sql
                ORDER BY n.fecha_cierre IS NULL, n.fecha_cierre
                LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total, 'suma' => $suma];
}

/**
 * Datos del tablero Kanban: una columna por etapa con sus negocios.
 *
 * @param int   $embudoId el embudo a mostrar (no se verifica que exista ni
 *                        que esté activo: uno inexistente da un tablero vacío)
 * @param array $f        propietario_id, q
 * @return array<int,array{etapa:array,negocios:array,total:float}> por id de
 *               etapa; 'total' suma importes sin separar moneda
 */
function embudo_kanban(int $embudoId, array $f = []): array
{
    // Sólo se aplican propietario y búsqueda: el Kanban muestra abiertos y
    // cerrados juntos, cada uno en la columna de su etapa.
    $cols = [];
    $w = ['n.eliminado_en IS NULL', 'n.embudo_id = ?'];
    $p = [$embudoId];
    if (!empty($f['propietario_id'])) { $w[] = 'n.propietario_id = ?'; $p[] = (int)$f['propietario_id']; }
    if (!empty($f['q'])) {
        $w[] = '(n.nombre LIKE ? OR cu.nombre LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like);
    }
    $where = 'WHERE ' . implode(' AND ', $w);
    $rows = q("SELECT n.*, cu.nombre AS cuenta, u.nombre AS propietario
               FROM negocios n
               LEFT JOIN cuentas cu ON cu.id = n.cuenta_id
               LEFT JOIN usuarios u ON u.id = n.propietario_id
               $where ORDER BY n.fecha_cierre IS NULL, n.fecha_cierre", $p)->fetchAll();
    // Las columnas salen de las etapas ACTIVAS: un negocio que quedó en una etapa
    // desactivada no se muestra en el tablero (el continue de abajo).
    foreach (etapas_de($embudoId) as $et) {
        $cols[(int)$et['id']] = ['etapa' => $et, 'negocios' => [], 'total' => 0.0];
    }
    foreach ($rows as $n) {
        $eid = (int)$n['etapa_id'];
        if (!isset($cols[$eid])) { continue; }
        $cols[$eid]['negocios'][] = $n;
        $cols[$eid]['total'] += (float)$n['importe'];
    }
    return $cols;
}

/**
 * Alta o edición de un negocio. Devuelve el id.
 *
 * La etapa manda: de ella salen el embudo y, si no se cargó a mano, la
 * probabilidad. Si la etapa no existe se usa la primera del embudo
 * predeterminado. Si la etapa es de cierre (ganada o perdida) se pone
 * cerrado_en con la hora actual; si es abierta, se vacía.
 *
 * En la edición pisa todos los campos con lo que vino en $d. Si la etapa
 * cambió, registra el tramo en negocio_etapas.
 * Efectos: escribe en negocios y negocio_etapas y deja constancia en el log.
 *
 * @throws RuntimeException si no hay ninguna etapa configurada
 */
function negocio_guardar(array $d, ?int $id = null): int
{
    $etapa = etapa_get((int)($d['etapa_id'] ?? 0));
    if (!$etapa) {
        $emb   = embudo_predeterminado();
        $lista = $emb ? etapas_de((int)$emb['id']) : [];
        $etapa = $lista[0] ?? null;
    }
    if (!$etapa) {
        throw new RuntimeException('No hay etapas configuradas en el embudo.');
    }

    // La probabilidad sigue a la etapa salvo que la pisen a mano: es lo que
    // hace que el pronóstico ponderado tenga sentido sin cargar nada extra.
    $prob = ($d['probabilidad'] ?? '') !== '' ? (int)($d['probabilidad'] ?? 0) : (int)$etapa['probabilidad'];

    $campos = [
        'nombre'         => trim((string)($d['nombre'] ?? '')),
        'cuenta_id'      => ($d['cuenta_id'] ?? null) ?: null,
        'contacto_id'    => ($d['contacto_id'] ?? null) ?: null,
        'propietario_id' => ($d['propietario_id'] ?? null) ?: current_user_id(),
        'embudo_id'      => (int)$etapa['embudo_id'],
        'etapa_id'       => (int)$etapa['id'],
        // El formulario usa type="number" y manda punto decimal; el reemplazo de la
        // coma cubre a quien llame con «1500,50». Un importe con separador de miles
        // («1.500,50») se convierte mal.
        'importe'        => (float)str_replace(',', '.', (string)($d['importe'] ?? 0)),
        'moneda'         => in_array($d['moneda'] ?? 'ARS', ['ARS', 'USD'], true) ? $d['moneda'] : 'ARS',
        'probabilidad'   => max(0, min(100, $prob)),
        'fecha_cierre'   => ($d['fecha_cierre'] ?? null) ?: null,
        'motivo_perdida' => ($d['motivo_perdida'] ?? null) ?: null,
        'tipo'           => ($d['tipo'] ?? null) ?: null,
        'fuente'         => ($d['fuente'] ?? null) ?: null,
        'campana_id'     => ($d['campana_id'] ?? null) ?: null,
        'siguiente_paso' => ($d['siguiente_paso'] ?? null) ?: null,
        'descripcion'    => ($d['descripcion'] ?? null) ?: null,
        // Ojo: se recalcula en cada guardado. Editar un negocio ya ganado vuelve a
        // poner cerrado_en en la fecha de hoy (ver auditoría).
        'cerrado_en'     => $etapa['tipo'] !== 'abierta' ? date('Y-m-d H:i:s') : null,
    ];

    if ($id) {
        $antes = negocio_get($id);
        $sets  = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE negocios SET $sets WHERE id = ?", [...array_values($campos), $id]);
        if ($antes && (int)$antes['etapa_id'] !== (int)$etapa['id']) {
            registrar_cambio_etapa($id, (int)$etapa['id']);
        }
        log_app('negocio.editar', ['entidad' => 'negocio', 'entidad_id' => $id, 'detalle' => $campos['nombre']]);
        return $id;
    }

    // Alta: se abre el primer tramo del historial en la etapa inicial.
    $campos['creado_por'] = current_user_id();
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO negocios ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    q('INSERT INTO negocio_etapas (negocio_id, etapa_id, usuario_id) VALUES (?, ?, ?)',
      [$nuevo, (int)$etapa['id'], current_user_id()]);
    log_app('negocio.crear', ['entidad' => 'negocio', 'entidad_id' => $nuevo,
        'detalle' => $campos['nombre'] . ' · ' . money($campos['importe'], $campos['moneda'])]);
    return $nuevo;
}

/**
 * Cierra el tramo de etapa anterior y abre el nuevo (para medir tiempos).
 *
 * No compara con la etapa actual: si se llama con la misma etapa, igual corta
 * el tramo y abre otro. No va en transacción con el UPDATE del negocio.
 */
function registrar_cambio_etapa(int $negocioId, int $etapaId): void
{
    q('UPDATE negocio_etapas SET hasta = NOW() WHERE negocio_id = ? AND hasta IS NULL', [$negocioId]);
    q('INSERT INTO negocio_etapas (negocio_id, etapa_id, usuario_id) VALUES (?, ?, ?)',
      [$negocioId, $etapaId, current_user_id()]);
}

/**
 * Mueve un negocio de etapa. Lo usa el formulario «Mover de etapa» de la
 * ficha del negocio (página negocio-etapa); el tablero Kanban no tiene
 * arrastrar y soltar.
 *
 * A diferencia de negocio_guardar(), acá la probabilidad SIEMPRE se reemplaza
 * por la de la etapa nueva (se pierde la que se haya cargado a mano). El
 * motivo de pérdida sólo se escribe si viene; si no, queda el que había,
 * aunque el negocio vuelva a una etapa abierta.
 *
 * @throws RuntimeException si el negocio o la etapa no existen
 */
function negocio_mover(int $id, int $etapaId, ?string $motivoPerdida = null): void
{
    $n  = negocio_get($id);
    $et = etapa_get($etapaId);
    if (!$n || !$et) {
        throw new RuntimeException('Negocio o etapa inexistente.');
    }
    q('UPDATE negocios SET etapa_id = ?, embudo_id = ?, probabilidad = ?,
              cerrado_en = ?, motivo_perdida = COALESCE(?, motivo_perdida)
       WHERE id = ?',
      [$etapaId, (int)$et['embudo_id'], (int)$et['probabilidad'],
       $et['tipo'] !== 'abierta' ? date('Y-m-d H:i:s') : null,
       $motivoPerdida, $id]);
    registrar_cambio_etapa($id, $etapaId);
    log_app('negocio.etapa', ['entidad' => 'negocio', 'entidad_id' => $id,
        'detalle' => $n['nombre'] . ': «' . $n['etapa'] . '» → «' . $et['nombre'] . '»']);
}

/**
 * Pronóstico del período: objetivo (cuota), ganado, embudo abierto y
 * embudo ponderado por probabilidad, por vendedor.
 *
 * Los negocios caen en el mes por su fecha ESTIMADA de cierre (fecha_cierre),
 * no por cerrado_en: un negocio ganado hoy con fecha estimada del mes que
 * viene suma en el mes que viene. Sólo aparecen usuarios activos; los
 * negocios de un usuario dado de baja no se cuentan en ninguna fila.
 *
 * @param string $periodo mes en formato AAAA-MM (no se valida acá; uno
 *                        inválido hace fallar strtotime)
 * @return array una fila por usuario activo: id, nombre, objetivo, ganado,
 *               perdido, abierto, ponderado
 */
function pronostico(string $periodo): array
{
    $desde = $periodo . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $filas = q("SELECT u.id, u.nombre,
                       COALESCE(cu.objetivo, 0) objetivo,
                       COALESCE(SUM(CASE WHEN e.tipo = 'ganada'  THEN n.importe END), 0) ganado,
                       COALESCE(SUM(CASE WHEN e.tipo = 'perdida' THEN n.importe END), 0) perdido,
                       COALESCE(SUM(CASE WHEN e.tipo = 'abierta' THEN n.importe END), 0) abierto,
                       COALESCE(SUM(CASE WHEN e.tipo = 'abierta' THEN n.ingreso_esperado END), 0) ponderado
                FROM usuarios u
                LEFT JOIN cuotas cu ON cu.usuario_id = u.id AND cu.periodo = ?
                LEFT JOIN negocios n ON n.propietario_id = u.id AND n.eliminado_en IS NULL
                     AND n.fecha_cierre BETWEEN ? AND ?
                LEFT JOIN etapas e ON e.id = n.etapa_id
                WHERE u.activo = 1
                GROUP BY u.id, u.nombre, cu.objetivo
                ORDER BY ganado DESC, u.nombre", [$periodo, $desde, $hasta])->fetchAll();
    return $filas;
}

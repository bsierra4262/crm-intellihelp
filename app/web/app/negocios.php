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
//  Montos: hay negocios en ARS y en USD y no se convierte uno en otro. Toda
//  suma de este archivo (listado, Kanban, pronóstico) se devuelve separada por
//  moneda, y las pantallas la muestran con montos_por_moneda().
//
//  Permisos: cualquier usuario con sesión crea, edita y mueve cualquier
//  negocio (la base se comparte en el equipo). Mandarlo a la papelera es sólo
//  del propietario o de un administrador (registro-eliminar).
// ============================================================

/** Las monedas que se manejan, en el orden en que se muestran. */
const NEGOCIO_MONEDAS = ['ARS', 'USD'];

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
 * Las etapas para elegir en un negocio: las activas de su embudo más la etapa
 * en la que está ahora, aunque esté desactivada (marcada con 'inactiva').
 *
 * Sin la etapa actual en la lista, el desplegable marcaría otra y guardar o
 * apretar «Mover» sin tocar nada cambiaría el negocio de etapa.
 *
 * @param int      $embudoId      el embudo del negocio
 * @param int|null $etapaActualId la etapa en la que está el negocio, si ya existe
 */
function etapas_para_negocio(int $embudoId, ?int $etapaActualId = null): array
{
    $lista = etapas_de($embudoId);
    if ($etapaActualId) {
        foreach ($lista as $et) {
            if ((int)$et['id'] === $etapaActualId) { return $lista; }
        }
        $actual = etapa_get($etapaActualId);
        if ($actual) {
            $actual['inactiva'] = true;
            $lista[] = $actual;
            usort($lista, static fn($a, $b) => (int)$a['orden'] <=> (int)$b['orden']);
        }
    }
    return $lista;
}

/** Una etapa por id, esté activa o no y sea del embudo que sea. */
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
 * Corre $fn adentro de una transacción, salvo que ya haya una abierta (la
 * conversión de un posible cliente, por ejemplo): ahí se suma a esa.
 *
 * @return mixed lo que devuelva $fn
 */
function negocio_tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) { return $fn(); }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Lee un importe escrito a mano o mandado por un campo numérico.
 *
 * Acepta «1500000.5» (lo que manda un type="number"), «1.500.000»,
 * «1.500.000,50», «1500,50» y «1,500.50». Reglas:
 *  · si tiene punto y coma, el que aparece último es el decimal;
 *  · una coma sola es decimal; varias, separadores de miles;
 *  · varios puntos son separadores de miles; uno solo es decimal, salvo que
 *    lo sigan exactamente tres dígitos y la parte entera no sea cero
 *    («1.500» es mil quinientos, «0.500» es medio).
 * Los símbolos de moneda y los espacios se ignoran.
 *
 * @return float|null null si no queda ningún número
 */
function importe_parsear($valor): ?float
{
    $s = preg_replace('/[^0-9.,\-]/', '', trim((string)$valor)) ?? '';
    if ($s === '' || !preg_match('/\d/', $s)) { return null; }
    $neg = str_starts_with($s, '-');
    $s = str_replace('-', '', $s);
    $pc = strrpos($s, ',');
    $pp = strrpos($s, '.');
    if ($pc !== false && $pp !== false) {
        if ($pc > $pp) { $s = str_replace(['.', ','], ['', '.'], $s); }
        else           { $s = str_replace(',', '', $s); }
    } elseif ($pc !== false) {
        $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    } elseif ($pp !== false) {
        if (substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        } elseif (preg_match('/^([1-9]\d{0,2})\.(\d{3})$/', $s)) {
            $s = str_replace('.', '', $s);
        }
    }
    if (!is_numeric($s)) { return null; }
    return $neg ? -(float)$s : (float)$s;
}

/** Un período AAAA-MM con un mes que existe (01 a 12). */
function periodo_valido(string $periodo): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo);
}

/**
 * Suma montos separados por moneda: [['moneda' => 'USD', 'x' => 10], …] →
 * ['ARS' => 0.0, 'USD' => 10.0]. Siempre trae ARS y USD, aunque sea en cero.
 */
function sumar_por_moneda(array $filas, string $campo): array
{
    $t = array_fill_keys(NEGOCIO_MONEDAS, 0.0);
    foreach ($filas as $f) {
        $m = in_array($f['moneda'] ?? 'ARS', NEGOCIO_MONEDAS, true) ? $f['moneda'] : 'ARS';
        $t[$m] += (float)($f[$campo] ?? 0);
    }
    return $t;
}

/**
 * Texto de un total separado por moneda: «$ 1.500,00 · US$ 200,00». Una
 * moneda en cero no se muestra; si las dos están en cero, «$ 0,00».
 *
 * @param array<string,float> $porMoneda moneda => monto
 */
function montos_por_moneda(array $porMoneda): string
{
    $partes = [];
    foreach (NEGOCIO_MONEDAS as $m) {
        if (abs((float)($porMoneda[$m] ?? 0)) >= 0.005) { $partes[] = money((float)$porMoneda[$m], $m); }
    }
    return $partes ? implode(' · ', $partes) : money(0);
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
 *               página, cada uno como moneda => monto)
 */
function negocios_listar(array $f): array
{
    [$where, $p] = negocios_where($f);
    $sql = "FROM negocios n
            JOIN etapas e ON e.id = n.etapa_id
            LEFT JOIN cuentas cu ON cu.id = n.cuenta_id
            LEFT JOIN usuarios u ON u.id = n.propietario_id $where";
    $total = (int) qv("SELECT COUNT(*) $sql", $p);
    $porMoneda = q("SELECT n.moneda, COALESCE(SUM(n.importe),0) importe, COALESCE(SUM(n.ingreso_esperado),0) ponderado
                    $sql GROUP BY n.moneda", $p)->fetchAll();
    $suma  = ['importe' => sumar_por_moneda($porMoneda, 'importe'),
              'ponderado' => sumar_por_moneda($porMoneda, 'ponderado')];
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
 * Las columnas son las etapas activas. Una etapa desactivada que todavía
 * tiene negocios también aparece (con 'inactiva' => true en la etapa): si no,
 * esos negocios desaparecerían del tablero sin que nadie lo note.
 *
 * @param int   $embudoId el embudo a mostrar (uno inexistente da un tablero vacío)
 * @param array $f        propietario_id, q
 * @return array<int,array{etapa:array,negocios:array,total:array}> por id de
 *               etapa; 'total' es moneda => suma de importes
 */
function embudo_kanban(int $embudoId, array $f = []): array
{
    // Sólo se aplican propietario y búsqueda: el Kanban muestra abiertos y
    // cerrados juntos, cada uno en la columna de su etapa.
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

    $porEtapa = [];
    foreach ($rows as $n) { $porEtapa[(int)$n['etapa_id']][] = $n; }

    $cols = [];
    foreach (q('SELECT * FROM etapas WHERE embudo_id = ? ORDER BY orden, id', [$embudoId])->fetchAll() as $et) {
        $eid = (int)$et['id'];
        $negocios = $porEtapa[$eid] ?? [];
        if (!(int)$et['activo']) {
            if (!$negocios) { continue; }
            $et['inactiva'] = true;
        }
        $cols[$eid] = ['etapa' => $et, 'negocios' => $negocios,
                       'total' => sumar_por_moneda($negocios, 'importe')];
    }
    return $cols;
}

/**
 * Revisa que la empresa, el contacto y la campaña elegidos existan y no estén
 * en la papelera. Sin esto, un id inexistente hace fallar la clave foránea
 * con un error 500.
 *
 * Un vínculo que ya tenía el negocio se acepta aunque su registro esté en la
 * papelera: guardar sin tocar nada no puede fallar ni borrar el vínculo.
 *
 * @param array      $d     lo que llegó del formulario
 * @param array|null $antes el negocio como estaba (null en un alta)
 * @return array<string,string> campo => mensaje (vacío si está todo bien)
 */
function negocio_validar_vinculos(array $d, ?array $antes = null): array
{
    $err = [];
    $tablas = [
        'cuenta_id'   => ['cuentas',   'La empresa elegida ya no existe (puede estar en la papelera).'],
        'contacto_id' => ['contactos', 'El contacto elegido ya no existe (puede estar en la papelera).'],
        'campana_id'  => ['campanas',  'La campaña elegida ya no existe.'],
    ];
    foreach ($tablas as $campo => [$tabla, $msg]) {
        $id = (int)($d[$campo] ?? 0);
        if ($antes && $id === (int)($antes[$campo] ?? 0)) { continue; }
        if ($id > 0 && !qv("SELECT 1 FROM $tabla WHERE id = ? AND eliminado_en IS NULL", [$id])) {
            $err[$campo] = $msg;
        }
    }
    if (isset($d['moneda']) && $d['moneda'] !== '' && !in_array($d['moneda'], NEGOCIO_MONEDAS, true)) {
        $err['moneda'] = 'La moneda tiene que ser ARS o USD.';
    }
    return $err;
}

/**
 * La fecha de cierre que corresponde guardar.
 *
 * Abierta → null. Cerrada: se conserva la fecha que ya tenía si antes estaba
 * cerrada con el mismo tipo (corregir la descripción de un negocio ganado en
 * marzo no lo vuelve a cerrar hoy); si recién se cierra, o pasa de ganado a
 * perdido o al revés, la hora actual.
 *
 * @param string      $tipoNuevo   tipo de la etapa a la que va
 * @param string|null $tipoAntes   tipo de la etapa en la que estaba (null en un alta)
 * @param string|null $cerradoAntes el cerrado_en que tenía
 */
function negocio_cerrado_en(string $tipoNuevo, ?string $tipoAntes, ?string $cerradoAntes, ?int $ahora = null): ?string
{
    if ($tipoNuevo === 'abierta') { return null; }
    if ($tipoAntes === $tipoNuevo && $cerradoAntes) { return $cerradoAntes; }
    return date('Y-m-d H:i:s', $ahora ?? time());
}

/**
 * Alta o edición de un negocio. Devuelve el id.
 *
 * La etapa manda: de ella salen el embudo y la probabilidad. Sirve cualquier
 * etapa activa o la que el negocio ya tenía (aunque esté desactivada); si no
 * vino una válida, en la edición se queda en la que estaba y en el alta va a
 * la primera del embudo predeterminado.
 *
 * Probabilidad: vacía, la de la etapa. En la edición, si la etapa cambió y la
 * probabilidad llegó igual a la que tenía (nadie la tocó), toma la de la
 * etapa nueva: es la misma regla que negocio_mover(), así el ponderado no
 * depende de por dónde se cambió la etapa.
 *
 * cerrado_en: ver negocio_cerrado_en().
 *
 * En la edición pisa todos los campos con lo que vino en $d. Si la etapa
 * cambió, registra el tramo en negocio_etapas, en la misma transacción.
 * Efectos: escribe en negocios y negocio_etapas y deja constancia en el log.
 *
 * @throws RuntimeException si no hay ninguna etapa configurada
 */
function negocio_guardar(array $d, ?int $id = null): int
{
    $antes = $id ? negocio_get($id) : null;

    $etapa = etapa_get((int)($d['etapa_id'] ?? 0));
    if ($etapa && !(int)$etapa['activo'] && (!$antes || (int)$antes['etapa_id'] !== (int)$etapa['id'])) {
        $etapa = null;
    }
    if (!$etapa && $antes) {
        $etapa = etapa_get((int)$antes['etapa_id']);
    }
    if (!$etapa) {
        $emb   = embudo_predeterminado();
        $lista = $emb ? etapas_de((int)$emb['id']) : [];
        $etapa = $lista[0] ?? null;
    }
    if (!$etapa) {
        throw new RuntimeException('No hay etapas configuradas en el embudo.');
    }

    $cambiaEtapa = $antes && (int)$antes['etapa_id'] !== (int)$etapa['id'];
    $probTxt = trim((string)($d['probabilidad'] ?? ''));
    if ($probTxt === '' || ($cambiaEtapa && (int)$probTxt === (int)$antes['probabilidad'])) {
        $prob = (int)$etapa['probabilidad'];
    } else {
        $prob = (int)$probTxt;
    }

    $campos = [
        'nombre'         => mb_substr(trim((string)($d['nombre'] ?? '')), 0, 200),
        'cuenta_id'      => ((int)($d['cuenta_id'] ?? 0)) ?: null,
        'contacto_id'    => ((int)($d['contacto_id'] ?? 0)) ?: null,
        'propietario_id' => ((int)($d['propietario_id'] ?? 0)) ?: current_user_id(),
        'embudo_id'      => (int)$etapa['embudo_id'],
        'etapa_id'       => (int)$etapa['id'],
        'importe'        => max(0.0, (float)(importe_parsear($d['importe'] ?? '') ?? 0.0)),
        'moneda'         => in_array($d['moneda'] ?? 'ARS', NEGOCIO_MONEDAS, true) ? ($d['moneda'] ?? 'ARS') : 'ARS',
        'probabilidad'   => max(0, min(100, $prob)),
        'fecha_cierre'   => ($d['fecha_cierre'] ?? null) ?: null,
        'motivo_perdida' => mb_substr(trim((string)($d['motivo_perdida'] ?? '')), 0, 200) ?: null,
        'tipo'           => mb_substr(trim((string)($d['tipo'] ?? '')), 0, 40) ?: null,
        'fuente'         => mb_substr(trim((string)($d['fuente'] ?? '')), 0, 60) ?: null,
        'campana_id'     => ((int)($d['campana_id'] ?? 0)) ?: null,
        'siguiente_paso' => mb_substr(trim((string)($d['siguiente_paso'] ?? '')), 0, 255) ?: null,
        'descripcion'    => ($d['descripcion'] ?? null) ?: null,
        'cerrado_en'     => negocio_cerrado_en((string)$etapa['tipo'], $antes['etapa_tipo'] ?? null,
                                               $antes['cerrado_en'] ?? null),
    ];

    if ($id) {
        negocio_tx(function () use ($campos, $id, $cambiaEtapa, $etapa): void {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
            q("UPDATE negocios SET $sets WHERE id = ?", [...array_values($campos), $id]);
            if ($cambiaEtapa) {
                registrar_cambio_etapa($id, (int)$etapa['id']);
            }
        });
        log_app('negocio.editar', ['entidad' => 'negocio', 'entidad_id' => $id, 'detalle' => $campos['nombre']]);
        return $id;
    }

    // Alta: se abre el primer tramo del historial en la etapa inicial.
    $campos['creado_por'] = current_user_id();
    $nuevo = negocio_tx(function () use ($campos, $etapa): int {
        $cols = implode(', ', array_keys($campos));
        $ph   = implode(', ', array_fill(0, count($campos), '?'));
        q("INSERT INTO negocios ($cols) VALUES ($ph)", array_values($campos));
        $nuevo = last_id();
        q('INSERT INTO negocio_etapas (negocio_id, etapa_id, usuario_id) VALUES (?, ?, ?)',
          [$nuevo, (int)$etapa['id'], current_user_id()]);
        return $nuevo;
    });
    log_app('negocio.crear', ['entidad' => 'negocio', 'entidad_id' => $nuevo,
        'detalle' => $campos['nombre'] . ' · ' . money($campos['importe'], $campos['moneda'])]);
    return $nuevo;
}

/**
 * Cierra el tramo de etapa anterior y abre el nuevo (para medir tiempos).
 *
 * No compara con la etapa actual: quien llama tiene que hacerlo antes (lo
 * hacen negocio_guardar() y negocio_mover()), y llamarla adentro de la misma
 * transacción que el UPDATE del negocio.
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
 * Reglas:
 *  · la etapa tiene que ser del mismo embudo y estar activa;
 *  · a la misma etapa no hace nada (no abre un tramo nuevo) y devuelve false;
 *  · la probabilidad pasa a ser la de la etapa nueva (como en negocio_guardar()
 *    cuando cambia la etapa y nadie tocó la probabilidad);
 *  · el motivo de pérdida se escribe si viene y la etapa es perdida; si la
 *    etapa no es perdida se borra (un negocio reabierto no tiene motivo de
 *    pérdida);
 *  · cerrado_en, como negocio_cerrado_en().
 * Todo en una transacción con el tramo del historial.
 *
 * @return bool true si se movió
 * @throws RuntimeException con un mensaje para mostrar si el negocio o la
 *         etapa no sirven
 */
function negocio_mover(int $id, int $etapaId, ?string $motivoPerdida = null): bool
{
    $n  = negocio_get($id);
    $et = etapa_get($etapaId);
    if (!$n) {
        throw new RuntimeException('El negocio no existe o está en la papelera.');
    }
    if (!$et || (int)$et['embudo_id'] !== (int)$n['embudo_id']) {
        throw new RuntimeException('Esa etapa no es del embudo de este negocio.');
    }
    if ((int)$n['etapa_id'] === $etapaId) {
        return false;
    }
    if (!(int)$et['activo']) {
        throw new RuntimeException('Esa etapa está desactivada: elegí otra.');
    }
    $motivo = $et['tipo'] === 'perdida'
        ? (mb_substr(trim((string)$motivoPerdida), 0, 200) ?: $n['motivo_perdida'])
        : null;
    negocio_tx(function () use ($id, $etapaId, $et, $n, $motivo): void {
        q('UPDATE negocios SET etapa_id = ?, embudo_id = ?, probabilidad = ?, cerrado_en = ?, motivo_perdida = ?
           WHERE id = ?',
          [$etapaId, (int)$et['embudo_id'], (int)$et['probabilidad'],
           negocio_cerrado_en((string)$et['tipo'], (string)$n['etapa_tipo'], $n['cerrado_en']),
           $motivo, $id]);
        registrar_cambio_etapa($id, $etapaId);
    });
    log_app('negocio.etapa', ['entidad' => 'negocio', 'entidad_id' => $id,
        'detalle' => $n['nombre'] . ': «' . $n['etapa'] . '» → «' . $et['nombre'] . '»']);
    return true;
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
 * Monedas: ganado, perdido, abierto y ponderado son SÓLO en pesos, la moneda
 * de las cuotas, así el cumplimiento compara lo mismo con lo mismo. Lo que
 * está en dólares viene aparte en ganado_usd, perdido_usd, abierto_usd y
 * ponderado_usd.
 *
 * @param string $periodo mes en formato AAAA-MM; uno inválido devuelve []
 * @return array una fila por usuario activo: id, nombre, objetivo, ganado,
 *               perdido, abierto, ponderado y sus *_usd
 */
function pronostico(string $periodo): array
{
    if (!periodo_valido($periodo)) { return []; }
    $desde = $periodo . '-01';
    $hasta = date('Y-m-t', (int) strtotime($desde));
    $sumas = '';
    foreach (['ARS' => '', 'USD' => '_usd'] as $mon => $suf) {
        $sumas .= "COALESCE(SUM(CASE WHEN e.tipo = 'ganada'  AND n.moneda = '$mon' THEN n.importe END), 0) ganado$suf,
                   COALESCE(SUM(CASE WHEN e.tipo = 'perdida' AND n.moneda = '$mon' THEN n.importe END), 0) perdido$suf,
                   COALESCE(SUM(CASE WHEN e.tipo = 'abierta' AND n.moneda = '$mon' THEN n.importe END), 0) abierto$suf,
                   COALESCE(SUM(CASE WHEN e.tipo = 'abierta' AND n.moneda = '$mon' THEN n.ingreso_esperado END), 0) ponderado$suf,";
    }
    $sumas = rtrim($sumas, ',');
    return q("SELECT u.id, u.nombre,
                     COALESCE(cu.objetivo, 0) objetivo,
                     $sumas
              FROM usuarios u
              LEFT JOIN cuotas cu ON cu.usuario_id = u.id AND cu.periodo = ?
              LEFT JOIN negocios n ON n.propietario_id = u.id AND n.eliminado_en IS NULL
                   AND n.fecha_cierre BETWEEN ? AND ?
              LEFT JOIN etapas e ON e.id = n.etapa_id
              WHERE u.activo = 1
              GROUP BY u.id, u.nombre, cu.objetivo
              ORDER BY ganado DESC, u.nombre", [$periodo, $desde, $hasta])->fetchAll();
}

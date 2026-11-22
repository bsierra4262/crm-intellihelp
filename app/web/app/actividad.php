<?php
declare(strict_types=1);

// ============================================================
//  Auditoría (tabla `actividad`): lo que alimenta la sección «CRON».
//
//  No confundir con actividades.php: aquello son las tareas, llamadas y
//  reuniones del equipo (tabla `actividades`, en plural). Esto es el
//  registro de lo que pasa en el sistema.
//
//  Dos canales:
//    · app → qué hace cada usuario (altas, ediciones, borrados, conversiones,
//            importaciones, campañas, login/logout) y todos los errores.
//    · api → cada pedido a la API de lectura y a la API v1 de la web.
//
//  Escribir: log_evento() y sus atajos log_app(), log_api() y log_error().
//  Leer: actividad_list(), actividad_conteo_tipos() y actividad_resumen()
//  para la pantalla del CRON, y timeline() para el «Historial» de cada ficha
//  (se filtra por `entidad` + `entidad_id`).
//
//  log_evento() NUNCA debe romper un pedido: todo va dentro de try/catch.
//  Si la tabla `actividad` no existe todavía, simplemente no registra.
//
//  La IP de quien no tiene sesión se guarda como hash (Ley 25.326), ver
//  log_ip(). El histórico no se borra nunca; la pantalla mira por defecto
//  sólo los últimos ACTIVIDAD_VENTANA_MESES meses.
//  Lo carga bootstrap.php para todas las rutas: es parte del núcleo.
// ============================================================

/**
 * Inserta un evento.
 *
 * Sin `usuario_id` ni `actor` explícitos, se toman del usuario de la sesión.
 * Los textos se recortan al largo de cada columna (actor 160, acción 120,
 * detalle 500) para que un dato largo no haga fallar el INSERT.
 * Nunca lanza: cualquier error se traga en silencio (tampoco queda en el log
 * de PHP).
 *
 * @param string $canal  'app' | 'api' (otro valor se guarda como 'app')
 * @param string $accion identificador corto (ej. 'contacto.crear')
 * @param array  $o      tipo|actor|entidad|entidad_id|detalle|usuario_id;
 *                       `tipo` es nav | accion | auth | error | sistema
 *                       (por defecto 'accion')
 */
function log_evento(string $canal, string $accion, array $o = []): void
{
    try {
        $uid   = $o['usuario_id'] ?? ($_SESSION['uid'] ?? null);
        $actor = $o['actor'] ?? null;
        if ($actor === null && !empty($_SESSION['uid']) && function_exists('auth_user')) {
            $u = auth_user();
            $actor = $u['nombre'] ?? null;
        }
        q('INSERT INTO actividad (canal, tipo, usuario_id, actor, accion, entidad, entidad_id, detalle, ip)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            in_array($canal, ['app', 'api'], true) ? $canal : 'app',
            $o['tipo'] ?? 'accion',
            $uid ? (int)$uid : null,
            $actor !== null ? mb_substr((string)$actor, 0, 160) : null,
            mb_substr($accion, 0, 120),
            $o['entidad'] ?? null,
            isset($o['entidad_id']) ? (int)$o['entidad_id'] : null,
            isset($o['detalle']) ? mb_substr((string)$o['detalle'], 0, 500) : null,
            log_ip(),
        ]);
    } catch (\Throwable $e) {
        // El log jamás debe tirar abajo la operación real.
    }
}

/**
 * La IP que queda en la auditoría.
 *
 * Con sesión es alguien del equipo, y su IP sirve para revisar un ingreso
 * raro: se guarda como siempre. Sin sesión es un visitante de las páginas
 * públicas —la agenda, la baja de suscripción— o un pedido de la web, y su IP
 * es un dato personal que no tenemos por qué guardar (Ley 25.326). Se guarda
 * el hash recortado, con el secreto de la instalación: alcanza para ver que
 * diez pedidos vinieron del mismo lugar, sin poder saber de cuál.
 */
function log_ip(): ?string
{
    // ip_confiable() y no client_ip(): las cabeceras de proxy las escribe el
    // que hace el pedido, y la auditoría tiene que mostrar de dónde vino.
    $ip = function_exists('ip_confiable') ? ip_confiable() : ($_SERVER['REMOTE_ADDR'] ?? null);
    if ($ip === null || $ip === '') { return null; }
    if (!empty($_SESSION['uid'])) { return $ip; }
    $h = function_exists('ip_hash') ? ip_hash($ip) : null;
    return $h ? 'h:' . substr($h, 0, 40) : null;
}

/** Atajo: evento del canal 'app' (lo que hace la gente y los errores). */
function log_app(string $accion, array $o = []): void { log_evento('app', $accion, $o); }
/** Atajo: evento del canal 'api' (pedidos a la API). */
function log_api(string $accion, array $o = []): void { log_evento('api', $accion, $o); }

/**
 * Registra un error con un CÓDIGO estable por ubicación (mismo archivo:línea →
 * mismo código), para poder buscarlo y arreglar la causa.
 *
 * El código sale del md5 del NOMBRE del archivo (no de la ruta completa) más
 * la línea: el mismo error da el mismo código en crm/ y en crm-pruebas/. Si
 * se mueve la línea, cambia.
 *
 * @param string $severidad opcional, se antepone al mensaje (ej. 'warning')
 */
function log_error(string $accion, string $mensaje, string $file, int $line, string $severidad = ''): void
{
    $loc     = basename($file) . ':' . $line;
    $codigo  = 'ERR-' . strtoupper(substr(md5($loc), 0, 6));
    $detalle = $codigo . ' · ' . ($severidad !== '' ? $severidad . ' · ' : '') . $mensaje . ' · ' . $loc;
    log_evento('app', $accion, ['tipo' => 'error', 'actor' => 'sistema', 'detalle' => $detalle]);
}

/**
 * ¿Existe la tabla? (para degradar con gracia si aún no se migró).
 * Se consulta una sola vez por pedido y se recuerda.
 */
function actividad_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try {
        db()->query('SELECT 1 FROM actividad LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/** Meses que se muestran por defecto en pantalla (el histórico nunca se borra). */
const ACTIVIDAD_VENTANA_MESES = 4;

/**
 * Construye WHERE + parámetros a partir de los filtros del CRON.
 *
 * Todas las columnas van calificadas con el alias `a`: la consulta del listado
 * hace JOIN con `usuarios`, que también tiene `creado_en`, y sin el prefijo
 * MySQL la rechaza por ambigua.
 *
 * Filtros: canal, tipo, desde, hasta (Y-m-d) y q (texto en actor, acción,
 * detalle o IP). Las fechas no se validan; van como parámetro, así que una
 * fecha mal escrita sólo devuelve resultados raros, no rompe la consulta.
 *
 * @return array{0:string,1:array} el WHERE completo y sus parámetros
 */
function actividad_where(array $f): array
{
    $w = ['a.canal = ?'];
    $p = [in_array($f['canal'] ?? 'app', ['app', 'api'], true) ? $f['canal'] : 'app'];
    if (!empty($f['tipo'])) { $w[] = 'a.tipo = ?'; $p[] = $f['tipo']; }
    // Ventana por defecto: últimos N meses (rendimiento). Si el usuario pide
    // una fecha "Desde" anterior, se consulta el histórico completo.
    $piso  = date('Y-m-d', strtotime('-' . ACTIVIDAD_VENTANA_MESES . ' months'));
    $desde = !empty($f['desde']) ? $f['desde'] : $piso;
    $w[] = 'a.creado_en >= ?'; $p[] = $desde . ' 00:00:00';
    if (!empty($f['hasta'])) { $w[] = 'a.creado_en <= ?'; $p[] = $f['hasta'] . ' 23:59:59'; }
    if (!empty($f['q'])) {
        $w[] = '(a.actor LIKE ? OR a.accion LIKE ? OR a.detalle LIKE ? OR a.ip LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like);
    }
    return ['WHERE ' . implode(' AND ', $w), $p];
}

/**
 * Lista paginada de eventos, del más nuevo al más viejo.
 *
 * @return array rows, total, page, per, offset, paginas, sin_tabla,
 *               ventana_default (true si se está mirando sólo la ventana
 *               por defecto) y ventana_meses
 */
function actividad_list(array $f): array
{
    if (!actividad_disponible()) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'per' => 25, 'sin_tabla' => true];
    }
    [$where, $p] = actividad_where($f);
    $total = (int) qv("SELECT COUNT(*) FROM actividad a $where", $p);
    $pg    = paginar($total, $f['page'] ?? 1);
    $rows  = q("SELECT a.*, u.email AS usuario_email, u.avatar_url AS usuario_avatar
                FROM actividad a LEFT JOIN usuarios u ON u.id = a.usuario_id
                $where ORDER BY a.creado_en DESC, a.id DESC
                LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + [
        'rows'            => $rows,
        'total'           => $total,
        'sin_tabla'       => false,
        'ventana_default' => empty($f['desde']),
        'ventana_meses'   => ACTIVIDAD_VENTANA_MESES,
    ];
}

/**
 * Conteo por tipo dentro de la ventana por defecto, para las pestañas del
 * CRON. Un tipo que no esté en la lista suma al total pero no tiene casilla.
 */
function actividad_conteo_tipos(string $canal): array
{
    $out = ['total' => 0, 'nav' => 0, 'accion' => 0, 'auth' => 0, 'error' => 0, 'sistema' => 0];
    if (!actividad_disponible()) { return $out; }
    $canal = in_array($canal, ['app', 'api'], true) ? $canal : 'app';
    $piso  = date('Y-m-d', strtotime('-' . ACTIVIDAD_VENTANA_MESES . ' months')) . ' 00:00:00';
    foreach (q('SELECT tipo, COUNT(*) n FROM actividad WHERE canal = ? AND creado_en >= ? GROUP BY tipo',
               [$canal, $piso])->fetchAll() as $r) {
        if (isset($out[$r['tipo']])) { $out[$r['tipo']] = (int)$r['n']; }
        $out['total'] += (int)$r['n'];
    }
    return $out;
}

/**
 * Resumen para las tarjetas del CRON (total, hoy, errores, último).
 *
 * El total cuenta todo el histórico del canal (sin la ventana de meses). Va
 * en consultas separadas que resuelven los índices (canal, creado_en) y
 * (tipo, creado_en): antes una sola consulta leía todas las filas y calculaba
 * DATE() en cada una, y era la que más crecía con el tiempo.
 */
function actividad_resumen(string $canal): array
{
    if (!actividad_disponible()) {
        return ['total' => 0, 'hoy' => 0, 'errores' => 0, 'ultimo' => null];
    }
    $canal = in_array($canal, ['app', 'api'], true) ? $canal : 'app';
    $r = [
        'total'   => qv('SELECT COUNT(*) FROM actividad WHERE canal = ?', [$canal]),
        'hoy'     => qv('SELECT COUNT(*) FROM actividad WHERE canal = ? AND creado_en >= ?', [$canal, date('Y-m-d 00:00:00')]),
        'errores' => qv("SELECT COUNT(*) FROM actividad WHERE tipo = 'error' AND canal = ?", [$canal]),
        'ultimo'  => qv('SELECT MAX(creado_en) FROM actividad WHERE canal = ?', [$canal]),
    ];
    return [
        'total'   => (int)($r['total'] ?? 0),
        'hoy'     => (int)($r['hoy'] ?? 0),
        'errores' => (int)($r['errores'] ?? 0),
        'ultimo'  => $r['ultimo'] ?? null,
    ];
}

/**
 * Línea de tiempo de un registro: el «Historial» de la ficha.
 *
 * Sólo trae eventos de auditoría (tabla `actividad`) con esa entidad e id:
 * altas, ediciones, cambios de estado, y las marcas que dejan
 * actividad_guardar() y actividad_completar() al agendar o completar una
 * actividad vinculada. Las notas y las actividades en sí NO se mezclan acá:
 * cada ficha las muestra en su propio panel.
 *
 * Nunca lanza: si la consulta falla, devuelve lo que haya juntado (nada).
 *
 * @param string $entidad 'contacto' | 'cuenta' | 'posible_cliente' | 'negocio' | …
 * @return array<int,array{cuando:string,clase:string,tipo:string,titulo:string,detalle:?string,actor:?string}>
 */
function timeline(string $entidad, int $id, int $limite = 40): array
{
    $items = [];
    try {
        foreach (q('SELECT creado_en, accion, detalle, actor, tipo FROM actividad
                    WHERE entidad = ? AND entidad_id = ? ORDER BY creado_en DESC LIMIT ?',
                   [$entidad, $id, $limite])->fetchAll() as $r) {
            $items[] = [
                'cuando' => $r['creado_en'], 'clase' => 'auditoria', 'tipo' => $r['tipo'],
                'titulo' => $r['accion'], 'detalle' => $r['detalle'], 'actor' => $r['actor'],
            ];
        }
    } catch (\Throwable $e) {}
    return $items;
}

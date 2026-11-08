<?php
declare(strict_types=1);

/**
 * Actividad / auditoría — alimenta la sección "CRON".
 *
 * Dos canales:
 *   · app  → qué hace cada usuario (altas, ediciones, borrados, conversiones,
 *            importaciones, campañas, login/logout) + todos los errores.
 *   · api  → cada pedido a la API de sólo lectura.
 *
 * `log_evento()` NUNCA debe romper una request: todo va dentro de try/catch.
 * Si la tabla `actividad` no existe todavía, simplemente no registra.
 */

/**
 * Inserta un evento.
 *
 * @param string $canal  'app' | 'api'
 * @param string $accion identificador corto (ej. 'contacto.crear')
 * @param array  $o      tipo|actor|entidad|entidad_id|detalle|usuario_id
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

function log_app(string $accion, array $o = []): void { log_evento('app', $accion, $o); }
function log_api(string $accion, array $o = []): void { log_evento('api', $accion, $o); }

/**
 * Registra un error con un CÓDIGO estable por ubicación (mismo archivo:línea →
 * mismo código), para poder buscarlo y arreglar la causa.
 */
function log_error(string $accion, string $mensaje, string $file, int $line, string $severidad = ''): void
{
    $loc     = basename($file) . ':' . $line;
    $codigo  = 'ERR-' . strtoupper(substr(md5($file . ':' . $line), 0, 6));
    $detalle = $codigo . ' · ' . ($severidad !== '' ? $severidad . ' · ' : '') . $mensaje . ' · ' . $loc;
    log_evento('app', $accion, ['tipo' => 'error', 'actor' => 'sistema', 'detalle' => $detalle]);
}

/** ¿Existe la tabla? (para degradar con gracia si aún no se migró). */
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

/** Lista paginada de eventos. */
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

/** Conteo por tipo dentro de la ventana por defecto. */
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

/** Resumen para las tarjetas del CRON (total, hoy, errores, último). */
function actividad_resumen(string $canal): array
{
    if (!actividad_disponible()) {
        return ['total' => 0, 'hoy' => 0, 'errores' => 0, 'ultimo' => null];
    }
    $canal = in_array($canal, ['app', 'api'], true) ? $canal : 'app';
    $r = q("SELECT COUNT(*) total,
                   SUM(CASE WHEN DATE(creado_en) = CURDATE() THEN 1 ELSE 0 END) hoy,
                   SUM(CASE WHEN tipo = 'error' THEN 1 ELSE 0 END) errores,
                   MAX(creado_en) ultimo
            FROM actividad WHERE canal = ?", [$canal])->fetch();
    return [
        'total'   => (int)($r['total'] ?? 0),
        'hoy'     => (int)($r['hoy'] ?? 0),
        'errores' => (int)($r['errores'] ?? 0),
        'ultimo'  => $r['ultimo'] ?? null,
    ];
}

/**
 * Línea de tiempo de un registro: mezcla lo que hizo la gente (auditoría) con
 * las actividades y notas asociadas. Es lo que en Zoho se ve en la ficha.
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

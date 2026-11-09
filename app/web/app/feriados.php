<?php
declare(strict_types=1);

// ============================================================
//  Feriados: los días en que la agenda no ofrece horarios.
//
//  Los nacionales se mantienen solos, sin que nadie los cargue. Una vez por
//  día se consulta el calendario oficial del año en curso y del siguiente
//  (ArgentinaDatos, que publica el del Ministerio del Interior):
//    · se agregan los que se decretan durante el año (días puente, feriados
//      por decreto);
//    · los trasladables se reubican cuando se fija su fecha: la fecha vieja
//      deja de figurar y aparece la nueva;
//    · se quitan los que dejan de figurar.
//  Lo que carga una persona (origen 'manual') no se toca nunca, y lo que se
//  marca como «se trabaja» (ignorado) no vuelve a aparecer.
//
//  Regionales: el calendario oficial trae sólo los nacionales. Los de una
//  provincia se cargan a mano con su jurisdicción, y rigen los nacionales más
//  los de la jurisdicción del ajuste `feriados_jurisdiccion` (por defecto CABA,
//  donde está la oficina).
//
//  La fuente de afuera nunca puede trabar la agenda: si no responde, se sigue
//  con lo que ya está en la base, se espera un rato antes de volver a probar y
//  la consulta tiene un tiempo de espera corto. Lo peor que pasa con la fuente
//  caída es que un feriado decretado ayer tarde un poco más en aparecer.
// ============================================================

/** Calendario oficial de feriados nacionales; se le agrega el año. */
const FERIADOS_FUENTE = 'https://api.argentinadatos.com/v1/feriados/';

/** Cuánto vale lo sincronizado antes de volver a consultar al vuelo. */
const FERIADOS_VIGENCIA_SEG = 86400;

/** Después de una consulta fallida, cuánto se espera para probar de nuevo. */
const FERIADOS_REINTENTO_SEG = 900;

/** Tiempo de espera de la consulta al vuelo (la del cron espera más). */
const FERIADOS_TIMEOUT_SEG = 3;
const FERIADOS_TIMEOUT_CRON_SEG = 15;

/**
 * Cada año tiene más de diez feriados nacionales: un calendario con menos es
 * un error de la fuente, y no se usa para borrar nada.
 */
const FERIADOS_MINIMO_ANIO = 10;

const FERIADOS_TIPOS = [
    'inamovible'   => 'Inamovible',
    'trasladable'  => 'Trasladable',
    'puente'       => 'Día puente',
    'no_laborable' => 'Día no laborable',
];

/** Jurisdicciones: 'nacional' y las 24 de la Argentina. */
const FERIADOS_JURISDICCIONES = [
    'nacional' => 'Todo el país',
    'CABA' => 'Ciudad de Buenos Aires',
    'BA'   => 'Provincia de Buenos Aires',
    'CA'   => 'Catamarca',
    'CH'   => 'Chaco',
    'CT'   => 'Chubut',
    'CB'   => 'Córdoba',
    'CR'   => 'Corrientes',
    'ER'   => 'Entre Ríos',
    'FO'   => 'Formosa',
    'JY'   => 'Jujuy',
    'LP'   => 'La Pampa',
    'LR'   => 'La Rioja',
    'MZ'   => 'Mendoza',
    'MI'   => 'Misiones',
    'NQ'   => 'Neuquén',
    'RN'   => 'Río Negro',
    'SA'   => 'Salta',
    'SJ'   => 'San Juan',
    'SL'   => 'San Luis',
    'SC'   => 'Santa Cruz',
    'SF'   => 'Santa Fe',
    'SE'   => 'Santiago del Estero',
    'TF'   => 'Tierra del Fuego',
    'TU'   => 'Tucumán',
];

// ------------------------------------------------------------
//  Ajustes
// ------------------------------------------------------------

/** ¿La agenda respeta los feriados? (ajuste `feriados_activo`, por defecto sí). */
function feriados_activos(): bool
{
    return (string) setting_get('feriados_activo', '1') !== '0';
}

/** La jurisdicción cuyos feriados rigen además de los nacionales. */
function feriados_jurisdiccion(): string
{
    $j = (string) setting_get('feriados_jurisdiccion', 'CABA');
    return isset(FERIADOS_JURISDICCIONES[$j]) && $j !== 'nacional' ? $j : 'CABA';
}

/** «Día de la Soberanía Nacional» o «Día del Patrono (sólo Córdoba)». */
function feriado_etiqueta(array $f): string
{
    $j = (string)($f['jurisdiccion'] ?? 'nacional');
    return (string)$f['nombre'] . ($j !== 'nacional' ? ' (sólo ' . (FERIADOS_JURISDICCIONES[$j] ?? $j) . ')' : '');
}

// ------------------------------------------------------------
//  Lógica pura (se prueba sin base ni red)
// ------------------------------------------------------------

/**
 * Lee la respuesta de la fuente para un año.
 *
 * @return array<string,array{nombre:string,tipo:string}>|null por fecha, o null si no es un calendario
 */
function feriados_parsear(string $json, int $anio): ?array
{
    $datos = json_decode($json, true);
    if (!is_array($datos) || !array_is_list($datos)) { return null; }
    $salida = [];
    foreach ($datos as $h) {
        if (!is_array($h)) { continue; }
        $fecha  = (string)($h['fecha'] ?? '');
        $nombre = trim(preg_replace('/\s+/u', ' ', (string)($h['nombre'] ?? '')) ?? '');
        $d      = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($nombre === '' || !$d || $d->format('Y-m-d') !== $fecha || (int)substr($fecha, 0, 4) !== $anio) {
            continue;
        }
        $tipo = (string)($h['tipo'] ?? '');
        $tipo = isset(FERIADOS_TIPOS[$tipo]) ? $tipo : 'inamovible';
        $nombre = mb_substr($nombre, 0, 160);
        // Dos feriados el mismo día (pasa): un solo día sin turnos, con los dos nombres.
        if (isset($salida[$fecha])) {
            if (!str_contains($salida[$fecha]['nombre'], $nombre)) {
                $salida[$fecha]['nombre'] = mb_substr($salida[$fecha]['nombre'] . ' · ' . $nombre, 0, 160);
            }
            continue;
        }
        $salida[$fecha] = ['nombre' => $nombre, 'tipo' => $tipo];
    }
    ksort($salida);
    return $salida;
}

/**
 * Qué hay que cambiar en la base para que un año quede como el oficial.
 *
 * $actuales son las filas NACIONALES de ese año (fecha, id, nombre, tipo,
 * origen, ignorado). Reglas:
 *   · una fecha oficial que no está, se agrega;
 *   · una automática cuyo nombre o tipo cambió, se actualiza;
 *   · una automática que ya no figura (trasladable que se movió, puente que
 *     se dejó sin efecto), se borra;
 *   · las manuales no se tocan, y las ignoradas quedan ignoradas.
 *
 * @return array{insertar:array<string,array>,actualizar:array<int,array>,borrar:array<int,array>}
 */
function feriados_planificar(array $oficial, array $actuales): array
{
    $porFecha = [];
    foreach ($actuales as $f) { $porFecha[(string)$f['fecha']] = $f; }

    $plan = ['insertar' => [], 'actualizar' => [], 'borrar' => []];
    foreach ($oficial as $fecha => $h) {
        $tiene = $porFecha[$fecha] ?? null;
        if (!$tiene) {
            $plan['insertar'][$fecha] = $h;
        } elseif ($tiene['origen'] === 'auto'
                  && ($tiene['nombre'] !== $h['nombre'] || $tiene['tipo'] !== $h['tipo'])) {
            $plan['actualizar'][(int)$tiene['id']] = $h + ['fecha' => $fecha];
        }
    }
    foreach ($porFecha as $fecha => $tiene) {
        if ($tiene['origen'] === 'auto' && !isset($oficial[$fecha])) {
            $plan['borrar'][(int)$tiene['id']] = $tiene;
        }
    }
    return $plan;
}

/**
 * De las filas de feriados, las que rigen: sin las ignoradas, nacionales o de
 * la jurisdicción dada. Si el mismo día es feriado nacional y regional, queda
 * el nacional.
 *
 * @return array<string,array> por fecha
 */
function feriados_aplicables(array $filas, string $jurisdiccion): array
{
    $salida = [];
    foreach ($filas as $f) {
        $j = (string)($f['jurisdiccion'] ?? 'nacional');
        if (!empty($f['ignorado']) || ($j !== 'nacional' && $j !== $jurisdiccion)) { continue; }
        $fecha = (string)$f['fecha'];
        if (isset($salida[$fecha]) && $salida[$fecha]['jurisdiccion'] === 'nacional') { continue; }
        $salida[$fecha] = [
            'fecha'        => $fecha,
            'nombre'       => (string)$f['nombre'],
            'tipo'         => (string)($f['tipo'] ?? 'inamovible'),
            'jurisdiccion' => $j,
            'origen'       => (string)($f['origen'] ?? 'auto'),
        ];
    }
    ksort($salida);
    return $salida;
}

/**
 * ¿Hay que consultar la fuente ahora?
 *
 *   · si falló hace menos de FERIADOS_REINTENTO_SEG, no: no se martilla una
 *     fuente caída ni se hace esperar a cada visitante;
 *   · si no hay datos del año o nunca se sincronizó, sí;
 *   · si lo sincronizado tiene más de un día, sí;
 *   · el cron ($diaria) además sincroniza una vez por día calendario.
 */
function feriados_toca(int $ahora, string $sincronizadoEn, string $intentoEn, bool $hayDatos, bool $diaria): bool
{
    $ultimo  = $sincronizadoEn !== '' ? (int) strtotime($sincronizadoEn) : 0;
    $intento = $intentoEn !== '' ? (int) strtotime($intentoEn) : 0;
    if ($intento > $ultimo && $intento > $ahora - FERIADOS_REINTENTO_SEG) { return false; }
    if (!$hayDatos || $ultimo === 0) { return true; }
    if ($ahora - $ultimo >= FERIADOS_VIGENCIA_SEG) { return true; }
    return $diaria && date('Y-m-d', $ultimo) !== date('Y-m-d', $ahora);
}

// ------------------------------------------------------------
//  Fuente
// ------------------------------------------------------------

/**
 * Feriados nacionales publicados para un año, o null si la fuente no respondió.
 *
 * @param string|null $error sale con el motivo cuando devuelve null
 */
function feriados_descargar(int $anio, int $timeout = FERIADOS_TIMEOUT_SEG, ?string &$error = null): ?array
{
    $error = null;
    if (!function_exists('curl_init')) { $error = 'este PHP no tiene curl'; return null; }
    $ch = curl_init(FERIADOS_FUENTE . $anio);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(1, $timeout),
        CURLOPT_CONNECTTIMEOUT => max(1, min(3, $timeout)),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'CRM Intellihelp (agenda de reuniones)',
    ]);
    $cuerpo = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $falla  = curl_error($ch);
    curl_close($ch);
    if ($codigo !== 200 || !is_string($cuerpo)) {
        $error = 'la fuente respondió ' . ($codigo ?: 'sin conexión') . ($falla !== '' ? ' (' . $falla . ')' : '');
        return null;
    }
    $datos = feriados_parsear($cuerpo, $anio);
    if ($datos === null) { $error = 'la fuente respondió algo que no es un calendario'; }
    return $datos;
}

// ------------------------------------------------------------
//  Sincronización
// ------------------------------------------------------------

/**
 * Deja los feriados nacionales de los años pedidos como el calendario oficial.
 *
 * Un año para el que la fuente no responde, o responde con un calendario
 * incompleto, no se modifica: se sigue con lo que había.
 *
 * $fuente: fn(int $anio): ?array con el formato de feriados_parsear(). Por
 * defecto, feriados_descargar(). Las pruebas pasan una que simula la fuente.
 *
 * @param int[]|null $anios por defecto el actual y el siguiente
 * @return array{agregados:array,quitados:array,actualizados:int,anios:int[],fallidos:int[],error:?string}
 */
function feriados_sincronizar(?array $anios = null, ?callable $fuente = null, int $timeout = FERIADOS_TIMEOUT_SEG): array
{
    $anios ??= [(int)date('Y'), (int)date('Y') + 1];
    $errores = [];
    $fuente ??= static function (int $anio) use ($timeout, &$errores): ?array {
        $e = null;
        $d = feriados_descargar($anio, $timeout, $e);
        if ($e !== null) { $errores[] = $anio . ': ' . $e; }
        return $d;
    };
    $res = ['agregados' => [], 'quitados' => [], 'actualizados' => 0, 'anios' => [], 'fallidos' => [], 'error' => null];
    setting_set('feriados_intento_en', date('Y-m-d H:i:s'));

    foreach ($anios as $anio) {
        $anio = (int)$anio;
        try {
            $oficial = $fuente($anio);
        } catch (\Throwable $e) {
            $errores[] = $anio . ': ' . $e->getMessage();
            $oficial = null;
        }
        if (!is_array($oficial) || count($oficial) < FERIADOS_MINIMO_ANIO) {
            if (is_array($oficial)) { $errores[] = $anio . ': calendario incompleto (' . count($oficial) . ' días)'; }
            $res['fallidos'][] = $anio;
            continue;
        }

        $actuales = q("SELECT id, fecha, nombre, tipo, origen, ignorado FROM feriados
                       WHERE jurisdiccion = 'nacional' AND fecha BETWEEN ? AND ?",
                      [$anio . '-01-01', $anio . '-12-31'])->fetchAll();
        $plan = feriados_planificar($oficial, $actuales);
        foreach ($plan['insertar'] as $fecha => $h) {
            q("INSERT INTO feriados (fecha, nombre, tipo, jurisdiccion, origen, ignorado)
               VALUES (?, ?, ?, 'nacional', 'auto', 0)", [$fecha, $h['nombre'], $h['tipo']]);
            $res['agregados'][] = ['fecha' => $fecha, 'nombre' => $h['nombre'], 'jurisdiccion' => 'nacional'];
        }
        foreach ($plan['actualizar'] as $id => $h) {
            q('UPDATE feriados SET nombre = ?, tipo = ? WHERE id = ?', [$h['nombre'], $h['tipo'], $id]);
            $res['actualizados']++;
        }
        foreach ($plan['borrar'] as $id => $f) {
            q('DELETE FROM feriados WHERE id = ?', [$id]);
            $res['quitados'][] = ['fecha' => (string)$f['fecha'], 'nombre' => (string)$f['nombre'], 'jurisdiccion' => 'nacional'];
        }
        $res['anios'][] = $anio;
    }

    // El año en curso es el que importa: el siguiente puede no estar publicado
    // todavía, y eso no es una falla.
    $actual = (int)date('Y');
    if ($res['anios'] && (!in_array($actual, $anios, true) || in_array($actual, $res['anios'], true))) {
        setting_set('feriados_sincronizado_en', date('Y-m-d H:i:s'));
        setting_set('feriados_ultimo_error', '');
    } else {
        $res['error'] = $errores ? implode(' · ', $errores) : 'la fuente no respondió';
        setting_set('feriados_ultimo_error', date('Y-m-d H:i') . ' · ' . mb_substr($res['error'], 0, 400));
    }

    if ($res['agregados'] || $res['quitados'] || $res['error']) {
        $partes = [];
        foreach ($res['agregados'] as $f) { $partes[] = 'nuevo ' . $f['fecha'] . ' ' . $f['nombre']; }
        foreach ($res['quitados'] as $f)  { $partes[] = 'ya no es feriado ' . $f['fecha'] . ' ' . $f['nombre']; }
        if ($res['error']) { $partes[] = 'sin actualizar: ' . $res['error']; }
        log_app('feriados.sincronizar', ['tipo' => $res['error'] && !$res['anios'] ? 'error' : 'sistema',
            'actor' => 'sistema', 'detalle' => mb_substr(implode(' | ', $partes), 0, 900)]);
    }
    if ($res['agregados']) { feriados_avisar_reuniones($res['agregados']); }
    $GLOBALS['__feriados_version'] = ($GLOBALS['__feriados_version'] ?? 0) + 1;
    return $res;
}

/** ¿Hay feriados cargados del año? (si no, faltan datos y hay que sincronizar). */
function feriados_hay_datos(int $anio): bool
{
    return (bool) qv("SELECT 1 FROM feriados WHERE jurisdiccion = 'nacional' AND fecha BETWEEN ? AND ? LIMIT 1",
                     [$anio . '-01-01', $anio . '-12-31']);
}

/**
 * Sincroniza si hace falta. Nunca lanza: los feriados no pueden romper la agenda.
 *
 * Se llama al vuelo antes de calcular horarios (una vez por pedido, con un
 * tiempo de espera corto) y desde el cron ($diaria, una vez por día).
 * $forzar es el botón «Actualizar ahora» de la pantalla de feriados.
 *
 * @return array|null el resultado de feriados_sincronizar(), o null si no tocaba
 */
function feriados_asegurar(bool $diaria = false, bool $forzar = false, ?callable $fuente = null): ?array
{
    static $hecho = false;
    if ($hecho && !$forzar && !$diaria) { return null; }
    $hecho = true;
    try {
        if (!feriados_activos() && !$forzar) { return null; }
        if (!$forzar) {
            // El cron corre varios minutos: otro proceso pudo haber sincronizado.
            if ($diaria && function_exists('setting_refrescar')) { setting_refrescar(); }
            $toca = feriados_toca(time(), (string) setting_get('feriados_sincronizado_en', ''),
                                  (string) setting_get('feriados_intento_en', ''),
                                  feriados_hay_datos((int)date('Y')), $diaria);
            if (!$toca) { return null; }
        }
        // Una sola sincronización a la vez: el resto sigue con lo que hay.
        if (!feriados_bloquear()) { return null; }
        try {
            return feriados_sincronizar(null, $fuente, $diaria || $forzar ? FERIADOS_TIMEOUT_CRON_SEG : FERIADOS_TIMEOUT_SEG);
        } finally {
            feriados_liberar();
        }
    } catch (\Throwable $e) {
        // Sin la migración 009 todavía no hay tabla: la agenda sigue como antes.
        if (!($e instanceof \PDOException && $e->getCode() === '42S02')) {
            log_error('feriados.asegurar', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
        }
        return null;
    }
}

function feriados_bloquear(): bool
{
    try {
        return (int) qv("SELECT GET_LOCK(CONCAT(DATABASE(), ':feriados'), 0)") === 1;
    } catch (\Throwable $e) {
        // Una base sin bloqueos con nombre (la de las pruebas): se sigue igual,
        // la sincronización es idempotente.
        return true;
    }
}

function feriados_liberar(): void
{
    try { q("DO RELEASE_LOCK(CONCAT(DATABASE(), ':feriados'))"); } catch (\Throwable $e) {}
}

// ------------------------------------------------------------
//  Consultas para la agenda
// ------------------------------------------------------------

/**
 * Feriados que rigen entre dos fechas (Y-m-d, incluidas), por fecha.
 *
 * Si hace falta, antes sincroniza (al vuelo, con poco tiempo de espera). Si la
 * tabla no existe o la base falla, devuelve una lista vacía: la agenda sigue.
 *
 * @return array<string,array{fecha:string,nombre:string,tipo:string,jurisdiccion:string,origen:string}>
 */
function feriados_del_rango(string $desde, string $hasta): array
{
    if (!feriados_activos()) { return []; }
    feriados_asegurar();
    try {
        $j = feriados_jurisdiccion();
        $filas = q("SELECT fecha, nombre, tipo, jurisdiccion, origen, ignorado FROM feriados
                    WHERE ignorado = 0 AND fecha BETWEEN ? AND ? AND jurisdiccion IN ('nacional', ?)
                    ORDER BY fecha", [$desde, $hasta, $j])->fetchAll();
        return feriados_aplicables($filas, $j);
    } catch (\Throwable $e) {
        return [];
    }
}

/** El feriado que rige ese día (Y-m-d), o null. */
function feriado_del_dia(string $fecha): ?array
{
    return feriados_del_rango($fecha, $fecha)[$fecha] ?? null;
}

/** Para la API: la lista de feriados del rango, con lo que la web necesita mostrar. */
function feriados_para_api(string $desde, string $hasta): array
{
    $salida = [];
    foreach (feriados_del_rango($desde, $hasta) as $f) {
        $salida[] = [
            'fecha'        => $f['fecha'],
            'nombre'       => $f['nombre'],
            'tipo'         => $f['tipo'],
            'nacional'     => $f['jurisdiccion'] === 'nacional',
            'jurisdiccion' => $f['jurisdiccion'],
        ];
    }
    return $salida;
}

// ------------------------------------------------------------
//  Aviso al responsable
// ------------------------------------------------------------

/**
 * Un feriado nuevo cayó sobre reuniones ya agendadas: se le avisa a quien
 * atiende cada una, para que coordine con el invitado si se mantiene o se
 * mueve. La reunión no se cancela sola: la decisión es de una persona.
 *
 * $nuevos: [['fecha', 'nombre', 'jurisdiccion'], …]. Sólo cuentan los que
 * rigen (nacionales o de la jurisdicción configurada).
 *
 * @return int reuniones avisadas
 */
function feriados_avisar_reuniones(array $nuevos): int
{
    $j = feriados_jurisdiccion();
    $porFecha = [];
    foreach ($nuevos as $f) {
        $fj = (string)($f['jurisdiccion'] ?? 'nacional');
        if ($fj === 'nacional' || $fj === $j) { $porFecha[(string)$f['fecha']] = $f; }
    }
    if (!$porFecha) { return 0; }
    $n = 0;
    try {
        $ph = implode(', ', array_fill(0, count($porFecha), '?'));
        $reuniones = q("SELECT r.id, r.usuario_id, r.inicia_el, r.invitado_nombre, r.invitado_empresa, t.nombre AS tipo
                        FROM agenda_reservas r JOIN agenda_tipos t ON t.id = r.tipo_id
                        WHERE r.estado = 'confirmada' AND r.inicia_el > ? AND DATE(r.inicia_el) IN ($ph)
                        ORDER BY r.inicia_el",
                       [date('Y-m-d H:i:s'), ...array_keys($porFecha)])->fetchAll();
        foreach ($reuniones as $r) {
            $f = $porFecha[substr((string)$r['inicia_el'], 0, 10)] ?? null;
            if (!$f) { continue; }
            notificar('reunion', 'Un feriado nuevo cae sobre una reunión agendada', [
                'usuario_id' => (int)$r['usuario_id'],
                'detalle'    => 'El ' . fecha_corta((string)$r['inicia_el']) . ' pasó a ser feriado ('
                              . feriado_etiqueta($f) . '). Tenés «' . $r['tipo'] . '» con ' . $r['invitado_nombre']
                              . (!empty($r['invitado_empresa']) ? ' (' . $r['invitado_empresa'] . ')' : '')
                              . ' a las ' . date('H:i', (int) strtotime((string)$r['inicia_el']))
                              . '. Coordiná con el invitado si se mantiene o la movés.',
                'entidad'    => 'reserva',
                'entidad_id' => (int)$r['id'],
                'url'        => 'reservas',
            ]);
            $n++;
        }
    } catch (\Throwable $e) {
        log_error('feriados.avisar', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
    return $n;
}

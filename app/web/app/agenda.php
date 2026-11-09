<?php
declare(strict_types=1);

require_once __DIR__ . '/gcal.php';
require_once __DIR__ . '/feriados.php';

// ============================================================
//  Agenda propia de reuniones — nuestro reemplazo de Calendly.
//
//  Cómo funciona, de punta a punta:
//    1. Cada vendedor define uno o varios «tipos de reunión» (duración,
//       horario laboral, antelación mínima, hasta cuántos días a futuro).
//    2. El enlace público /r/<usuario>/<tipo> muestra los huecos reales:
//       horario laboral MENOS lo que ya está ocupado en su Google Calendar
//       MENOS lo que ya reservaron por acá.
//    3. Al reservar queda todo en el CRM —reserva, contacto, empresa y la
//       Reunión— y enseguida se crea el evento en Google Calendar con Google
//       Meet y se manda la confirmación con la invitación para el calendario.
//    4. Lo mismo entra desde la web (intellihelp.tech/diagnostico/) por la
//       API: agenda_huecos_rango() para los horarios, agenda_reservar() y
//       agenda_reserva_completar() para la reserva.
//
//  Una sola invitación por reunión, la del CRM: Google crea el evento sin
//  avisarle a nadie y la invitación del correo usa el mismo identificador que
//  el evento de Google, así el cliente no queda con dos reuniones iguales.
//
//  Los feriados (feriados.php) no tienen turnos: ni en la web, ni en el
//  enlace público, ni al mover una reunión, ni en los días de una cobertura.
//
//  No dependemos de ningún servicio externo ni de ninguna cuenta paga.
// ============================================================

/** Segundos que espera la consulta a Google en la verificación final de una reserva. */
const AGENDA_TIMEOUT_VERIFICACION = 6;

/** Segundos que sirve la consulta a Google para mostrar horarios (páginas públicas y web). */
const AGENDA_CACHE_HORARIOS = 60;

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

/** La zona pedida si existe; si no, la de Argentina. */
function agenda_zona_valida(string $zona): string
{
    return in_array($zona, DateTimeZone::listIdentifiers(), true) ? $zona : AGENDA_ZONA_DEFECTO;
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
        'zona_horaria'    => agenda_zona_valida((string)($d['zona_horaria'] ?? '')),
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

// ------------------------------------------------------------
//  Zona horaria del tipo de reunión
//
//  El horario laboral («de 9 a 18») está escrito en la hora del vendedor, no
//  en la del servidor, así que se calcula con la `zona_horaria` del tipo: con
//  la zona de la instalación, a un vendedor en otro país la agenda le
//  ofrecería los horarios corridos. En la base las fechas se guardan en la
//  hora de la instalación, como el resto del CRM.
// ------------------------------------------------------------

const AGENDA_ZONA_DEFECTO = 'America/Argentina/Buenos_Aires';

function agenda_zona(array $tipo): DateTimeZone
{
    $z = (string)($tipo['zona_horaria'] ?? '');
    try {
        return new DateTimeZone($z !== '' ? $z : AGENDA_ZONA_DEFECTO);
    } catch (\Throwable $e) {
        return new DateTimeZone(AGENDA_ZONA_DEFECTO);
    }
}

/** Un momento en la hora del tipo de reunión (por defecto, 'Y-m-d H:i:s'). */
function agenda_local(int $ts, array $tipo, string $formato = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(agenda_zona($tipo))->format($formato);
}

/** «Hora de Argentina (UTC−3)», para mostrarle al visitante en qué hora está viendo todo. */
function agenda_zona_etiqueta(array $tipo): string
{
    $tz  = agenda_zona($tipo);
    $seg = $tz->getOffset(new DateTimeImmutable('now', $tz));
    $h   = intdiv(abs($seg), 3600);
    $m   = intdiv(abs($seg) % 3600, 60);
    $utc = 'UTC' . ($seg < 0 ? '−' : '+') . $h . ($m ? ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) : '');
    $nombre = str_starts_with($tz->getName(), 'America/Argentina/')
        ? 'Argentina'
        : str_replace('_', ' ', (string)substr((string)strrchr('/' . $tz->getName(), '/'), 1));
    return 'Hora de ' . $nombre . ' (' . $utc . ')';
}

/** Zonas que se ofrecen en el formulario del tipo de reunión. */
function agenda_zonas_comunes(): array
{
    return [
        'America/Argentina/Buenos_Aires' => 'Argentina',
        'America/Montevideo'             => 'Uruguay',
        'America/Santiago'               => 'Chile',
        'America/Asuncion'               => 'Paraguay',
        'America/Sao_Paulo'              => 'Brasil (San Pablo)',
        'America/La_Paz'                 => 'Bolivia',
        'America/Lima'                   => 'Perú',
        'America/Bogota'                 => 'Colombia',
        'America/Mexico_City'            => 'México (Ciudad de México)',
        'America/New_York'               => 'Estados Unidos (Nueva York)',
        'Europe/Madrid'                  => 'España',
        'UTC'                            => 'UTC',
    ];
}

// ------------------------------------------------------------
//  Errores de la agenda
// ------------------------------------------------------------

/**
 * Error de la agenda con un motivo que se puede traducir a un código.
 *
 * El motivo le permite a quien llama distinguir «ese horario ya lo tomaron»
 * (hay que volver a pedir los horarios) de «el correo está mal escrito» (hay
 * que marcar el campo). Extiende RuntimeException, así que el código que
 * atrapa esa excepción la sigue atrapando.
 */
final class AgendaExcepcion extends RuntimeException
{
    public function __construct(
        string $mensaje,                          // texto para el visitante, en castellano
        public readonly string $motivo = 'datos_invalidos',
        // horario_tomado | horario_invalido | datos_invalidos | sin_calendario | ocupado | limite | feriado
        public readonly ?string $campo = null,    // nombre, email, telefono, inicio…
        public readonly array $datos = [],        // lo que la API devuelve además del mensaje (el feriado)
    ) { parent::__construct($mensaje); }
}

/**
 * Tipo de reunión activo por slug, para lo que llega de afuera (la web, la API).
 *
 * Con $usuario busca el de ese vendedor. Sin él, tiene que haber uno solo
 * con ese slug: si dos vendedores tienen un «diagnostico», adivinar cuál es
 * mandaría la reunión al calendario equivocado.
 *
 * @param string|null $motivo sale con 'inexistente' o 'ambiguo' cuando devuelve null
 */
function agenda_tipo_publico(string $slug, ?string $usuario = null, ?string &$motivo = null): ?array
{
    $motivo = null;
    $slug = strtolower(trim($slug));
    if (!preg_match('/^[a-z0-9-]{1,60}$/', $slug)) { $motivo = 'inexistente'; return null; }

    if ($usuario !== null && $usuario !== '') {
        $usuario = strtolower(trim($usuario));
        if (!preg_match('/^[a-z0-9._-]{1,64}$/', $usuario)) { $motivo = 'inexistente'; return null; }
        $t = agenda_tipo_por_slug($usuario, $slug);
        if (!$t) { $motivo = 'inexistente'; }
        return $t;
    }

    $filas = q("SELECT t.*, u.nombre AS usuario, u.email AS usuario_email
                FROM agenda_tipos t JOIN usuarios u ON u.id = t.usuario_id
                WHERE t.slug = ? AND t.activo = 1 AND u.activo = 1
                LIMIT 2", [$slug])->fetchAll();
    if (!$filas)          { $motivo = 'inexistente'; return null; }
    if (count($filas) > 1) { $motivo = 'ambiguo';     return null; }
    return $filas[0];
}

// ------------------------------------------------------------
//  Cobertura: otra persona atiende un tipo de reunión por unos días
//
//  Vacaciones, una licencia, un viaje. La web y el enlace público siguen
//  pidiendo el tipo de siempre (cbaldelli/diagnostico), pero los días
//  cubiertos los horarios salen del calendario de quien cubre, y la reunión
//  queda a su nombre: evento y Meet en su Google, la confirmación firmada por
//  él y las respuestas a su correo. Pasado el período vuelve sola al dueño
//  del tipo, sin que nadie tenga que acordarse de cambiar nada.
//
//  Ajuste `agenda_coberturas`: una lista JSON de
//      {"tipo_id": 4, "usuario_id": 4, "desde": "2026-11-09", "hasta": "2026-11-24"}
//  con las dos fechas incluidas, en la zona horaria del tipo.
//
//  Una reunión ya tomada no cambia de manos: si quien la reservó le cambia el
//  horario, la sigue atendiendo la misma persona y los horarios que se le
//  ofrecen son los de su calendario.
// ------------------------------------------------------------

/**
 * Las coberturas válidas de un tipo, leídas del JSON del ajuste. Pura.
 *
 * @return array<int,array{usuario_id:int,desde:string,hasta:string}>
 */
function agenda_coberturas_leer(string $json, int $tipoId): array
{
    $todas  = json_decode($json, true);
    $fecha  = static fn($v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
    $salida = [];
    foreach (is_array($todas) ? $todas : [] as $c) {
        if (!is_array($c) || (int)($c['tipo_id'] ?? 0) !== $tipoId || (int)($c['usuario_id'] ?? 0) <= 0) { continue; }
        if (!$fecha($c['desde'] ?? null) || !$fecha($c['hasta'] ?? null) || $c['hasta'] < $c['desde']) { continue; }
        $salida[] = ['usuario_id' => (int)$c['usuario_id'], 'desde' => $c['desde'], 'hasta' => $c['hasta']];
    }
    return $salida;
}

/** Quién cubre ese día (Y-m-d), o null si lo atiende el dueño del tipo. Pura. */
function agenda_cobertura_del_dia(array $coberturas, string $fecha): ?int
{
    foreach ($coberturas as $c) {
        if ($fecha >= $c['desde'] && $fecha <= $c['hasta']) { return (int)$c['usuario_id']; }
    }
    return null;
}

function agenda_coberturas(int $tipoId): array
{
    return agenda_coberturas_leer((string) setting_get('agenda_coberturas', ''), $tipoId);
}

/** Quién atiende el tipo ese día (Y-m-d en la zona del tipo). */
function agenda_anfitrion_id(array $tipo, string $fecha): int
{
    $cubre = agenda_cobertura_del_dia(agenda_coberturas((int)$tipo['id']), $fecha);
    // Si quien cubre ya no está activo, la reunión vuelve al dueño: que al
    // menos le llegue a alguien que la ve.
    if ($cubre && $cubre !== (int)$tipo['usuario_id']
        && qv('SELECT 1 FROM usuarios WHERE id = ? AND activo = 1', [$cubre])) {
        return $cubre;
    }
    return (int)$tipo['usuario_id'];
}

/**
 * El tipo con esa persona como anfitrión, ya fijo: no se le vuelve a aplicar
 * ninguna cobertura. Cambian el usuario, su nombre y su correo, que es lo que
 * usan el calendario, la confirmación y el «responder a».
 */
function agenda_tipo_con_anfitrion(array $tipo, int $usuarioId): array
{
    if ($usuarioId > 0 && $usuarioId !== (int)$tipo['usuario_id']) {
        $u = q1('SELECT nombre, email FROM usuarios WHERE id = ?', [$usuarioId]);
        if ($u) {
            $tipo['usuario_id']    = $usuarioId;
            $tipo['usuario']       = $u['nombre'];
            $tipo['usuario_email'] = $u['email'];
        }
    }
    $tipo['anfitrion_fijo'] = true;
    return $tipo;
}

/** El tipo como queda para una reunión que empieza en $inicio (con la cobertura de ese día). */
function agenda_tipo_para(array $tipo, int $inicio): array
{
    if (!empty($tipo['anfitrion_fijo'])) { return $tipo; }
    return agenda_tipo_con_anfitrion($tipo, agenda_anfitrion_id($tipo, agenda_local($inicio, $tipo, 'Y-m-d')));
}

/** El tipo de una reserva ya tomada, con quien la atiende (que puede no ser el dueño del tipo). */
function agenda_tipo_de_reserva(array $r): ?array
{
    $tipo = agenda_tipo_get((int)$r['tipo_id']);
    return $tipo ? agenda_tipo_con_anfitrion($tipo, (int)$r['usuario_id']) : null;
}

/**
 * Huecos de un tipo con coberturas: cada tramo de días seguidos con el mismo
 * anfitrión se calcula con el calendario de quien atiende esos días.
 *
 * Un tramo cuyo calendario no se pudo leer no publica sus días (el mismo
 * criterio de siempre: sin ver el calendario no se ofrece nada), pero no
 * apaga los demás. Si no quedó ningún horario, el motivo es ese.
 */
function agenda_huecos_por_anfitrion(array $tipo, string $desde, string $hasta, ?string &$motivo,
                                     ?int $ignorarReservaId, array $op): array
{
    $motivo = null;
    [, , $fechas] = agenda_ventana($tipo, $desde, $hasta, time());
    $tramos = [];
    foreach ($fechas as $f) {
        $u = agenda_anfitrion_id($tipo, $f);
        $n = count($tramos);
        if ($n && $tramos[$n - 1][0] === $u) { $tramos[$n - 1][2] = $f; }
        else { $tramos[] = [$u, $f, $f]; }
    }
    $huecos = [];
    $sinCalendario = false;
    foreach ($tramos as [$u, $d1, $d2]) {
        $m = null;
        $huecos += agenda_huecos_rango(agenda_tipo_con_anfitrion($tipo, $u), $d1, $d2, $m, $ignorarReservaId, $op);
        if ($m === 'sin_calendario') { $sinCalendario = true; }
        elseif ($m !== null && $motivo === null) { $motivo = $m; }
    }
    if ($sinCalendario && !$huecos) { $motivo = 'sin_calendario'; }
    ksort($huecos);
    return $huecos;
}

// ------------------------------------------------------------
//  Cálculo de huecos
// ------------------------------------------------------------

/** Horario laboral del tipo por día de la semana: dia (0 = domingo) => [[desde, hasta], …]. */
function agenda_franjas(array $tipo): array
{
    $porDia = [];
    foreach (agenda_horarios_de((int)$tipo['id']) as $h) {
        $porDia[(int)$h['dia']][] = [(string)$h['desde'], (string)$h['hasta']];
    }
    return $porDia;
}

/** Una fecha Y-m-d en la zona dada, o null si no es una fecha válida. */
function agenda_fecha(string $fecha, DateTimeZone $tz): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $tz);
    return ($d && $d->format('Y-m-d') === $fecha) ? $d : null;
}

/**
 * Ventana de cálculo: [timeMin, timeMax, fechas].
 *
 * timeMax es el FINAL del último día: si la consulta a Google terminara al
 * comienzo, los horarios de ese día se ofrecerían sin haber mirado el
 * calendario.
 *
 * Recorta al rango [hoy, hoy + ventana_dias] del tipo y a 31 días como mucho.
 * Una fecha mal escrita se toma como «hoy» (desde) o como el límite (hasta).
 *
 * @return array{0:int,1:int,2:array<int,string>}
 */
function agenda_ventana(array $tipo, string $desde, string $hasta, int $ahora): array
{
    $tz     = agenda_zona($tipo);
    $hoy    = (new DateTimeImmutable('@' . $ahora))->setTimezone($tz)->setTime(0, 0);
    $limite = $hoy->modify('+' . max(1, (int)($tipo['ventana_dias'] ?? 30)) . ' days');

    $d = agenda_fecha($desde, $tz) ?? $hoy;
    if ($d < $hoy) { $d = $hoy; }
    $h = agenda_fecha($hasta, $tz) ?? $limite;
    if ($h > $limite) { $h = $limite; }
    $tope = $d->modify('+30 days');
    if ($h > $tope) { $h = $tope; }

    if ($h < $d) { return [$ahora, $ahora, []]; }

    $fechas = [];
    for ($x = $d; $x <= $h; $x = $x->modify('+1 day')) {
        $fechas[] = $x->format('Y-m-d');
    }
    $timeMin = max($d->getTimestamp(), $ahora);
    $timeMax = $h->modify('+1 day')->getTimestamp();
    return [$timeMin, $timeMax, $fechas];
}

/**
 * Inicios libres de un día. Pura: no lee la base ni Google, para poder probarla.
 *
 * Los inicios avanzan de a una duración desde el comienzo de cada franja. Un
 * inicio sirve si la reunión entera entra en la franja, no empieza antes de
 * $minimoTs (la antelación mínima) y no se acerca a nada ocupado a menos del
 * margen, ni antes ni después.
 *
 * @param array  $franjas [[desde 'H:i[:s]', hasta], …] de ese día
 * @param array  $ocupado [[inicio, fin], …] en segundos Unix
 * @param DateTimeZone|string|null $zona la del tipo; sin ella, la de la instalación
 * @return array<int,int> inicios en segundos Unix, ordenados
 */
function agenda_slots_dia(string $fecha, array $franjas, int $duracionSeg, int $margenSeg,
                          int $minimoTs, array $ocupado, DateTimeZone|string|null $zona = null): array
{
    if ($duracionSeg <= 0) { return []; }
    try {
        $tz = $zona instanceof DateTimeZone ? $zona
            : new DateTimeZone($zona !== null && $zona !== '' ? $zona : date_default_timezone_get());
    } catch (\Throwable $e) {
        $tz = new DateTimeZone(AGENDA_ZONA_DEFECTO);
    }
    if (!agenda_fecha($fecha, $tz)) { return []; }

    $salida = [];
    foreach ($franjas as $f) {
        [$desde, $hasta] = array_values((array)$f) + [null, null];
        try {
            $ini = (new DateTimeImmutable($fecha . ' ' . $desde, $tz))->getTimestamp();
            $fin = (new DateTimeImmutable($fecha . ' ' . $hasta, $tz))->getTimestamp();
        } catch (\Throwable $e) {
            continue;
        }
        for ($t = $ini; $t + $duracionSeg <= $fin; $t += $duracionSeg) {
            if ($t < $minimoTs) { continue; }
            $tFin  = $t + $duracionSeg;
            $libre = true;
            foreach ($ocupado as $o) {
                [$oi, $of] = array_values((array)$o) + [0, 0];
                if ($t < (int)$of + $margenSeg && (int)$oi - $margenSeg < $tFin) { $libre = false; break; }
            }
            if ($libre) { $salida[] = $t; }
        }
    }
    $salida = array_values(array_unique($salida));
    sort($salida);
    return $salida;
}

/**
 * Huecos disponibles de un tipo de reunión entre dos fechas (Y-m-d, inclusive).
 *
 * Si no se puede consultar el calendario de Google devuelve una lista vacía y
 * deja el motivo en $motivo. NO publica los horarios «por las dudas»: sin ver
 * el calendario no hay forma de saber qué está ocupado, y ofrecerlo todo igual
 * termina con dos reuniones encima de la misma hora. Es preferible que la
 * página diga que no hay horarios a que agende sobre algo que ya estaba.
 *
 * $ignorarReservaId es la reserva que se está moviendo: no tiene que ocupar su
 * propio horario. Se ignora su fila y, en lo ocupado de Google, la franja que
 * coincide exactamente con ella (si Google la juntó con una reunión pegada no
 * coincide, y ese horario no se ofrece: es lo prudente).
 *
 * @param string|null $motivo sale con 'sin_calendario' o 'sin_horario'
 * @return array<string,array<int,int>> fecha (Y-m-d) => lista de timestamps de inicio
 */
function agenda_huecos_rango(array $tipo, string $desde, string $hasta,
                             ?string &$motivo = null, ?int $ignorarReservaId = null,
                             array $op = []): array
{
    $motivo = null;
    if (empty($tipo['anfitrion_fijo']) && agenda_coberturas((int)$tipo['id'])) {
        return agenda_huecos_por_anfitrion($tipo, $desde, $hasta, $motivo, $ignorarReservaId, $op);
    }
    $porDia = agenda_franjas($tipo);
    if (!$porDia) { $motivo = 'sin_horario'; return []; }

    $ahora = time();
    [$tMin, $tMax, $fechas] = agenda_ventana($tipo, $desde, $hasta, $ahora);
    if (!$fechas) { return []; }

    // Los feriados no tienen turnos. Se sacan antes de consultar a Google: un
    // rango que es todo feriado no necesita mirar el calendario.
    $feriados = feriados_del_rango($fechas[0], $fechas[count($fechas) - 1]);
    if ($feriados) {
        $fechas = array_values(array_filter($fechas, static fn(string $f): bool => !isset($feriados[$f])));
        if (!$fechas) { return []; }
    }

    $tz       = agenda_zona($tipo);
    $usuario  = (int)$tipo['usuario_id'];
    $duracion = (int)$tipo['duracion_min'] * 60;
    $margen   = (int)$tipo['margen_min'] * 60;
    $minimo   = $ahora + ((int)$tipo['aviso_min_horas'] * 3600);

    // Ocupado: Google Calendar + lo ya reservado en el CRM. Se mira también el
    // margen antes y después de la ventana: una reunión que termina justo a la
    // medianoche anterior también tiene que respetar el colchón.
    // $op: 'cache' (segundos que sirve la última consulta a Google: las páginas
    // públicas y la web, para que nadie pueda martillar el calendario a fuerza
    // de pedir horarios) y 'timeout' (la verificación final de una reserva
    // espera poco: tiene el calendario bloqueado y la web del otro lado).
    // Con caché, la consulta arranca al comienzo del primer día y no en este
    // segundo: así es la misma consulta durante todo el día y la caché sirve.
    // Mirar unas horas de más hacia atrás no cambia los huecos que se ofrecen.
    $cache    = max(0, (int)($op['cache'] ?? 0));
    $consulta = $cache > 0 ? min($tMin, agenda_fecha($fechas[0], $tz)->getTimestamp()) : $tMin;
    $ocupado  = gcal_ocupado($usuario, date('c', $consulta - $margen), date('c', $tMax + $margen), 'primary',
                             (int)($op['timeout'] ?? 25), $cache);
    if ($ocupado === null) {
        $motivo = 'sin_calendario';
        return [];
    }

    if ($ignorarReservaId) {
        $propia = q1('SELECT inicia_el, termina_el FROM agenda_reservas WHERE id = ?', [$ignorarReservaId]);
        if ($propia) {
            $pi = strtotime((string)$propia['inicia_el']);
            $pf = strtotime((string)$propia['termina_el']);
            foreach ($ocupado as $i => [$oi, $of]) {
                if ($oi === $pi && $of === $pf) { unset($ocupado[$i]); break; }
            }
        }
    }

    $sql = "SELECT inicia_el, termina_el FROM agenda_reservas
            WHERE usuario_id = ? AND estado = 'confirmada' AND termina_el > ? AND inicia_el < ?";
    $p   = [$usuario, date('Y-m-d H:i:s', $tMin - $margen), date('Y-m-d H:i:s', $tMax + $margen)];
    if ($ignorarReservaId) { $sql .= ' AND id <> ?'; $p[] = $ignorarReservaId; }
    foreach (q($sql, $p)->fetchAll() as $r) {
        $ocupado[] = [strtotime((string)$r['inicia_el']), strtotime((string)$r['termina_el'])];
    }
    $ocupado = array_values($ocupado);

    $huecos = [];
    foreach ($fechas as $fecha) {
        $dow   = (int) agenda_fecha($fecha, $tz)->format('w');
        $slots = agenda_slots_dia($fecha, $porDia[$dow] ?? [], $duracion, $margen, $minimo, $ocupado, $tz);
        if ($slots) { $huecos[$fecha] = $slots; }
    }
    return $huecos;
}

/**
 * Huecos desde hoy, para las pantallas que ya usaban esta función.
 * Con $dias = 0, toda la ventana del tipo (hasta 31 días).
 */
function agenda_huecos(array $tipo, int $dias = 0, ?string &$motivo = null,
                       ?int $ignorarReservaId = null, array $op = []): array
{
    $hoy   = agenda_local(time(), $tipo, 'Y-m-d');
    $dias  = $dias > 0 ? $dias : (int)$tipo['ventana_dias'];
    $hasta = (new DateTimeImmutable($hoy, agenda_zona($tipo)))->modify('+' . $dias . ' days')->format('Y-m-d');
    return agenda_huecos_rango($tipo, $hoy, $hasta, $motivo, $ignorarReservaId, $op);
}

/**
 * ¿Ese inicio es un hueco válido y libre AHORA (Google + CRM + margen)?
 *
 * El inicio tiene que ser uno de los que la propia agenda ofrece, calculado en
 * este momento. Controlar sólo el choque con las reservas del CRM dejaría
 * pasar cualquier inicio que llegue por POST: un domingo a las 3 de la mañana,
 * o encima de una reunión que el vendedor tiene en Google.
 *
 * @param string|null $motivo horario_invalido | horario_tomado | sin_calendario
 */
function agenda_hueco_libre(array $tipo, int $inicio, ?int $ignorarReservaId = null,
                            ?string &$motivo = null): bool
{
    $motivo = null;
    $tz     = agenda_zona($tipo);
    $fecha  = (new DateTimeImmutable('@' . $inicio))->setTimezone($tz)->format('Y-m-d');

    // 0) Un feriado no tiene turnos, aunque el horario laboral diga que sí.
    if (feriado_del_dia($fecha)) { $motivo = 'feriado'; return false; }

    // 1) ¿Es un horario que el tipo ofrece, sin mirar lo ocupado?
    [, , $fechas] = agenda_ventana($tipo, $fecha, $fecha, time());
    $dow  = (int) agenda_fecha($fecha, $tz)->format('w');
    $base = $fechas
        ? agenda_slots_dia($fecha, agenda_franjas($tipo)[$dow] ?? [], (int)$tipo['duracion_min'] * 60, 0,
                           time() + ((int)$tipo['aviso_min_horas'] * 3600), [], $tz)
        : [];
    if (!in_array($inicio, $base, true)) { $motivo = 'horario_invalido'; return false; }

    // 2) ¿Sigue libre? Contra Google en este momento, sin caché, y con poco
    //    tiempo de espera: esto corre con el calendario del vendedor bloqueado
    //    y la web espera la respuesta diez segundos. Si Google tarda más, la
    //    reserva falla limpia (sin_calendario) antes de que la web corte, en
    //    vez de quedar tomada sin que el visitante lo sepa.
    $m = null;
    $huecos = agenda_huecos_rango($tipo, $fecha, $fecha, $m, $ignorarReservaId,
                                  ['timeout' => AGENDA_TIMEOUT_VERIFICACION]);
    if ($m === 'sin_calendario') { $motivo = 'sin_calendario'; return false; }
    if (!in_array($inicio, $huecos[$fecha] ?? [], true)) { $motivo = 'horario_tomado'; return false; }
    return true;
}

/** La excepción que corresponde a un motivo de agenda_hueco_libre(). */
function agenda_excepcion_hueco(?string $motivo): AgendaExcepcion
{
    return match ($motivo) {
        'sin_calendario'   => new AgendaExcepcion('No pudimos revisar la agenda en este momento. '
                                . 'Probá de nuevo en unos minutos.', 'sin_calendario', 'inicio'),
        'horario_invalido' => new AgendaExcepcion('Ese horario no está disponible para reservar. '
                                . 'Elegí uno de la lista, por favor.', 'horario_invalido', 'inicio'),
        'feriado'          => new AgendaExcepcion('Ese día es feriado y no tomamos reuniones. '
                                . 'Elegí otro día, por favor.', 'feriado', 'inicio'),
        default            => new AgendaExcepcion('Justo tomaron ese horario. Elegí otro, por favor.',
                                'horario_tomado', 'inicio'),
    };
}

/** El feriado del día en que empezaría la reunión (en la zona del tipo), o null. */
function agenda_feriado_de_inicio(array $tipo, int $inicio): ?array
{
    $fecha = agenda_local($inicio, $tipo, 'Y-m-d');
    $f = feriado_del_dia($fecha);
    return $f ? $f + ['fecha' => $fecha] : null;
}

/**
 * La excepción de una reserva en un feriado, con el nombre del feriado.
 *
 * Sale antes que cualquier otro control del horario: el visitante tiene que
 * leer que ese día es feriado, no que «justo lo tomaron».
 */
function agenda_excepcion_feriado(array $f): AgendaExcepcion
{
    return new AgendaExcepcion(
        'El ' . fecha_larga((string)$f['fecha']) . ' es feriado (' . feriado_etiqueta($f) . ') '
            . 'y ese día no tomamos reuniones. Elegí otro día, por favor.',
        'feriado', 'inicio',
        ['feriado' => ['fecha' => (string)$f['fecha'], 'nombre' => (string)$f['nombre']]]
    );
}

// ------------------------------------------------------------
//  Bloqueo por vendedor
//
//  Entre comprobar que el horario está libre y guardar la reserva no había
//  nada que impidiera que otro pedido hiciera lo mismo en paralelo: dos
//  personas que confirmaban a la vez se quedaban con el mismo horario. El
//  bloqueo es por vendedor (su calendario es lo que se reparte) y el nombre
//  lleva la base porque producción y pruebas comparten el servidor de MariaDB,
//  y los bloqueos con nombre son del servidor, no de cada base.
// ------------------------------------------------------------

function agenda_bloquear(int $usuarioId, int $espera = 10): bool
{
    return (int) qv("SELECT GET_LOCK(CONCAT(DATABASE(), ':agenda:', ?), ?)",
                    [$usuarioId, max(0, $espera)]) === 1;
}

function agenda_liberar(int $usuarioId): void
{
    try {
        q("DO RELEASE_LOCK(CONCAT(DATABASE(), ':agenda:', ?))", [$usuarioId]);
    } catch (\Throwable $e) {
        // Si la conexión se cayó, el bloqueo se liberó con ella.
    }
}

/** ¿Choca con una reserva confirmada del vendedor? (sin margen; para lo que agenda el equipo). */
function agenda_choca(int $usuarioId, int $inicio, int $fin, ?int $ignorarReservaId = null): bool
{
    $sql = "SELECT 1 FROM agenda_reservas
            WHERE usuario_id = ? AND estado = 'confirmada' AND inicia_el < ? AND termina_el > ?";
    $p   = [$usuarioId, date('Y-m-d H:i:s', $fin), date('Y-m-d H:i:s', $inicio)];
    if ($ignorarReservaId) { $sql .= ' AND id <> ?'; $p[] = $ignorarReservaId; }
    return (bool) qv($sql . ' LIMIT 1', $p);
}

/** Abre una transacción si no hay otra abierta. Devuelve si la abrió esta llamada. */
function agenda_tx_abrir(): bool
{
    if (db()->inTransaction()) { return false; }
    db()->beginTransaction();
    return true;
}

function agenda_tx_cerrar(bool $abierta, bool $confirmar): void
{
    if (!$abierta || !db()->inTransaction()) { return; }
    $confirmar ? db()->commit() : db()->rollBack();
}

// ------------------------------------------------------------
//  Reservar
// ------------------------------------------------------------

/** Texto de una línea: sin saltos ni caracteres de control, con los espacios juntos. */
function agenda_una_linea(?string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$s) ?? '') ?? '');
}

/**
 * Parte de base de datos de la reserva.
 *
 * Valida, bloquea el calendario del vendedor, comprueba que el horario siga
 * libre y deja guardados la reserva, el contacto, la empresa y la reunión. NO
 * llama a Google para crear el evento ni manda el correo: eso lo hace
 * agenda_reserva_completar(). Crear el Meet puede tardar varios segundos, y si
 * la web esperaba todo eso, un corte por tiempo dejaba al visitante con un
 * error y el horario tomado igual.
 *
 * $d: inicio (int), nombre, email, empresa, telefono, comentario, servicio, campana_id
 * $o: origen ('web' | 'publica' | 'crm'; por defecto 'publica')
 *     validar_hueco (bool, por defecto true)
 *     consentimiento_en (Y-m-d H:i:s), consentimiento_version
 *     novedades (bool, por defecto false)
 *     descripcion_extra (la línea de origen, para la descripción de la reunión)
 *     entrada_id (la fila de entradas_web, que queda enlazada a lo que se creó)
 *     pagina (desde qué página llegó, para la línea de tiempo del contacto)
 *
 * @return array la fila de agenda_reservas + 'cuenta_id' + 'contacto_nuevo'
 * @throws AgendaExcepcion
 */
function agenda_reservar(array $tipo, array $d, array $o = []): array
{
    $origen       = in_array($o['origen'] ?? '', ['web', 'publica', 'crm'], true) ? (string)$o['origen'] : 'publica';
    $validarHueco = (bool)($o['validar_hueco'] ?? true);
    // Quien atiende ese día: el dueño del tipo o quien lo cubre (vacaciones).
    $tipo         = agenda_tipo_para($tipo, (int)($d['inicio'] ?? 0));
    $usuarioId    = (int)$tipo['usuario_id'];

    $inicio     = (int)($d['inicio'] ?? 0);
    $fin        = $inicio + ((int)$tipo['duracion_min'] * 60);
    $email      = normalizar_email((string)($d['email'] ?? ''));
    $nombre     = agenda_una_linea($d['nombre'] ?? '');
    $empresa    = agenda_una_linea($d['empresa'] ?? '');
    $telefono   = agenda_una_linea($d['telefono'] ?? '');
    $servicio   = agenda_una_linea($d['servicio'] ?? '');
    // Un retorno suelto (\r) también es un fin de línea para algunos
    // calendarios: partiría la invitación .ics en propiedades nuevas.
    $comentario = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '',
                                    str_replace(["\r\n", "\r"], "\n", (string)($d['comentario'] ?? ''))) ?? '');

    // Nombre y empresa vuelven en el correo de confirmación, que sale con la
    // marca de la empresa a la dirección que escribió el visitante: sin
    // direcciones web. Y hasta 120 caracteres, lo que entra en la ficha del
    // contacto.
    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120 || !preg_match('/\p{L}/u', $nombre)
        || !texto_sin_enlaces($nombre)) {
        throw new AgendaExcepcion('Escribí tu nombre y apellido.', 'datos_invalidos', 'nombre');
    }
    if (!email_valido($email) || strlen($email) > 190) {
        throw new AgendaExcepcion('El correo no es válido.', 'datos_invalidos', 'email');
    }
    if (mb_strlen($empresa) > 200) {
        throw new AgendaExcepcion('El nombre de la empresa es demasiado largo.', 'datos_invalidos', 'empresa');
    }
    if (!texto_sin_enlaces($empresa)) {
        throw new AgendaExcepcion('Escribí sólo el nombre de la empresa.', 'datos_invalidos', 'empresa');
    }
    // El teléfono lo escribe alguien de afuera de la empresa, así que hay que
    // comprobarlo del lado del servidor: `type="tel"` en el formulario no
    // valida nada, es sólo una pista para el teclado del celular. Sin esto se
    // podían mandar letras y quedaban guardadas como número de contacto.
    if (mb_strlen($telefono) > 60 || !telefono_valido($telefono !== '' ? $telefono : null)) {
        throw new AgendaExcepcion('El teléfono no parece un número. Poné sólo números; '
            . 'se aceptan también + ( ) - . y espacios.', 'datos_invalidos', 'telefono');
    }
    if (mb_strlen($servicio) > 120) {
        throw new AgendaExcepcion('El servicio elegido es demasiado largo.', 'datos_invalidos', 'servicio');
    }
    if (mb_strlen($comentario) > 3000) {
        throw new AgendaExcepcion('El comentario es demasiado largo: hasta 3000 caracteres.', 'datos_invalidos', 'comentario');
    }
    if ($inicio <= time()) {
        throw new AgendaExcepcion('Ese horario ya pasó.', 'horario_invalido', 'inicio');
    }
    // En un feriado no se reserva por ningún camino, tampoco desde el CRM. Se
    // controla antes de bloquear el calendario: si hiciera falta actualizar los
    // feriados, la consulta no tiene que demorar a nadie más.
    if ($feriado = agenda_feriado_de_inicio($tipo, $inicio)) {
        throw agenda_excepcion_feriado($feriado);
    }

    // Lo que se guarda como comentario de la reserva: el servicio que eligió
    // y lo que escribió. Es lo que el vendedor lee antes de la reunión.
    $comentarioReserva = trim(($servicio !== '' ? 'Servicio: ' . $servicio . "\n\n" : '') . $comentario);

    if (!agenda_bloquear($usuarioId)) {
        throw new AgendaExcepcion('Hay otra reserva en curso en este momento. Probá de nuevo en unos segundos.', 'ocupado');
    }
    try {
        // Verificación final contra doble reserva, ya con el calendario del
        // vendedor bloqueado: entre que el visitante eligió el horario y apretó
        // «confirmar», otro pudo haberlo tomado, o el vendedor pudo haber
        // agendado algo en Google.
        if ($validarHueco) {
            $m = null;
            if (!agenda_hueco_libre($tipo, $inicio, null, $m)) {
                throw agenda_excepcion_hueco($m);
            }
        } elseif (agenda_choca($usuarioId, $inicio, $fin)) {
            throw agenda_excepcion_hueco('horario_tomado');
        }
        // Después del horario: si lo tomaron, eso es lo que hay que decirle.
        if ($origen !== 'crm') {
            agenda_controlar_topes($usuarioId, $email);
        }

        $abierta = agenda_tx_abrir();
        try {
            $token = token32();
            q('INSERT INTO agenda_reservas (tipo_id, usuario_id, token, inicia_el, termina_el,
                                            invitado_nombre, invitado_email, invitado_empresa, invitado_telefono,
                                            comentario, campana_id, origen, consentimiento_en, consentimiento_version)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                (int)$tipo['id'], $usuarioId, $token,
                date('Y-m-d H:i:s', $inicio), date('Y-m-d H:i:s', $fin),
                $nombre, $email, $empresa ?: null, $telefono ?: null,
                $comentarioReserva ?: null, ((int)($d['campana_id'] ?? 0)) ?: null,
                $origen,
                ($o['consentimiento_en'] ?? null) ?: null,
                ($o['consentimiento_version'] ?? null) ?: null,
            ]);
            $reservaId = last_id();
            // El identificador propio de la invitación. Si después Google crea el
            // evento, se reemplaza por el suyo antes de mandar el primer correo.
            q('UPDATE agenda_reservas SET ics_uid = ? WHERE id = ?',
              [agenda_ics_uid_propio($reservaId, $token), $reservaId]);

            // Empresa y contacto, sin duplicar ni pisar nada, con el criterio de lo que entra por la web
            // (crm.php): el correo se busca en los tres campos de correo, la
            // empresa por su nombre sin forma societaria o por el dominio.
            $cuentaId = $empresa !== '' ? cuenta_asegurar_web($empresa, $email, $usuarioId) : null;
            [$contactoId, $contactoNuevo] = contacto_asegurar_web([
                'nombre'    => $nombre,
                'email'     => $email,
                'telefono'  => $telefono,
                'novedades' => !empty($o['novedades']),
                'pagina'    => ($o['pagina'] ?? null) ?: null,
            ], $usuarioId, $cuentaId);
            // Sin empresa escrita, la reunión queda con la del contacto si ya la tenía.
            if (!$cuentaId) {
                $cuentaId = ((int) qv('SELECT cuenta_id FROM contactos WHERE id = ?', [$contactoId])) ?: null;
            }

            // La reunión, dentro del CRM, va también con la empresa: con el
            // contacto solo no aparecería en la ficha de la empresa.
            $descripcion = trim(
                ($servicio !== '' ? 'Servicio: ' . $servicio . "\n" : '')
                . ($comentario !== '' ? "\n" . $comentario . "\n" : '')
                . (!empty($o['descripcion_extra']) ? "\n" . agenda_una_linea((string)$o['descripcion_extra']) : '')
            );
            $actividadId = actividad_guardar([
                'tipo'           => 'reunion',
                'asunto'         => recortar($tipo['nombre'] . ' con ' . $nombre . ($empresa !== '' ? ' (' . $empresa . ')' : ''), 200),
                'propietario_id' => $usuarioId,
                'contacto_id'    => $contactoId,
                'cuenta_id'      => $cuentaId,
                'inicia_el'      => date('Y-m-d H:i:s', $inicio),
                'termina_el'     => date('Y-m-d H:i:s', $fin),
                'estado'         => 'No iniciada',
                'lugar'          => !empty($tipo['con_meet']) ? 'Google Meet' : 'A confirmar',
                'descripcion'    => $descripcion,
            ]);
            q('INSERT INTO actividad_invitados (actividad_id, email, nombre) VALUES (?, ?, ?)',
              [$actividadId, $email, $nombre]);
            q('UPDATE agenda_reservas SET contacto_id = ?, actividad_id = ? WHERE id = ?',
              [$contactoId, $actividadId, $reservaId]);

            // Si la reserva vino de una campaña, cuenta como resultado de esa campaña.
            if (!empty($d['campana_id'])) {
                q('UPDATE campanas SET reuniones = reuniones + 1 WHERE id = ?', [(int)$d['campana_id']]);
            }

            // La entrada de la web queda apuntando a lo que se creó, en la misma
            // transacción: si algo falla no queda una entrada «ok» sin reunión.
            if (!empty($o['entrada_id']) && function_exists('entrada_web_actualizar')) {
                entrada_web_actualizar((int)$o['entrada_id'], [
                    'propietario_id' => $usuarioId,
                    'contacto_id'    => $contactoId,
                    'cuenta_id'      => $cuentaId,
                    'actividad_id'   => $actividadId,
                    'reserva_id'     => $reservaId,
                ]);
            }
            agenda_tx_cerrar($abierta, true);
        } catch (\Throwable $e) {
            agenda_tx_cerrar($abierta, false);
            throw $e;
        }
    } finally {
        agenda_liberar($usuarioId);
    }

    log_app('agenda.reserva', ['tipo' => 'sistema', 'entidad' => 'reserva', 'entidad_id' => $reservaId,
        'detalle' => $tipo['nombre'] . ' el ' . fecha_hora(date('Y-m-d H:i:s', $inicio))
                   . ' · ' . ['web' => 'desde la web', 'publica' => 'desde la agenda pública',
                              'crm' => 'desde el CRM'][$origen]]);

    $reserva = q1('SELECT * FROM agenda_reservas WHERE id = ?', [$reservaId]) ?? [];
    return $reserva + ['cuenta_id' => $cuentaId, 'contacto_nuevo' => $contactoNuevo];
}

/**
 * Topes de lo que entra de afuera (la web y la agenda pública) que no
 * dependen de la IP: con un /64 de IPv6 o rotando direcciones, el límite por
 * IP no alcanza para frenar a un programa que quiere tomar todos los horarios.
 *
 *   · por correo: como máximo `agenda_max_por_correo` reuniones futuras
 *     confirmadas (por defecto 2). Se compara sin la parte «+algo» de la
 *     dirección, que llega al mismo buzón;
 *   · por vendedor: como máximo `agenda_tope_diario` reservas de afuera por
 *     día (por defecto 15). Al llegar se avisa al vendedor, y la web pasa al
 *     formulario de siempre hasta el día siguiente.
 *
 * Corre con el calendario del vendedor bloqueado, así que dos pedidos en
 * paralelo no pasan los dos el mismo tope.
 *
 * @throws AgendaExcepcion motivo 'limite'
 */
function agenda_controlar_topes(int $usuarioId, string $email): void
{
    $maxCorreo = max(1, (int) setting_get('agenda_max_por_correo', '2'));
    [$local, $dominio] = array_pad(explode('@', $email, 2), 2, '');
    $base = explode('+', $local, 2)[0];
    $comodin = static fn(string $s): string => addcslashes($s, '%_\\');
    $futuras = (int) qv("SELECT COUNT(*) FROM agenda_reservas
                         WHERE estado = 'confirmada' AND inicia_el > NOW() AND origen IN ('web', 'publica')
                           AND (invitado_email = ? OR invitado_email LIKE ?)",
                        [$base . '@' . $dominio, $comodin($base) . '+%@' . $comodin($dominio)]);
    if ($futuras >= $maxCorreo) {
        throw new AgendaExcepcion('Ya tenés reuniones agendadas con nosotros. Si necesitás otro horario, '
            . 'cambialo desde el enlace del correo de confirmación.', 'limite', 'email');
    }

    $tope = max(1, (int) setting_get('agenda_tope_diario', '15'));
    $hoy  = (int) qv("SELECT COUNT(*) FROM agenda_reservas
                      WHERE usuario_id = ? AND origen IN ('web', 'publica') AND creado_en >= CURDATE()",
                     [$usuarioId]);
    if ($hoy >= $tope) {
        // Una alerta por día, no una por cada intento.
        if (!limite_superado('agenda:tope:' . $usuarioId, 1, 86400)) {
            notificar('sistema', 'La agenda llegó al tope de reservas del día',
                ['usuario_id' => $usuarioId,
                 'detalle'    => 'Entraron ' . $hoy . ' reservas desde afuera hoy y la agenda dejó de tomar más '
                               . 'hasta mañana; la web ofrece el formulario. Si son reales, subí el tope '
                               . '(ajuste agenda_tope_diario).',
                 'url'        => 'reservas']);
        }
        throw new AgendaExcepcion('En este momento no podemos tomar más reservas por acá. '
            . 'Escribinos y coordinamos la reunión.', 'limite');
    }
}

/** El identificador de invitación propio, el que se usaba siempre: se mantiene para las reservas viejas. */
function agenda_ics_uid_propio(int $reservaId, string $token): string
{
    return 'reserva-' . $reservaId . '-' . $token . '@intellihelp.tech';
}

/**
 * Segunda parte de la reserva: evento de Google con Meet, correo de
 * confirmación con la invitación y alerta al vendedor.
 *
 * Se puede llamar las veces que haga falta: si ya tiene evento no crea otro, y
 * si ya se avisó no manda otro correo. Nunca lanza: lo que falla queda en la
 * auditoría y la reserva sigue tomada. El cron completa lo que haya quedado a
 * medias (agenda_completar_pendientes).
 *
 * @return array la reserva actualizada
 */
function agenda_reserva_completar(int $reservaId): array
{
    try {
        $r = q1('SELECT * FROM agenda_reservas WHERE id = ?', [$reservaId]);
        if (!$r || $r['estado'] !== 'confirmada') { return $r ?? []; }
        $tipo = agenda_tipo_de_reserva($r);
        if (!$tipo) { return $r; }

        // Si la está completando otro proceso (la respuesta de la web y el
        // cron, a la vez), no se crea un segundo evento.
        if ((int) qv("SELECT GET_LOCK(CONCAT(DATABASE(), ':agenda-completar:', ?), 0)", [$reservaId]) !== 1) {
            return $r;
        }
    } catch (\Throwable $e) {
        log_error('agenda.completar', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
        return [];
    }

    try {
        $r = q1('SELECT * FROM agenda_reservas WHERE id = ?', [$reservaId]) ?? $r;
        $primeraVez = empty($r['gcal_event_id']) && empty($r['avisada_en']);
        $usuarioId  = (int)$r['usuario_id'];

        // Google Calendar + Meet. Si falla, la reserva NO se pierde: queda igual y
        // el vendedor la ve en el CRM con el aviso de que no se creó el evento.
        if (empty($r['gcal_event_id'])) {
            try {
                $invitados = [(string)$tipo['usuario_email']];
                // El cliente es invitado del evento para poder entrar a la Meet
                // sin pedir permiso. Google no le avisa nada (avisar = none):
                // la invitación le llega una sola vez, la del CRM.
                if ((string) setting_get('agenda_invitado_en_google', '1') !== '0') {
                    array_unshift($invitados, (string)$r['invitado_email']);
                }
                [$eventId, $meet, $icalUid] = gcal_crear_evento($usuarioId, [
                    'inicio'      => strtotime((string)$r['inicia_el']),
                    'fin'         => strtotime((string)$r['termina_el']),
                    'titulo'      => $tipo['nombre'] . ' · ' . $r['invitado_nombre']
                                   . (!empty($r['invitado_empresa']) ? ' (' . $r['invitado_empresa'] . ')' : ''),
                    // Ni el comentario, ni el teléfono, ni de dónde llegó: el
                    // evento lo ven todos los invitados. Eso queda en el CRM.
                    'descripcion' => 'Reunión agendada desde intellihelp.tech.',
                    'invitados'   => array_values(array_unique($invitados)),
                    'con_meet'    => (bool)$tipo['con_meet'],
                    'zona'        => agenda_zona($tipo)->getName(),
                    'avisar'      => 'none',
                    'privado'     => ['crm_reserva_id' => $reservaId],
                ]);
                // El identificador de la invitación pasa a ser el de Google sólo si
                // todavía no salió ningún correo con el propio: si no, el cliente
                // quedaría con dos invitaciones distintas para la misma reunión.
                $usarUid = $icalUid !== '' && empty($r['avisada_en']) && empty($r['reprogramada_de']);
                q('UPDATE agenda_reservas SET gcal_event_id = ?, meet_url = ?, ics_uid = ? WHERE id = ?',
                  [$eventId ?: null, $meet ?: null, $usarUid ? $icalUid : $r['ics_uid'], $reservaId]);
                if (!empty($r['actividad_id'])) {
                    q('UPDATE actividades SET gcal_event_id = ?, meet_url = ?, lugar = ? WHERE id = ?',
                      [$eventId ?: null, $meet ?: null, $meet !== '' ? 'Google Meet' : 'A confirmar',
                       (int)$r['actividad_id']]);
                }
            } catch (\Throwable $e) {
                log_app('agenda.gcal_error', ['tipo' => 'error', 'entidad' => 'reserva', 'entidad_id' => $reservaId,
                    'detalle' => $e->getMessage()]);
            }
            $r = q1('SELECT * FROM agenda_reservas WHERE id = ?', [$reservaId]) ?? $r;
        }

        // Del lado nuestro también hay que enterarse: al cliente le llega su
        // confirmación, pero si nadie mira la pantalla de Reuniones la reunión
        // aparece recién el día que toca. Una sola alerta por reserva, aunque
        // el cron la complete en otra vuelta.
        if ($primeraVez && !qv("SELECT 1 FROM notificaciones WHERE tipo = 'reunion' AND entidad = 'reserva'
                                AND entidad_id = ? LIMIT 1", [$reservaId])) {
            notificar('reunion',
                $r['invitado_nombre'] . ' agendó una reunión',
                ['usuario_id' => $usuarioId,
                 'detalle'    => $tipo['nombre'] . ' · ' . fecha_hora((string)$r['inicia_el'])
                               . (!empty($r['invitado_empresa']) ? ' · ' . $r['invitado_empresa'] : '')
                               . (($r['origen'] ?? '') === 'web' ? ' · desde la web' : '')
                               . (empty($r['gcal_event_id']) ? ' · SIN evento en el calendario' : ''),
                 'entidad'    => 'reserva', 'entidad_id' => $reservaId,
                 'url'        => 'reservas']);
        }

        if (empty($r['avisada_en']) && agenda_avisar($r, $tipo, 'confirmacion')) {
            q('UPDATE agenda_reservas SET avisada_en = NOW() WHERE id = ?', [$reservaId]);
        }

        if ($primeraVez) {
            log_app('agenda.reserva_completada', ['tipo' => 'sistema', 'entidad' => 'reserva', 'entidad_id' => $reservaId,
                'detalle' => (!empty($r['meet_url']) ? 'Meet creado' : (!empty($r['gcal_event_id']) ? 'Evento sin Meet' : 'Sin evento'))
                           . ' · ' . (smtp_configurado() ? 'confirmación por correo' : 'sin correo configurado')]);
        }
    } catch (\Throwable $e) {
        log_error('agenda.completar', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    } finally {
        try {
            q("DO RELEASE_LOCK(CONCAT(DATABASE(), ':agenda-completar:', ?))", [$reservaId]);
        } catch (\Throwable $e) {
        }
    }
    return q1('SELECT * FROM agenda_reservas WHERE id = ?', [$reservaId]) ?? [];
}

/**
 * Cron: completa las reservas confirmadas que quedaron sin avisar (el proceso
 * se cortó después de responder, o el correo falló). Sólo las de las últimas
 * 24 h y por venir: un correo de confirmación que llega después de la reunión
 * confunde más de lo que ayuda.
 *
 * @return int cuántas quedaron avisadas en esta vuelta
 */
function agenda_completar_pendientes(): int
{
    if (!agenda_esquema_listo()) { return 0; }
    $ids = q("SELECT id FROM agenda_reservas
              WHERE estado = 'confirmada' AND avisada_en IS NULL AND origen IS NOT NULL
                AND creado_en < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                AND creado_en > DATE_SUB(NOW(), INTERVAL 1 DAY)
                AND inicia_el > NOW()
              ORDER BY id LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($ids as $id) {
        $r = agenda_reserva_completar((int)$id);
        if (!empty($r['avisada_en'])) { $n++; }
    }
    return $n;
}

/**
 * ¿Ya corrió la migración 008 en esta base? El código nuevo usa columnas que
 * agrega esa migración; si se subió antes de migrar, las páginas públicas
 * dicen «no disponible» en vez de romperse con un error.
 */
function agenda_esquema_listo(): bool
{
    static $listo = null;
    if ($listo === null) {
        try {
            $listo = (bool) q("SHOW COLUMNS FROM agenda_reservas LIKE 'avisada_en'")->fetch();
        } catch (\Throwable $e) {
            $listo = false;
        }
    }
    return $listo;
}

/**
 * La reserva vigente a partir de cualquier token de la cadena de cambios de
 * horario.
 *
 * Cada cambio de horario crea una reserva nueva con otro token. El enlace de
 * cancelar del primer correo apuntaba a la reserva vieja, ya «reprogramada», y
 * dejaba de servir: el cliente que lo usaba veía que no había nada para
 * cancelar y la reunión seguía en pie.
 */
function agenda_reserva_vigente(string $token): ?array
{
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
    if ($token === '') { return null; }
    $r = q1('SELECT * FROM agenda_reservas WHERE token = ?', [$token]);
    for ($i = 0; $r && $r['estado'] === 'reprogramada' && $i < 50; $i++) {
        $siguiente = q1('SELECT * FROM agenda_reservas WHERE reprogramada_de = ? ORDER BY id DESC LIMIT 1',
                        [(int)$r['id']]);
        if (!$siguiente) { break; }
        $r = $siguiente;
    }
    return $r;
}

/**
 * Mueve una reunión a otro horario.
 *
 * La reserva vieja queda como «reprogramada» y se crea una nueva «confirmada»
 * con el horario nuevo. Se hace así, y no pisando la fila, por dos motivos: la
 * disponibilidad se calcula sobre las confirmadas, así que el hueco viejo se
 * libera solo y el nuevo se ocupa; y queda el historial de que esa reunión se
 * movió. La nueva apunta a la vieja (`reprogramada_de`) y lleva la MISMA
 * invitación (`ics_uid`) con la secuencia siguiente: con otro identificador,
 * el cliente quedaría con las dos reuniones en el calendario.
 *
 * El evento de Google es el mismo: se mueve, no se borra y se vuelve a crear,
 * así la reunión no desaparece del calendario de quien ya la había aceptado.
 *
 * $validarHueco = true para el invitado (/r/mover): sólo puede elegir uno de
 * los horarios que ofrece la agenda. El equipo puede moverla a cualquier hora.
 *
 * @return array la reserva nueva
 * @throws AgendaExcepcion
 */
function agenda_reprogramar(string $token, int $nuevoInicio, bool $validarHueco = false): array
{
    $r = agenda_reserva_vigente($token);
    if (!$r || $r['estado'] !== 'confirmada') {
        throw new AgendaExcepcion('Esa reunión ya no está activa.', 'datos_invalidos');
    }
    if ($nuevoInicio <= time()) {
        throw new AgendaExcepcion('Ese horario ya pasó.', 'horario_invalido', 'inicio');
    }
    if ($nuevoInicio === strtotime((string)$r['inicia_el'])) {
        throw new AgendaExcepcion('La reunión ya está en ese horario.', 'horario_invalido', 'inicio');
    }

    $tipo = agenda_tipo_de_reserva($r);
    if (!$tipo) { throw new AgendaExcepcion('El tipo de reunión ya no existe.', 'datos_invalidos'); }
    // Tampoco se mueve a un feriado, ni el invitado ni el equipo.
    if ($feriado = agenda_feriado_de_inicio($tipo, $nuevoInicio)) {
        throw agenda_excepcion_feriado($feriado);
    }
    $fin       = $nuevoInicio + ((int)$tipo['duracion_min'] * 60);
    $usuarioId = (int)$r['usuario_id'];

    if (!agenda_bloquear($usuarioId)) {
        throw new AgendaExcepcion('Hay otra reserva en curso en este momento. Probá de nuevo en unos segundos.', 'ocupado');
    }
    try {
        // Que no se pise con otra reunión (sin contar la que se mueve).
        if ($validarHueco) {
            $m = null;
            if (!agenda_hueco_libre($tipo, $nuevoInicio, (int)$r['id'], $m)) {
                throw agenda_excepcion_hueco($m);
            }
        } elseif (agenda_choca($usuarioId, $nuevoInicio, $fin, (int)$r['id'])) {
            throw new AgendaExcepcion('Ya hay otra reunión en ese horario.', 'horario_tomado', 'inicio');
        }

        $secGoogle = null;
        if (!empty($r['gcal_event_id'])) {
            try {
                if (!gcal_mover_evento($usuarioId, (string)$r['gcal_event_id'], $nuevoInicio, $fin,
                                       'primary', 'none', $secGoogle, agenda_zona($tipo)->getName())) {
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

        // La secuencia tiene que superar a la anterior y a la que tiene el
        // evento en Google; si no, el calendario del cliente descarta la
        // actualización por vieja.
        $secuencia = max((int)($r['ics_secuencia'] ?? 0) + 1, (int)$secGoogle);
        $uid       = (string)(($r['ics_uid'] ?? '') ?: agenda_ics_uid_propio((int)$r['id'], (string)$r['token']));

        $abierta = agenda_tx_abrir();
        try {
            q("UPDATE agenda_reservas SET estado = 'reprogramada' WHERE id = ?", [(int)$r['id']]);
            q('INSERT INTO agenda_reservas (tipo_id, usuario_id, token, inicia_el, termina_el,
                                            invitado_nombre, invitado_email, invitado_empresa, invitado_telefono,
                                            comentario, campana_id, contacto_id, actividad_id, gcal_event_id, meet_url,
                                            ics_uid, ics_secuencia, reprogramada_de, origen,
                                            consentimiento_en, consentimiento_version)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                (int)$r['tipo_id'], $usuarioId, token32(),
                date('Y-m-d H:i:s', $nuevoInicio), date('Y-m-d H:i:s', $fin),
                $r['invitado_nombre'], $r['invitado_email'], $r['invitado_empresa'], $r['invitado_telefono'],
                $r['comentario'], $r['campana_id'], $r['contacto_id'], $r['actividad_id'],
                $r['gcal_event_id'], $r['meet_url'],
                $uid, $secuencia, (int)$r['id'], ($r['origen'] ?? null) ?: 'publica',
                $r['consentimiento_en'] ?? null, $r['consentimiento_version'] ?? null,
            ]);
            $nuevaId = last_id();
            if (!empty($r['actividad_id'])) {
                q('UPDATE actividades SET inicia_el = ?, termina_el = ? WHERE id = ?',
                  [date('Y-m-d H:i:s', $nuevoInicio), date('Y-m-d H:i:s', $fin), (int)$r['actividad_id']]);
            }
            agenda_tx_cerrar($abierta, true);
        } catch (\Throwable $e) {
            agenda_tx_cerrar($abierta, false);
            throw $e;
        }
    } finally {
        agenda_liberar($usuarioId);
    }

    $nueva = q1('SELECT * FROM agenda_reservas WHERE id = ?', [$nuevaId]) ?? [];
    log_app('agenda.reprogramada', ['tipo' => 'accion', 'entidad' => 'reserva',
        'entidad_id' => $nuevaId,
        'detalle' => $r['invitado_nombre'] . ': de ' . fecha_hora((string)$r['inicia_el'])
                   . ' a ' . fecha_hora(date('Y-m-d H:i:s', $nuevoInicio))]);

    // La invitación actualizada: mismo identificador, secuencia nueva. Si el
    // correo falla, el cron la reintenta (avisada_en sigue vacío).
    if (agenda_avisar($nueva, $tipo, 'confirmacion')) {
        q('UPDATE agenda_reservas SET avisada_en = NOW() WHERE id = ?', [$nuevaId]);
        $nueva['avisada_en'] = date('Y-m-d H:i:s');
    }
    return $nueva;
}

/** Cancela una reserva (desde el enlace del correo, aunque sea de un correo viejo, o desde el CRM). */
function agenda_cancelar(string $token, string $motivo = ''): bool
{
    $r = agenda_reserva_vigente($token);
    if (!$r || $r['estado'] !== 'confirmada') { return false; }
    $usuarioId = (int)$r['usuario_id'];

    $secGoogle = null;
    if (!empty($r['gcal_event_id'])) {
        // El fallo de Google no se puede tragar en silencio: la reunión
        // quedaría cancelada en el CRM y viva en el calendario del vendedor,
        // que la seguiría viendo ocupada. Se registra para sacarla a mano.
        try {
            $secGoogle = gcal_secuencia_evento($usuarioId, (string)$r['gcal_event_id']);
            if (!gcal_borrar_evento($usuarioId, (string)$r['gcal_event_id'], 'primary', 'none')) {
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

    // La cancelación es una versión más de la misma invitación: secuencia + 1.
    $secuencia = max((int)($r['ics_secuencia'] ?? 0) + 1, $secGoogle !== null ? $secGoogle + 1 : 0);
    q("UPDATE agenda_reservas SET estado = 'cancelada', ics_secuencia = ? WHERE id = ?", [$secuencia, (int)$r['id']]);
    if (!empty($r['actividad_id'])) {
        q('UPDATE actividades SET eliminado_en = NOW() WHERE id = ?', [(int)$r['actividad_id']]);
    }
    log_app('agenda.cancelacion', ['tipo' => 'sistema', 'entidad' => 'reserva', 'entidad_id' => (int)$r['id'],
        'detalle' => 'Reunión cancelada' . ($motivo !== '' ? ': ' . recortar($motivo, 200) : '')]);

    // Aviso de la baja con la invitación de cancelación: sin esto, a quien ya
    // la había aceptado le quedaría la reunión en el calendario.
    $tipo = agenda_tipo_de_reserva($r);
    if ($tipo) {
        $r['estado'] = 'cancelada';
        $r['ics_secuencia'] = $secuencia;
        agenda_avisar($r, $tipo, 'cancelacion');
    }
    return true;
}

// ------------------------------------------------------------
//  Páginas públicas: token de formulario y límite de envíos
// ------------------------------------------------------------

/**
 * Token de formulario firmado: tipo de reunión + marca de tiempo + un número
 * al azar + HMAC.
 *
 * Las páginas públicas no tienen sesión, así que no hay token CSRF de los de
 * siempre. Este cumple esa función y además mide el tiempo: un formulario
 * que se manda a los dos segundos de abrir la página no lo llenó una persona.
 * El número al azar es para que cada token sirva una sola vez
 * (agenda_form_token_valido() con $unaVez): antes, con uno solo se podían
 * mandar todas las reservas que se quisiera durante dos horas.
 */
function agenda_form_token(int $tipoId, string $uso = 'reservar'): string
{
    $ts    = (string) time();
    $nonce = bin2hex(random_bytes(8));
    return $tipoId . '.' . $ts . '.' . $nonce . '.'
         . hash_hmac('sha256', 'agenda-form|' . $uso . '|' . $tipoId . '|' . $ts . '|' . $nonce, crm_secret());
}

/**
 * Válido entre 3 segundos y 2 horas después de generado.
 *
 * Con $unaVez, además, queda registrado como usado y la segunda vez se
 * rechaza ('usado'). Las páginas lo piden así; si se rechaza por cualquier
 * motivo, la página vuelve a dibujar el formulario con un token nuevo. Si el
 * registro no está disponible no se frena a nadie: siguen valiendo la firma,
 * el tiempo y el límite de envíos.
 *
 * @param string|null $motivo rapido | vencido | invalido | usado
 */
function agenda_form_token_valido(string $token, int $tipoId, ?string &$motivo = null, string $uso = 'reservar',
                                  bool $unaVez = false): bool
{
    $motivo = null;
    if (!preg_match('/^(\d{1,10})\.(\d{1,12})\.([a-f0-9]{16})\.([a-f0-9]{64})$/', $token, $m) || (int)$m[1] !== $tipoId) {
        $motivo = 'invalido';
        return false;
    }
    $esperada = hash_hmac('sha256', 'agenda-form|' . $uso . '|' . $tipoId . '|' . $m[2] . '|' . $m[3], crm_secret());
    if (!hash_equals($esperada, $m[4])) { $motivo = 'invalido'; return false; }

    $edad = time() - (int)$m[2];
    if ($edad < -60)   { $motivo = 'invalido'; return false; }
    if ($edad < 3)     { $motivo = 'rapido';   return false; }
    if ($edad > 7200)  { $motivo = 'vencido';  return false; }

    if ($unaVez) {
        try {
            $nuevo = q('INSERT IGNORE INTO api_limites (clave, ventana, conteo, vence_en) VALUES (?, 0, 1, ?)',
                       ['ft:' . $m[3], date('Y-m-d H:i:s', (int)$m[2] + 7200 + 60)])->rowCount() === 1;
            if (!$nuevo) { $motivo = 'usado'; return false; }
        } catch (\Throwable $e) {
            // Sin la tabla, sin registro: no se frena a nadie.
        }
    }
    return true;
}

/**
 * Hash de la IP del visitante para contar sus envíos: la IP que vio el
 * servidor (no la que dicen las cabeceras) y, en IPv6, su red /64.
 */
function agenda_ip_visitante(): ?string
{
    return ip_hash(ip_red(ip_confiable()));
}

/**
 * ¿Esta IP ya hizo demasiados envíos en las páginas públicas? Tres por hora y
 * seis por día entre reservar, mover y cancelar. Se cuenta por el hash de la
 * IP, nunca por la IP. Si el contador no está disponible no se frena a nadie:
 * es una protección, no un requisito para reservar.
 */
function agenda_limite_publico(): bool
{
    try {
        $h = agenda_ip_visitante();
        if (!$h) { return false; }
        $hora = limite_superado('ip:' . $h . ':r:3600', 3, 3600);
        $dia  = limite_superado('ip:' . $h . ':r:86400', 6, 86400);
        return $hora || $dia;
    } catch (\Throwable $e) {
        log_error('agenda.limite', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
        return false;
    }
}

/**
 * ¿Esta IP ya abrió demasiadas veces las páginas públicas de la agenda? 30
 * cada 10 minutos: de sobra para una persona que elige horario, y un freno
 * para quien las martilla para gastar la cuota de Google del vendedor.
 */
function agenda_limite_vistas(): bool
{
    try {
        $h = agenda_ip_visitante();
        return $h ? limite_superado('ip:' . $h . ':ver:600', 30, 600) : false;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Actualiza la entrada de la web de una reserva pública sin frenar nada si falla. */
function agenda_entrada_marcar(int $entradaId, array $campos): void
{
    if ($entradaId <= 0) { return; }
    try {
        entrada_web_actualizar($entradaId, $campos);
    } catch (\Throwable $e) {
        log_error('agenda.entrada', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
}

/** Versión y texto del consentimiento de la agenda pública (Ley 25.326). */
const AGENDA_CONSENTIMIENTO_VERSION = 'r-2026-11';
const AGENDA_CONSENTIMIENTO_TEXTO   = 'Acepto que Intellihelp use estos datos para coordinar la reunión '
                                    . 'y responder mi consulta, según el aviso de privacidad.';

/** Dónde está el aviso de privacidad que se enlaza desde la casilla. */
function agenda_privacidad_url(): string
{
    return (string) setting_get('privacidad_url', 'https://intellihelp.tech/privacidad/');
}

/**
 * Datos y dibujo de la página pública de reserva. La usan /r/<usuario>/<tipo>
 * y la confirmación cuando hay que volver a mostrar el formulario con un
 * aviso (el horario ya lo tomaron, faltó la casilla, un dato mal escrito).
 *
 * @param array $extra error, campo, viejo (lo que ya había escrito), diaSel, origen
 */
function agenda_render_publica(array $tipo, array $extra = []): void
{
    $motivo = null;
    $huecos = agenda_huecos($tipo, 0, $motivo, null, ['cache' => AGENDA_CACHE_HORARIOS]);
    $dias   = array_keys($huecos);
    $diaSel = (string)($extra['diaSel'] ?? ($_GET['dia'] ?? ''));
    if (!isset($huecos[$diaSel])) { $diaSel = (string)($dias[0] ?? ''); }

    render('agenda-publica', [
        'tipo'      => $tipo,
        'huecos'    => $huecos,
        'diaSel'    => $diaSel,
        'conectado' => gcal_disponible((int)$tipo['usuario_id']),
        // Cuando no se pudo leer el calendario no se muestran horarios. El visitante
        // no tiene por qué enterarse del problema técnico, pero tampoco se le puede
        // dejar una pantalla vacía sin explicación ni salida.
        'motivo'    => $motivo,
        'campana'   => (int)($extra['campana'] ?? ($_GET['c'] ?? 0)),
        'origen'    => $extra['origen'] ?? agenda_origen_de_url(),
        'formToken' => agenda_form_token((int)$tipo['id']),
        'error'     => $extra['error'] ?? null,
        'campo'     => $extra['campo'] ?? null,
        'viejo'     => $extra['viejo'] ?? [],
        'bodyClass' => 'publica',
    ], $tipo['nombre'] . ' · ' . $tipo['usuario']);
}

/**
 * Lo que la URL de la agenda pública trae de dónde vino el visitante: las UTM
 * y el sitio que lo mandó. Viaja en campos ocultos hasta la confirmación y
 * queda en la entrada de la web, para saber por qué canal llegan las reuniones.
 */
function agenda_origen_de_url(): array
{
    $o = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
        $v = agenda_una_linea(is_string($_GET[$k] ?? null) ? $_GET[$k] : '');
        if ($v !== '') { $o[$k] = mb_substr($v, 0, 150); }
    }
    $ref = is_string($_GET['ref'] ?? null) ? (string)$_GET['ref'] : (string)($_SERVER['HTTP_REFERER'] ?? '');
    $propio = (string) parse_url(url_abs(''), PHP_URL_HOST);
    if (preg_match('#^https?://#i', $ref) && strlen($ref) <= 300
        && strcasecmp((string)parse_url($ref, PHP_URL_HOST), $propio) !== 0) {
        $o['referrer'] = $ref;
    }
    return $o;
}

/** Lo mismo, leído del formulario que se mandó. */
function agenda_origen_de_post(): array
{
    $o = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
        $v = agenda_una_linea(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
        if ($v !== '') { $o[$k] = mb_substr($v, 0, 150); }
    }
    $ref = is_string($_POST['referrer'] ?? null) ? (string)$_POST['referrer'] : '';
    if (preg_match('#^https?://#i', $ref) && strlen($ref) <= 300) { $o['referrer'] = $ref; }
    return $o;
}

/**
 * Arma la invitación de calendario (formato iCalendar) de una reserva.
 *
 * Esto es lo que hace que Gmail y Outlook muestren los botones «Sí / No /
 * Quizás» arriba del correo y que la reunión se pueda meter en el calendario
 * con un clic. Se arma acá y no se delega en Google: si la API de Calendar no
 * responde, la invitación llega igual.
 *
 * @param string $metodo REQUEST para invitar, CANCEL para dar de baja
 */
function agenda_ics(array $reserva, array $tipo, string $metodo = 'REQUEST'): string
{
    $utc = static fn(string $f): string => gmdate('Ymd\THis\Z', strtotime($f));

    // El identificador tiene que ser SIEMPRE el mismo para la misma reunión:
    // es lo que permite que un cambio de horario o una cancelación pisen a la
    // invitación original en vez de aparecer como un evento nuevo. Desde que
    // existe `ics_uid` es el del evento de Google (para Gmail, la invitación y
    // el evento son uno solo) y pasa de una reserva a la siguiente cuando se
    // cambia el horario. Las reservas sin él siguen con el de siempre.
    $uid   = (string)(($reserva['ics_uid'] ?? '') ?: agenda_ics_uid_propio((int)$reserva['id'], (string)$reserva['token']));

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

    // Saltos de línea reales: el escapado de abajo los convierte en el \n
    // del formato. Escritos ya escapados, se escaparían dos veces y el
    // calendario mostraría «\n» en el texto.
    // Sin el comentario del visitante: la invitación sale firmada por la
    // empresa a la dirección que él escribió, y los calendarios convierten en
    // enlace cualquier dirección del texto. Lo que escribió queda en el CRM.
    $desc = 'Reunión coordinada desde ' . setting_get('empresa_nombre', 'INTELLIHELP') . '.';
    if ($meet !== '')                     { $desc .= "\n\nEnlace: " . $meet; }
    if ($metodo !== 'CANCEL') {
        $desc .= "\n\nPara cambiar el horario: " . url_abs('r/mover?t=' . $reserva['token']);
        $desc .= "\n\nPara cancelar: " . url_abs('r/cancelar?t=' . $reserva['token']);
    }

    // Escapado del formato: coma, punto y coma y barra son caracteres de control.
    // Un retorno suelto también pasa a \n: algunos calendarios lo toman como
    // fin de línea, y sin esto un texto podía agregar propiedades a la
    // invitación.
    $esc = static fn(string $s): string => str_replace(
        ["\\", "\r\n", "\r", "\n", ',', ';'], ['\\\\', '\\n', '\\n', '\\n', '\\,', '\\;'], trim($s));
    // Los parámetros (el nombre de CN=) no se escapan con barras: van entre
    // comillas dobles, sin comillas ni saltos adentro. Sin comillas, un nombre
    // con «:» corta la propiedad y uno con coma («Pérez, Juan») queda mal
    // formado, y los clientes de correo no muestran los botones de respuesta.
    $param = static fn(string $s): string => '"' . trim((string) preg_replace('/[\x00-\x1F\x7F"]+/u', ' ', $s)) . '"';

    $lineas = [
        'BEGIN:VCALENDAR',
        'PRODID:-//INTELLIHELP//CRM//ES',
        'VERSION:2.0',
        'CALSCALE:GREGORIAN',
        'METHOD:' . $metodo,
        'BEGIN:VEVENT',
        'UID:' . $uid,
        // La secuencia sube con cada cambio de horario y con la cancelación,
        // para que el calendario entienda que es una actualización de la
        // invitación anterior y no otra reunión. La lleva la reserva.
        'SEQUENCE:' . max(0, (int)($reserva['ics_secuencia'] ?? 0)),
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $utc((string)$reserva['inicia_el']),
        'DTEND:'   . $utc((string)$reserva['termina_el']),
        'SUMMARY:' . $esc((string)($tipo['nombre'] ?? 'Reunión') . ' · ' . setting_get('empresa_nombre', 'INTELLIHELP')),
        'DESCRIPTION:' . $esc($desc),
        'LOCATION:' . $esc($meet !== '' ? $meet : $lugar),
        'ORGANIZER;CN=' . $param((string)($tipo['usuario'] ?? 'INTELLIHELP')) . ':mailto:' . $orgEm,
        'ATTENDEE;CN=' . $param((string)$reserva['invitado_nombre'])
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
 * línea, ancho de 600, versión en texto plano).
 */
function agenda_avisar(array $reserva, array $tipo, string $clase = 'confirmacion'): bool
{
    if (!smtp_configurado() || empty($reserva['invitado_email'])) { return false; }

    // La hora va en la zona del tipo de reunión, que es la que el visitante
    // vio al elegir el horario.
    $inicio  = strtotime((string)$reserva['inicia_el']);
    $local   = agenda_local($inicio, $tipo);
    $cuando  = ucfirst(fecha_larga($local)) . ' a las ' . substr($local, 11, 5) . ' h';
    $zona    = agenda_zona_etiqueta($tipo);
    $cancel  = url_abs('r/cancelar?t=' . $reserva['token']);
    $mover   = url_abs('r/mover?t=' . $reserva['token']);
    $meet    = (string)($reserva['meet_url'] ?? '');
    $conQuien = (string)($tipo['usuario'] ?? setting_get('empresa_nombre', 'INTELLIHELP'));
    $movida  = $clase === 'confirmacion' && !empty($reserva['reprogramada_de']);

    $titulos = [
        'confirmacion' => $movida ? 'Cambiamos el horario de la reunión' : 'Reunión confirmada',
        'recordatorio' => 'Te recordamos tu reunión',
        'cancelacion'  => 'Reunión cancelada',
    ];
    $titulo = $titulos[$clase] ?? $titulos['confirmacion'];

    $entrada = match (true) {
        $clase === 'recordatorio' => 'Te escribimos para recordarte la reunión que tenemos coordinada.',
        $clase === 'cancelacion'  => 'Te confirmamos que la reunión quedó cancelada. No tenés que hacer nada más.',
        $movida                   => 'Listo, la reunión quedó en el horario nuevo. La invitación adjunta '
                                   . 'reemplaza a la anterior en tu calendario.',
        default                   => 'Listo, ya está reservado el horario. Te dejamos los datos por las dudas.',
    };

    // Los datos de la reunión van en un recuadro para que se lean de un vistazo
    // en el celular, que es donde se abre la mayoría de estos correos.
    $ficha = 'Reunión: ' . (string)($tipo['nombre'] ?? '') . "\n"
           . 'Con: ' . $conQuien . "\n"
           . 'Cuándo: ' . $cuando . ' (' . mb_strtolower(mb_substr($zona, 0, 1)) . mb_substr($zona, 1) . ')' . "\n"
           . 'Duración: ' . (int)($tipo['duracion_min'] ?? 30) . ' minutos';

    // El correo sale con la marca de la empresa a una dirección que nadie
    // verificó: lo que escribió el visitante se reduce al mínimo y nunca se
    // convierte en enlace. Del nombre, la primera palabra; el comentario no se
    // repite (queda en el CRM). Si no, un nombre o un comentario con una
    // dirección web convertirían la confirmación en un correo de la empresa
    // con un enlace cualquiera, mandado a quien el que reserva quiera.
    $saludo = mb_substr(preg_split('/\s+/u', trim((string)$reserva['invitado_nombre']))[0] ?? '', 0, 40);

    $bloques = [
        ['tipo' => 'encabezado', 'alto' => 34],
        ['tipo' => 'titulo', 'texto' => $titulo],
        ['tipo' => 'texto',  'texto' => $saludo !== '' ? 'Hola ' . $saludo . ',' : 'Hola,', 'sin_enlaces' => true],
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
        // Cambiar el horario también se resuelve solo, sin escribirnos: antes
        // el correo sólo ofrecía cancelar.
        $bloques[] = ['tipo' => 'texto', 'texto' => '¿Necesitás otro horario? Cambialo acá: ' . $mover];
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
    //   confirmación → REQUEST: es la invitación propiamente dicha, con los botones
    //                  «Sí / No / Quizás».
    //   recordatorio → PUBLISH: informativo. Con REQUEST el cliente le
    //                  volvería a pedir al invitado que acepte algo que ya
    //                  aceptó, y en Outlook aparecería como una invitación
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
    $filas = q("SELECT r.*, t.nombre, t.duracion_min, t.con_meet, t.zona_horaria,
                       u.nombre AS usuario, u.email AS usuario_email
                FROM agenda_reservas r
                JOIN agenda_tipos t ON t.id = r.tipo_id
                JOIN usuarios u     ON u.id = r.usuario_id
                WHERE r.estado = 'confirmada' AND r.recordatorio_enviado_en IS NULL
                  AND r.inicia_el BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)")->fetchAll();
    $n = 0;
    foreach ($filas as $r) {
        // Sólo se marca si SALIÓ. Un correo que falló —o que ni se intentó
        // porque no hay casilla configurada— queda sin marcar y se reintenta
        // en la corrida siguiente.
        if (!agenda_avisar($r, $r, 'recordatorio')) { continue; }
        q('UPDATE agenda_reservas SET recordatorio_enviado_en = NOW() WHERE id = ?', [(int)$r['id']]);
        $n++;
    }
    return $n;
}

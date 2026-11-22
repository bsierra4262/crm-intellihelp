<?php
declare(strict_types=1);
require_admin();

// Feriados de la agenda: los nacionales se cargan solos desde el calendario
// oficial; acá se ven, se agregan los regionales o los días que la empresa no
// trabaja, se marca «se trabaja» un feriado oficial y se fuerza la
// actualización. La lógica vive en app/feriados.php.
//
// Ruta: feriados?anio=AAAA (sólo administradores). Módulo: Agenda (la ruta
// figura en modulos.php). Vista: views/feriados.php. Tabla: feriados, y lee
// agenda_reservas para mostrar las reuniones que caen en un feriado.
// Acciones por POST (con CSRF), todas vuelven con Post/Redirect/Get al año
// de la fila tocada:
//   · sincronizar: «Actualizar ahora», fuerza la consulta a la fuente;
//   · agregar: carga (o pisa) un feriado a mano para una fecha y jurisdicción;
//   · ignorar: uno oficial queda marcado «se trabaja»; uno manual se borra;
//   · restituir: deshace «se trabaja»;
//   · ajustes: jurisdicción que rige y si la agenda respeta feriados.
// Agregar o restituir avisa a quien atiende cada reunión ya agendada ese día.

$anio = (int)($_GET['anio'] ?? date('Y'));
if ($anio < 2000 || $anio > 2100) { $anio = (int)date('Y'); }

$fechaValida = static function (string $v): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
};

if (is_post()) {
    verify_csrf();
    $accion = (string)($_POST['accion'] ?? '');
    try {
        switch ($accion) {
            case 'sincronizar':
                // null significa «otra sincronización tiene el bloqueo», pero
                // feriados_asegurar() también devuelve null si algo lanzó una
                // excepción (la registra en log_error): en ese caso el mensaje
                // de «en curso» no es el motivo real.
                $r = feriados_asegurar(false, true);
                if ($r === null) {
                    flash('Ya hay una actualización en curso. Probá de nuevo en un minuto.', 'warn');
                } elseif ($r['error'] && !$r['anios']) {
                    flash('No se pudo consultar el calendario oficial (' . $r['error'] . '). '
                        . 'La agenda sigue con los feriados que ya tenía.', 'error');
                } else {
                    flash('Feriados al día con el calendario oficial de ' . implode(' y ', $r['anios']) . ': '
                        . count($r['agregados']) . ' nuevos, ' . count($r['quitados']) . ' quitados.', 'success');
                }
                break;

            case 'agregar':
                $fecha  = (string)($_POST['fecha'] ?? '');
                $nombre = agenda_una_linea((string)($_POST['nombre'] ?? ''));
                $jur    = (string)($_POST['jurisdiccion'] ?? 'nacional');
                $tipo   = (string)($_POST['tipo'] ?? 'no_laborable');
                if (!$fechaValida($fecha)) { flash('La fecha no es válida.', 'error'); break; }
                if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 160) {
                    flash('Escribí el nombre del feriado (hasta 160 caracteres).', 'error'); break;
                }
                if (!isset(FERIADOS_JURISDICCIONES[$jur])) { $jur = 'nacional'; }
                if (!isset(FERIADOS_TIPOS[$tipo])) { $tipo = 'no_laborable'; }
                $anio = (int)substr($fecha, 0, 4);
                $previo = q1('SELECT id, ignorado FROM feriados WHERE fecha = ? AND jurisdiccion = ?', [$fecha, $jur]);
                // Cargado a mano queda manual: la sincronización no lo vuelve a tocar.
                // Eso incluye pisar uno oficial de la misma fecha: pasa a manual,
                // y si después el calendario oficial lo traslada, éste queda donde
                // estaba y el nuevo se agrega aparte.
                if ($previo) {
                    q("UPDATE feriados SET nombre = ?, tipo = ?, origen = 'manual', ignorado = 0 WHERE id = ?",
                      [$nombre, $tipo, (int)$previo['id']]);
                } else {
                    q("INSERT INTO feriados (fecha, nombre, tipo, jurisdiccion, origen, ignorado, creado_por)
                       VALUES (?, ?, ?, ?, 'manual', 0, ?)", [$fecha, $nombre, $tipo, $jur, current_user_id()]);
                }
                // Sólo se avisa si el día pasa a ser feriado (no existía o estaba
                // «se trabaja»); renombrar uno que ya regía no manda alertas.
                $nuevo = ['fecha' => $fecha, 'nombre' => $nombre, 'jurisdiccion' => $jur];
                $avisadas = (!$previo || (int)$previo['ignorado']) ? feriados_avisar_reuniones([$nuevo]) : 0;
                log_app('feriados.agregar', ['detalle' => $fecha . ' ' . feriado_etiqueta($nuevo)]);
                $rige = $jur === 'nacional' || $jur === feriados_jurisdiccion();
                flash('Feriado guardado: ' . fecha_corta($fecha) . ' · ' . feriado_etiqueta($nuevo) . '.'
                    . ($rige ? ' Ese día la agenda no ofrece horarios.'
                             : ' No rige para la agenda: es de otra jurisdicción que la configurada.')
                    . ($avisadas ? ' Hay ' . $avisadas . ' reunión(es) agendadas ese día: se le avisó a cada responsable.' : ''),
                    'success');
                break;

            case 'ignorar':
                $f = q1('SELECT * FROM feriados WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
                if (!$f) { break; }
                $anio = (int)substr((string)$f['fecha'], 0, 4);
                if ($f['origen'] === 'auto') {
                    // Queda la fila marcada: si se borrara, la próxima sincronización lo traería de vuelta.
                    q('UPDATE feriados SET ignorado = 1 WHERE id = ?', [(int)$f['id']]);
                    flash('Listo: el ' . fecha_corta((string)$f['fecha']) . ' se trabaja y la agenda ofrece horarios. '
                        . 'La actualización automática no lo vuelve a agregar.', 'success');
                } else {
                    q('DELETE FROM feriados WHERE id = ?', [(int)$f['id']]);
                    flash('Feriado eliminado.', 'success');
                }
                log_app('feriados.ignorar', ['detalle' => $f['fecha'] . ' ' . feriado_etiqueta($f)]);
                break;

            case 'restituir':
                $f = q1('SELECT * FROM feriados WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
                if (!$f) { break; }
                $anio = (int)substr((string)$f['fecha'], 0, 4);
                q('UPDATE feriados SET ignorado = 0 WHERE id = ?', [(int)$f['id']]);
                $avisadas = feriados_avisar_reuniones([$f]);
                log_app('feriados.restituir', ['detalle' => $f['fecha'] . ' ' . feriado_etiqueta($f)]);
                flash('El ' . fecha_corta((string)$f['fecha']) . ' vuelve a ser feriado y la agenda no ofrece horarios.'
                    . ($avisadas ? ' Hay ' . $avisadas . ' reunión(es) agendadas ese día: se le avisó a cada responsable.' : ''),
                    'success');
                break;

            case 'ajustes':
                $jur = (string)($_POST['jurisdiccion'] ?? 'CABA');
                if (!isset(FERIADOS_JURISDICCIONES[$jur]) || $jur === 'nacional') { $jur = 'CABA'; }
                setting_set('feriados_jurisdiccion', $jur);
                setting_set('feriados_activo', !empty($_POST['activo']) ? '1' : '0');
                log_app('feriados.ajustes', ['detalle' => 'Jurisdicción ' . $jur . ' · '
                    . (!empty($_POST['activo']) ? 'la agenda respeta los feriados' : 'la agenda NO respeta los feriados')]);
                flash('Ajustes de feriados guardados.', 'success');
                break;
        }
    } catch (\Throwable $e) {
        log_error('feriados.' . $accion, get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
        flash('No se pudo guardar el cambio. Si sigue pasando, revisá que la migración 009 esté aplicada.', 'error');
    }
    redirect('feriados?anio=' . $anio);
}

// ---- Datos para la vista ----
// Se listan TODAS las filas del año (también las de otras jurisdicciones y
// las ignoradas); $rigen es el subconjunto que de verdad quita turnos, y con
// él se buscan las reuniones futuras que caen en un feriado.
$jurisdiccion = feriados_jurisdiccion();
$filas = [];
$afectadas = [];
$sinTabla = false;
try {
    // Si no hay nada del año en curso, la primera visita ya los trae.
    feriados_asegurar();
    $filas = q('SELECT f.*, u.nombre AS autor FROM feriados f LEFT JOIN usuarios u ON u.id = f.creado_por
                WHERE f.fecha BETWEEN ? AND ? ORDER BY f.fecha, f.jurisdiccion',
               [$anio . '-01-01', $anio . '-12-31'])->fetchAll();
    $rigen = feriados_activos() ? feriados_aplicables($filas, $jurisdiccion) : [];
    $futuras = array_values(array_filter(array_keys($rigen), static fn(string $f): bool => $f >= date('Y-m-d')));
    if ($futuras) {
        $ph = implode(', ', array_fill(0, count($futuras), '?'));
        $afectadas = q("SELECT r.id, r.inicia_el, r.invitado_nombre, r.invitado_empresa, t.nombre AS tipo, u.nombre AS usuario
                        FROM agenda_reservas r JOIN agenda_tipos t ON t.id = r.tipo_id JOIN usuarios u ON u.id = r.usuario_id
                        WHERE r.estado = 'confirmada' AND r.inicia_el > NOW() AND DATE(r.inicia_el) IN ($ph)
                        ORDER BY r.inicia_el", $futuras)->fetchAll();
    }
} catch (\PDOException $e) {
    // Se asume que falta la migración 009; cualquier otra falla de la base
    // también cae acá y muestra el mismo aviso.
    $sinTabla = true;
}

render('feriados', [
    'anio'           => $anio,
    'filas'          => $filas,
    'afectadas'      => $afectadas,
    'jurisdiccion'   => $jurisdiccion,
    'activo'         => feriados_activos(),
    'sinTabla'       => $sinTabla,
    'sincronizadoEn' => (string) setting_get('feriados_sincronizado_en', ''),
    'ultimoError'    => (string) setting_get('feriados_ultimo_error', ''),
], 'Feriados');

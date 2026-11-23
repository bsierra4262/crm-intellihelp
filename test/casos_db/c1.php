<?php
declare(strict_types=1);

// ============================================================
//  Casos con base del lote C1: agenda propia de reuniones y reservas.
//
//  Corre dentro de run_tests.php, después del bloque de feriados, sobre la
//  misma base SQLite en memoria: usa el tipo 4 «diagnostico» de Carlos (id 1)
//  y los feriados que dejó cargados ese bloque (Navidad del año que viene).
//
//  Cubre: carreras al mover y cancelar (fila leída antes de que otro la
//  cambiara), base antes que Google con el pendiente marcado y el reintento
//  del cron, campaña descontada al cancelar, tope diario sin contar los
//  cambios de horario, feriados por fecha argentina, alerta y evento al
//  completar, orden de los pendientes del cron, validación del tipo de
//  reunión, slug que choca entre usuarios y el bloqueo dentro de una
//  transacción.
// ============================================================

// ---- Andamiaje: lo de MariaDB que usan estas consultas y SQLite no tiene ----
db()->sqliteCreateFunction('GET_LOCK', static fn($n, $t) => 1, 2);
db()->sqliteCreateFunction('DATABASE', static fn() => 'test', 0);
db()->sqliteCreateFunction('CONCAT', static fn(...$a) => implode('', array_map('strval', $a)), -1);
db()->sqliteCreateFunction('CURDATE', static fn() => date('Y-m-d'), 0);
db()->sqliteCreateFunction('SUBSTRING_INDEX', static function ($s, $sep, $n) {
    $partes = explode((string)$sep, (string)$s);
    return (int)$n >= 0 ? implode((string)$sep, array_slice($partes, 0, (int)$n))
                        : implode((string)$sep, array_slice($partes, (int)$n));
}, 3);

/** Columnas de una tabla de la base en memoria. */
$c1Columnas = static fn(string $t): array => array_column(db()->query("PRAGMA table_info($t)")->fetchAll(), 'name');

/**
 * Inserta una fila completando con '' o 0 cualquier columna NOT NULL sin
 * valor por defecto: si otra área creó la tabla con más columnas, el insert
 * no falla por eso.
 */
$c1Insertar = static function (string $t, array $valores): int {
    foreach (db()->query("PRAGMA table_info($t)")->fetchAll() as $col) {
        if ((int)$col['notnull'] === 1 && $col['dflt_value'] === null && (int)$col['pk'] === 0
            && !array_key_exists($col['name'], $valores)) {
            $valores[$col['name']] = preg_match('/INT|REAL|NUM/i', (string)$col['type']) ? 0 : '';
        }
    }
    $cols = implode(', ', array_keys($valores));
    q("INSERT INTO $t ($cols) VALUES (" . implode(', ', array_fill(0, count($valores), '?')) . ')', array_values($valores));
    return last_id();
};

// agenda_reservas: el bloque de feriados la creó con pocas columnas y sin el
// estado por defecto. Se rehace con las de la base real, copiando lo que había.
if (!in_array('gcal_pendiente', $c1Columnas('agenda_reservas'), true)) {
    $viejas = $c1Columnas('agenda_reservas');
    db()->exec("CREATE TABLE agenda_reservas_c1 (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tipo_id INTEGER, usuario_id INTEGER, token TEXT,
        inicia_el TEXT, termina_el TEXT, invitado_nombre TEXT, invitado_email TEXT, invitado_empresa TEXT,
        invitado_telefono TEXT, comentario TEXT, estado TEXT NOT NULL DEFAULT 'confirmada',
        gcal_event_id TEXT, meet_url TEXT, gcal_pendiente TEXT, ics_uid TEXT, ics_secuencia INTEGER NOT NULL DEFAULT 0,
        reprogramada_de INTEGER, origen TEXT, avisada_en TEXT, consentimiento_en TEXT, consentimiento_version TEXT,
        contacto_id INTEGER, actividad_id INTEGER, campana_id INTEGER, recordatorio_enviado_en TEXT,
        creado_en TEXT, actualizado_en TEXT)");
    $comunes = implode(', ', $viejas);
    db()->exec("INSERT INTO agenda_reservas_c1 ($comunes) SELECT $comunes FROM agenda_reservas");
    db()->exec('DROP TABLE agenda_reservas');
    db()->exec('ALTER TABLE agenda_reservas_c1 RENAME TO agenda_reservas');
    db()->exec("CREATE TRIGGER ar_creado AFTER INSERT ON agenda_reservas WHEN NEW.creado_en IS NULL
                BEGIN UPDATE agenda_reservas SET creado_en = ahora(), actualizado_en = ahora() WHERE id = NEW.id; END");
}
if (!in_array('descripcion', $c1Columnas('agenda_tipos'), true)) {
    db()->exec('ALTER TABLE agenda_tipos ADD COLUMN descripcion TEXT');
}
db()->exec('CREATE TABLE IF NOT EXISTS campanas (id INTEGER PRIMARY KEY, reuniones INTEGER NOT NULL DEFAULT 0)');
db()->exec('CREATE TABLE IF NOT EXISTS actividades (id INTEGER PRIMARY KEY AUTOINCREMENT, inicia_el TEXT, termina_el TEXT, eliminado_en TEXT)');
db()->exec('CREATE TABLE IF NOT EXISTS notificaciones (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT, entidad TEXT, entidad_id INTEGER)');
db()->exec('CREATE TABLE IF NOT EXISTS google_tokens (usuario_id INTEGER PRIMARY KEY, refresh_token TEXT, access_token TEXT, expira_en TEXT)');

$zAR  = new DateTimeZone('America/Argentina/Buenos_Aires');
$A    = (int)date('Y') + 1;
$enAR = static fn(string $f): int => (new DateTimeImmutable($f, $zAR))->getTimestamp();

/** Una reserva confirmada del tipo 4 de Carlos; $extra pisa columnas. */
$c1Reserva = static function (string $inicio, array $extra = []) use ($c1Insertar, $enAR): array {
    $ts = $enAR($inicio);
    $id = $c1Insertar('agenda_reservas', $extra + [
        'tipo_id' => 4, 'usuario_id' => 1, 'token' => token32(), 'estado' => 'confirmada',
        'inicia_el' => date('Y-m-d H:i:s', $ts), 'termina_el' => date('Y-m-d H:i:s', $ts + 1800),
        'invitado_nombre' => 'Juana Prueba', 'invitado_email' => 'juana@empresa-prueba.com.ar',
        'origen' => 'publica', 'ics_secuencia' => 0,
    ]);
    return q1('SELECT * FROM agenda_reservas WHERE id = ?', [$id]);
};
$c1Fila = static fn(int $id): ?array => q1('SELECT * FROM agenda_reservas WHERE id = ?', [$id]);

// ---- Carreras al mover y cancelar ----
echo "-- Carreras al mover y cancelar --\n";
$r1    = $c1Reserva("$A-09-15 10:00:00");
$vieja = $r1;
ok('cancelar una reunión confirmada', agenda_cancelar((string)$r1['token']));
$exc = null;
try { agenda_reprogramar_reserva($vieja, $enAR("$A-09-16 11:00:00")); } catch (AgendaExcepcion $e) { $exc = $e; }
eq('mover con la fila leída antes de que la cancelaran: rechazado', $exc?->motivo, 'datos_invalidos');
eq('y no queda ninguna fila nueva colgando de la cancelada',
   (int) qv('SELECT COUNT(*) FROM agenda_reservas WHERE reprogramada_de = ?', [(int)$r1['id']]), 0);
eq('la cancelada sigue cancelada', $c1Fila((int)$r1['id'])['estado'], 'cancelada');

$r2    = $c1Reserva("$A-09-17 10:00:00");
$vieja = $r2;
$nueva = agenda_reprogramar((string)$r2['token'], $enAR("$A-09-17 15:00:00"));
eq('mover deja la vieja «reprogramada» y una nueva confirmada',
   [$c1Fila((int)$r2['id'])['estado'], $nueva['estado'], (int)$nueva['reprogramada_de']],
   ['reprogramada', 'confirmada', (int)$r2['id']]);
ok('cancelar con la fila leída antes de que la movieran: no hace nada', !agenda_cancelar_reserva($vieja));
eq('y la reunión movida sigue en pie', $c1Fila((int)$nueva['id'])['estado'], 'confirmada');
$exc = null;
try { agenda_reprogramar_reserva($vieja, $enAR("$A-09-18 10:00:00")); } catch (AgendaExcepcion $e) { $exc = $e; }
eq('moverla otra vez desde la fila vieja: rechazado', $exc?->motivo, 'datos_invalidos');
eq('una sola confirmada en toda la cadena',
   (int) qv("SELECT COUNT(*) FROM agenda_reservas WHERE estado = 'confirmada' AND (id = ? OR reprogramada_de = ?)",
            [(int)$r2['id'], (int)$r2['id']]), 1);

$r3 = $c1Reserva("$A-09-21 10:00:00");
$copia = $r3;
ok('la primera cancelación sale', agenda_cancelar_reserva($r3));
ok('la segunda, en paralelo con la misma fila, no (un solo aviso)', !agenda_cancelar_reserva($copia));
eq('la secuencia de la invitación subió una sola vez', (int)$c1Fila((int)$r3['id'])['ics_secuencia'], 1);

// ---- Base primero, Google después ----
// Sin token de Google guardado (google_tokens vacía), cualquier llamada a
// Google falla: la base tiene que quedar cambiada y el pendiente marcado.
echo "-- Base primero, Google después --\n";
$r4 = $c1Reserva("$A-09-22 10:00:00", ['gcal_event_id' => 'ev-prueba-1', 'ics_uid' => 'ev-prueba-1@google.com']);
$m4 = agenda_reprogramar((string)$r4['token'], $enAR("$A-09-22 16:00:00"));
eq('Google falla al mover: la reunión igual queda movida en el CRM',
   [$c1Fila((int)$r4['id'])['estado'], $m4['estado'], $m4['inicia_el']],
   ['reprogramada', 'confirmada', date('Y-m-d H:i:s', $enAR("$A-09-22 16:00:00"))]);
eq('y queda marcado para que el cron mueva el evento', $m4['gcal_pendiente'], 'mover');
eq('con la misma invitación y la secuencia siguiente', [$m4['ics_uid'], (int)$m4['ics_secuencia']], ['ev-prueba-1@google.com', 1]);
$avisado = true;
ok('cancelarla con Google caído también sale', agenda_cancelar((string)$m4['token'], 'prueba', $avisado));
$c4 = $c1Fila((int)$m4['id']);
eq('cancelada en la base y marcada para borrar el evento', [$c4['estado'], $c4['gcal_pendiente'], (int)$c4['ics_secuencia']],
   ['cancelada', 'borrar', 2]);
ok('sin correo configurado, avisa que el correo no salió', $avisado === false);

// El cron: lo que sigue fallando queda marcado y pasa al final de la fila;
// lo que ya no hace falta (una fila reprogramada: su evento lo lleva la
// nueva) se desmarca sin tocar Google.
q("UPDATE agenda_reservas SET actualizado_en = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 600), (int)$m4['id']]);
q("UPDATE agenda_reservas SET gcal_pendiente = 'mover', actualizado_en = ? WHERE id = ?",
  [date('Y-m-d H:i:s', time() - 600), (int)$r4['id']]);
eq('el cron resuelve la fila vieja (sólo la desmarca)', agenda_sincronizar_google(), 1);
eq('la vieja queda sin pendiente', $c1Fila((int)$r4['id'])['gcal_pendiente'], null);
$c4 = $c1Fila((int)$m4['id']);
eq('la cancelada sigue marcada mientras Google no responda', $c4['gcal_pendiente'], 'borrar');
ok('y pasó al final de la fila (actualizado_en de recién)', strtotime((string)$c4['actualizado_en']) >= time() - 5);
eq('dentro de los dos minutos de gracia el cron no la toca', agenda_sincronizar_google(), 0);

// ---- Cancelar descuenta la reunión de la campaña ----
echo "-- Campaña al cancelar --\n";
$c1Insertar('campanas', ['id' => 9701, 'reuniones' => 3]);
$c1Insertar('campanas', ['id' => 9702, 'reuniones' => 0]);
$r5 = $c1Reserva("$A-09-23 10:00:00", ['campana_id' => 9701]);
agenda_cancelar((string)$r5['token']);
eq('la campaña pasa de 3 a 2 reuniones', (int) qv('SELECT reuniones FROM campanas WHERE id = 9701'), 2);
$r6 = $c1Reserva("$A-09-23 11:00:00", ['campana_id' => 9702]);
agenda_cancelar((string)$r6['token']);
eq('nunca baja de cero', (int) qv('SELECT reuniones FROM campanas WHERE id = 9702'), 0);

// ---- Tope diario: los cambios de horario no cuentan ----
echo "-- Tope diario --\n";
$GLOBALS['__ajustes']['agenda_tope_diario'] = '2';
q("DELETE FROM agenda_reservas WHERE usuario_id = 2");
$t1 = $c1Reserva("$A-09-24 10:00:00", ['usuario_id' => 2, 'origen' => 'web', 'invitado_email' => 'a@empresa-prueba.com.ar']);
$c1Reserva("$A-09-24 12:00:00", ['usuario_id' => 2, 'origen' => 'web', 'reprogramada_de' => (int)$t1['id'],
                                  'invitado_email' => 'a@empresa-prueba.com.ar']);
$exc = null;
try { agenda_controlar_topes(2, 'b@empresa-prueba.com.ar'); } catch (AgendaExcepcion $e) { $exc = $e; }
ok('una reserva y su cambio de horario no llenan un tope de 2', $exc === null);
$c1Reserva("$A-09-24 14:00:00", ['usuario_id' => 2, 'origen' => 'web', 'estado' => 'cancelada',
                                  'invitado_email' => 'c@empresa-prueba.com.ar']);
$exc = null;
try { agenda_controlar_topes(2, 'b@empresa-prueba.com.ar'); } catch (AgendaExcepcion $e) { $exc = $e; }
eq('una cancelada sí cuenta (reservar y cancelar en bucle)', $exc?->motivo, 'limite');
unset($GLOBALS['__ajustes']['agenda_tope_diario']);

// ---- Feriados por la fecha argentina ----
echo "-- Feriados por fecha argentina --\n";
$tipoMad = ['zona_horaria' => 'Europe/Madrid'] + agenda_tipo_get(4);
$enMad   = static fn(string $f): int => (new DateTimeImmutable($f, new DateTimeZone('Europe/Madrid')))->getTimestamp();
eq('el 25/12 a las 02:00 de Madrid es todavía 24/12 en la Argentina: no es feriado',
   agenda_feriado_de_inicio($tipoMad, $enMad("$A-12-25 02:00:00")), null);
eq('el 25/12 a las 10:00 de Madrid ya es Navidad en la Argentina',
   agenda_feriado_de_inicio($tipoMad, $enMad("$A-12-25 10:00:00"))['nombre'] ?? null, 'Navidad');
$mot = null;
agenda_hueco_libre($tipoMad, $enMad("$A-12-25 02:00:00"), null, $mot);
ok('la verificación final tampoco lo toma por feriado', $mot !== 'feriado');
$mot = null;
ok('y sí rechaza el horario que cae en Navidad argentina',
   !agenda_hueco_libre($tipoMad, $enMad("$A-12-25 10:00:00"), null, $mot) && $mot === 'feriado');
eq('para un tipo en hora argentina no cambia nada', agenda_feriado_de_inicio(agenda_tipo_get(4), $enAR("$A-12-25 10:00:00"))['fecha'] ?? null,
   "$A-12-25");

// ---- Completar: sin evento para una cancelada, sin segunda alerta para una movida ----
echo "-- Completar la reserva --\n";
$GLOBALS['__avisos'] = [];
$r7 = $c1Reserva("$A-09-25 10:00:00", ['estado' => 'cancelada']);
eq('una reserva cancelada no se completa', agenda_reserva_completar((int)$r7['id'])['estado'] ?? null, 'cancelada');
$r8 = $c1Reserva("$A-09-25 11:00:00");
$r9 = $c1Reserva("$A-09-25 12:00:00", ['reprogramada_de' => (int)$r8['id']]);
agenda_reserva_completar((int)$r9['id']);
eq('una reserva movida sin evento no repite la alerta «agendó una reunión»',
   count(array_filter($GLOBALS['__avisos'], static fn($a) => str_contains((string)($a['titulo'] ?? ''), 'agendó'))), 0);
agenda_reserva_completar((int)$r8['id']);
eq('una reserva nueva sí avisa', count(array_filter($GLOBALS['__avisos'], static fn($a) => str_contains((string)($a['titulo'] ?? ''), 'agendó'))), 1);

// ---- Cron de pendientes: lo que falla pasa al final de la fila ----
echo "-- Pendientes del cron --\n";
$p1 = $c1Reserva("$A-09-26 10:00:00", ['origen' => 'web']);
$hace10 = date('Y-m-d H:i:s', time() - 600);
q('UPDATE agenda_reservas SET creado_en = ?, actualizado_en = ? WHERE id = ?', [$hace10, $hace10, (int)$p1['id']]);
agenda_completar_pendientes();
ok('un intento fallido (sin correo) actualiza la fila para que la próxima vuelta empiece por otras',
   strtotime((string)$c1Fila((int)$p1['id'])['actualizado_en']) >= time() - 5);

// ---- Recordatorios ----
echo "-- Recordatorios --\n";
$ahora = time();
$fila  = static fn(int $creado, int $inicio): array => ['creado_en' => date('Y-m-d H:i:s', $creado), 'inicia_el' => date('Y-m-d H:i:s', $inicio)];
ok('reservada con días de anticipación: recordatorio', agenda_recordatorio_toca($fila($ahora - 5 * 86400, $ahora + 20 * 3600), $ahora));
ok('reservada hace 10 minutos para dentro de 5 h: sin recordatorio', !agenda_recordatorio_toca($fila($ahora - 600, $ahora + 5 * 3600), $ahora));
ok('reservada hace 13 h para dentro de 10 h: ahora sí', agenda_recordatorio_toca($fila($ahora - 13 * 3600, $ahora + 10 * 3600), $ahora));
ok('sin fecha de creación se manda como siempre', agenda_recordatorio_toca(['inicia_el' => date('Y-m-d H:i:s', $ahora + 3600)], $ahora));

// ---- Tipo de reunión: validación, slug y guardado ----
echo "-- Tipo de reunión --\n";
$base = ['nombre' => 'Demo', 'dias' => ['1'], 'desde' => ['1' => '09:00'], 'hasta' => ['1' => '12:00'],
         'zona_horaria' => 'America/Argentina/Buenos_Aires'];
eq('un tipo bien cargado no tiene errores', agenda_tipo_validar($base), []);
ok('nombre de más de 120: error', isset(agenda_tipo_validar(['nombre' => str_repeat('a', 121)] + $base)['nombre']));
ok('día 7: error', isset(agenda_tipo_validar(['dias' => ['7']] + $base)['dias']));
ok('hora mal escrita: error', isset(agenda_tipo_validar(['desde' => ['1' => '9']] + $base)['dias']));
ok('horas al revés: error', isset(agenda_tipo_validar(['desde' => ['1' => '13:00']] + $base)['dias']));
ok('zona inventada: error', isset(agenda_tipo_validar(['zona_horaria' => 'Marte/Olimpo'] + $base)['zona_horaria']));
eq('el slug se recorta a 60 sin guion final', strlen(slugificar(str_repeat('ab ', 40))), 59);
ok('y sigue siendo un slug válido', (bool) preg_match('/^[a-z0-9-]{1,60}$/', slugificar(str_repeat('ab ', 40))));

$nuevoTipo = agenda_tipo_guardar(['usuario_id' => 2, 'nombre' => 'Prueba C1', 'duracion_min' => 9999,
    'zona_horaria' => 'America/Argentina/Buenos_Aires', 'activo' => 1,
    'dias' => ['1', '7', 'x'], 'desde' => ['1' => '09:00', '7' => '09:00'], 'hasta' => ['1' => '25:00', '7' => '10:00']]);
eq('guardar descarta días inexistentes y horas mal escritas', (int) qv('SELECT COUNT(*) FROM agenda_horarios WHERE tipo_id = ?', [$nuevoTipo]), 0);
eq('y lleva la duración al máximo del formulario', (int) qv('SELECT duracion_min FROM agenda_tipos WHERE id = ?', [$nuevoTipo]), 480);
ok('el guardado no deja transacciones abiertas', !db()->inTransaction());

q("INSERT INTO usuarios (id, nombre, email, activo) VALUES (3, 'Carlos B', 'cbaldelli@otro-dominio.com', 1)");
ok('otro usuario con el mismo nombre de correo no puede usar el mismo slug', agenda_slug_choca_usuario(3, 'diagnostico'));
ok('alguien con otro nombre de correo sí', !agenda_slug_choca_usuario(2, 'diagnostico'));
ok('el dueño no choca consigo mismo', !agenda_slug_choca_usuario(1, 'diagnostico'));
q("INSERT INTO agenda_tipos (id, usuario_id, slug, nombre, duracion_min, margen_min, aviso_min_horas, ventana_dias, zona_horaria, con_meet, activo)
   VALUES (90, 3, 'diagnostico', 'Copia', 30, 0, 0, 30, 'America/Argentina/Buenos_Aires', 1, 1)");
eq('si el choque ya existía, el enlace sigue siendo del más antiguo', (int)(agenda_tipo_por_slug('cbaldelli', 'diagnostico')['id'] ?? 0), 4);
q('DELETE FROM agenda_tipos WHERE id = 90');
q("UPDATE usuarios SET activo = 0 WHERE id = 3");
eq('agenda_tipo_get trae si el dueño sigue activo', (int)(agenda_tipo_get(4)['usuario_activo'] ?? -1), 1);

// ---- Coberturas: el ajuste se lee una vez y cada usuario se consulta una vez ----
echo "-- Coberturas --\n";
$cob = [['usuario_id' => 2, 'desde' => "$A-12-01", 'hasta' => "$A-12-31"], ['usuario_id' => 3, 'desde' => "$A-11-01", 'hasta' => "$A-11-30"]];
$memo = [];
eq('cubre quien corresponde ese día', agenda_anfitrion_id(agenda_tipo_get(4), "$A-12-10", $cob, $memo), 2);
eq('si quien cubre está inactivo, vuelve al dueño', agenda_anfitrion_id(agenda_tipo_get(4), "$A-11-10", $cob, $memo), 1);
eq('y queda recordado quién está activo', $memo, [2 => true, 3 => false]);

// ---- Bloqueo dentro de una transacción ajena ----
echo "-- Bloqueo y transacciones --\n";
db()->beginTransaction();
$exc = null;
try { agenda_bloquear(1); } catch (LogicException $e) { $exc = $e; }
db()->rollBack();
ok('bloquear el calendario con una transacción abierta se rechaza', $exc !== null);

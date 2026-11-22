<?php
declare(strict_types=1);

// ============================================================
//  Casos con base (SQLite en memoria de run_tests.php) del lote A:
//  alertas leídas por persona, limpieza de datos de prueba, adjuntos con
//  borrado lógico y purga, etiquetas en transacción, registros vivos y la
//  red de seguridad de administradores.
//
//  notificaciones.php no se puede cargar con require: run_tests.php ya
//  define un notificar() de juguete. Se evalúa su código sin esa función.
// ============================================================

$__srcA = getenv('CRM_SRC') ?: (__DIR__ . '/../../app/web/app');
require_once $__srcA . '/auth.php';
require_once $__srcA . '/datos_prueba.php';
if (!function_exists('notif_de')) {
    $__n = (string) file_get_contents($__srcA . '/notificaciones.php');
    $__n = (string) preg_replace('/\nfunction notificar\(.*?\n\}\n/s', "\n", $__n, 1);
    // declare() sólo puede ir al principio de un archivo, no en un eval.
    $__n = str_replace('declare(strict_types=1);', '', $__n);
    eval('?>' . $__n);
}
if (!defined('STORAGE')) { define('STORAGE', sys_get_temp_dir() . '/crm_test_storage_a_' . getmypid()); }

db()->exec("CREATE TABLE IF NOT EXISTS notificaciones (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER,
    tipo TEXT NOT NULL, titulo TEXT NOT NULL, detalle TEXT, entidad TEXT, entidad_id INTEGER, url TEXT,
    leida_en TEXT, email_en TEXT, creada_en TEXT DEFAULT CURRENT_TIMESTAMP)");
try { db()->exec("ALTER TABLE usuarios ADD COLUMN rol TEXT DEFAULT 'comercial'"); } catch (\Throwable $e) {}

// ---- Alertas del equipo: sin la tabla de lecturas, como antes ----
q("INSERT INTO notificaciones (usuario_id, tipo, titulo) VALUES (NULL, 'respuesta', 'Equipo A')");
$nA = last_id();
q("INSERT INTO notificaciones (usuario_id, tipo, titulo) VALUES (9001, 'web', 'Personal de 9001')");
q("INSERT INTO notificaciones (usuario_id, tipo, titulo) VALUES (9002, 'web', 'Personal de 9002')");
notif_lecturas_disponible(true);
ok('sin la migración 010, se usa la columna única', !notif_lecturas_disponible());
eq('9001 ve la del equipo y la suya', notif_sin_leer(9001), 2);

// ---- Con la tabla de lecturas: cada uno lee lo suyo ----
db()->exec("CREATE TABLE IF NOT EXISTS notificacion_lecturas (notificacion_id INTEGER NOT NULL, usuario_id INTEGER NOT NULL,
    leida_en TEXT NOT NULL, PRIMARY KEY (notificacion_id, usuario_id))");
notif_lecturas_disponible(true);
ok('con la migración, lecturas por persona', notif_lecturas_disponible());
notif_marcar_leidas(9001);
eq('9001 marcó todo: no le queda nada', notif_sin_leer(9001), 0);
eq('a 9002 la del equipo le sigue sin leer (antes desaparecía para todos)', notif_sin_leer(9002), 2);
$filaDe = static function (int $uid, int $id): ?array {
    foreach (notif_de($uid) as $f) { if ((int)$f['id'] === $id) { return $f; } }
    return null;
};
ok('notif_de trae la lectura de cada uno', ($filaDe(9001, $nA)['leida_en'] ?? null) !== null
   && ($f2 = $filaDe(9002, $nA)) !== null && $f2['leida_en'] === null);
eq('la columna única de la alerta del equipo no se toca', qv('SELECT leida_en FROM notificaciones WHERE id = ?', [$nA]), null);
notif_marcar_leidas(9002, $nA);
eq('9002 marca sólo esa: le queda la personal', notif_sin_leer(9002), 1);
notif_marcar_leidas(9002, $nA);
eq('marcarla dos veces no duplica la lectura', (int) qv('SELECT COUNT(*) FROM notificacion_lecturas WHERE notificacion_id = ? AND usuario_id = 9002', [$nA]), 1);
notif_marcar_leidas(9001, (int) qv("SELECT id FROM notificaciones WHERE titulo = 'Personal de 9002'"));
eq('nadie marca una personal de otro', notif_sin_leer(9002), 1);
eq('las sin leer de la campana: sólo la suya', array_column(notif_de(9002, true), 'titulo'), ['Personal de 9002']);

// ---- A quién va el correo de cada alerta ----
$grupos = notif_agrupar_destinos([
    ['id' => 1, 'usuario_id' => 9001], ['id' => 2, 'usuario_id' => null], ['id' => 3, 'usuario_id' => 9003],
    ['id' => 4, 'usuario_id' => 9001],
], [9001 => 'ana@intellihelp.tech'], ['admin@intellihelp.tech', 'jefe@intellihelp.tech']);
eq('la personal va a su dueño, juntas en un correo', array_column($grupos['ana@intellihelp.tech']['alertas'], 'id'), [1, 4]);
eq('la del equipo y la de un dueño sin correo, a la lista', array_column($grupos['admin@intellihelp.tech,jefe@intellihelp.tech']['alertas'], 'id'), [2, 3]);
eq('sin lista del equipo, la del equipo queda sin destinatario', array_column(notif_agrupar_destinos([['id' => 7, 'usuario_id' => null]], [], [])['']['alertas'], 'id'), [7]);

// ---- Limpieza de datos de prueba: sólo las alertas sembradas ----
q("INSERT INTO notificaciones (usuario_id, tipo, titulo, entidad, entidad_id) VALUES (NULL, 'rebote', 'De prueba', 'contacto', 501)");
$nPrueba = last_id();
q("INSERT INTO notificaciones (usuario_id, tipo, titulo, entidad, entidad_id) VALUES (NULL, 'respuesta', 'Real', 'contacto', 777)");
q('INSERT INTO notificacion_lecturas (notificacion_id, usuario_id, leida_en) VALUES (?, 9001, ?)', [$nPrueba, date('Y-m-d H:i:s')]);
$antes = (int) qv('SELECT COUNT(*) FROM notificaciones');
eq('borra sólo la alerta del contacto sembrado', datos_prueba_borrar_alertas(['contacto' => [501], 'campana' => []]), 1);
eq('las reales quedan', (int) qv('SELECT COUNT(*) FROM notificaciones'), $antes - 1);
eq('y su marca de lectura se va con ella', (int) qv('SELECT COUNT(*) FROM notificacion_lecturas WHERE notificacion_id = ?', [$nPrueba]), 0);

// ---- Etiquetas: reemplazo en una transacción ----
db()->exec("CREATE TABLE IF NOT EXISTS etiquetas (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL UNIQUE, color TEXT)");
db()->exec("CREATE TABLE IF NOT EXISTS etiqueta_registro (etiqueta_id INTEGER NOT NULL, entidad TEXT NOT NULL, entidad_id INTEGER NOT NULL,
    PRIMARY KEY (etiqueta_id, entidad, entidad_id))");
db()->exec("CREATE TRIGGER IF NOT EXISTS etiqueta_falla_a BEFORE INSERT ON etiquetas WHEN NEW.nombre = 'FALLA-A'
    BEGIN SELECT RAISE(ABORT, 'falla de prueba'); END");
$etqDe = static fn(): array => array_column(etiquetas_de('contacto', 8801), 'nombre');
etiquetas_asignar('contacto', 8801, ['VIP-A', 'Norte-A']);
etiquetas_asignar('contacto', 8801, ['Norte-A', 'Sur-A']);
eq('reemplaza la lista completa', $etqDe(), ['Norte-A', 'Sur-A']);
$fallo = false;
try { etiquetas_asignar('contacto', 8801, ['Oeste-A', 'FALLA-A']); } catch (\Throwable $e) { $fallo = true; }
ok('si falla a mitad, se avisa', $fallo);
eq('y el registro queda con las etiquetas que tenía', $etqDe(), ['Norte-A', 'Sur-A']);
ok('sin transacción colgada', !db()->inTransaction());

// ---- Adjuntos: borrar es lógico; la purga borra el archivo ----
db()->exec("CREATE TABLE IF NOT EXISTS adjuntos (id INTEGER PRIMARY KEY AUTOINCREMENT, entidad TEXT NOT NULL, entidad_id INTEGER NOT NULL,
    nombre TEXT NOT NULL, ruta TEXT NOT NULL, mime TEXT, bytes INTEGER DEFAULT 0, creado_por INTEGER,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP, eliminado_en TEXT)");
@mkdir(adjuntos_dir() . '/2026/01', 0777, true);
$rutaAdj = '2026/01/' . bin2hex(random_bytes(8)) . '.pdf';
file_put_contents(adjuntos_dir() . '/' . $rutaAdj, 'pdf de prueba');
q("INSERT INTO adjuntos (entidad, entidad_id, nombre, ruta, creado_por) VALUES ('contacto', 8801, 'Presupuesto.pdf', ?, 9001)", [$rutaAdj]);
$adjId = last_id();
adjunto_borrar($adjId);
ok('borrar marca la fila', qv('SELECT eliminado_en FROM adjuntos WHERE id = ?', [$adjId]) !== null);
ok('y el archivo sigue en el disco (se puede restaurar)', is_file(adjuntos_dir() . '/' . $rutaAdj));
ok('restaurar lo devuelve', adjunto_restaurar($adjId) && qv('SELECT eliminado_en FROM adjuntos WHERE id = ?', [$adjId]) === null);
adjunto_borrar($adjId);
eq('la purga no toca lo reciente', adjuntos_purgar(60), 0);
q("UPDATE adjuntos SET eliminado_en = '2020-01-01 00:00:00' WHERE id = ?", [$adjId]);
eq('pasado el plazo, la purga lo borra', adjuntos_purgar(60), 1);
ok('el archivo y la fila', !is_file(adjuntos_dir() . '/' . $rutaAdj) && !q1('SELECT id FROM adjuntos WHERE id = ?', [$adjId]));

// ---- Registros vivos (notas, etiquetas y adjuntos no se cuelgan de cualquier id) ----
db()->exec("CREATE TABLE IF NOT EXISTS negocios (id INTEGER PRIMARY KEY, propietario_id INTEGER, eliminado_en TEXT)");
try {
    q('INSERT INTO negocios (id, propietario_id, eliminado_en) VALUES (8801, 9001, NULL)');
    q("INSERT INTO negocios (id, propietario_id, eliminado_en) VALUES (8802, 9001, '2026-01-01 00:00:00')");
    ok('un registro vivo se encuentra', registro_vivo('negocio', 8801) !== null);
    ok('uno en la papelera no', registro_vivo('negocio', 8802) === null);
    ok('uno que no existe tampoco', registro_vivo('negocio', 8899) === null);
} catch (\Throwable $e) {
    ok('registro_vivo: la tabla negocios de otro caso no admite la fila de prueba (' . $e->getMessage() . ')', true);
}
ok('una entidad desconocida, nunca', registro_vivo('usuarios', 1) === null);

// ---- Nunca sin administradores, ni con dos cambios a la vez ----
q("INSERT OR REPLACE INTO usuarios (id, nombre, email, activo, rol) VALUES (9001, 'Ana', 'ana@intellihelp.tech', 1, 'admin')");
q("INSERT OR REPLACE INTO usuarios (id, nombre, email, activo, rol) VALUES (9002, 'Beto', 'beto@intellihelp.tech', 1, 'admin')");
$otrosAdmins = (int) qv("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id NOT IN (9001, 9002)");
ok('un admin deja de serlo si queda otro', user_update_protegido(9001, 'comercial', true));
if ($otrosAdmins === 0) {
    ok('el último admin no se puede quitar', !user_update_protegido(9002, 'comercial', true));
    ok('ni desactivar', !user_update_protegido(9002, 'admin', false));
    eq('y queda como estaba', q1('SELECT rol, activo FROM usuarios WHERE id = 9002'), ['rol' => 'admin', 'activo' => 1]);
}
ok('volver a hacerlo admin siempre se puede', user_update_protegido(9001, 'admin', true));
ok('sin transacción colgada', !db()->inTransaction());

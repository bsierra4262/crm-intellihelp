<?php
declare(strict_types=1);

// ============================================================
//  Casos con base del cierre (SQLite en memoria de run_tests.php):
//  el aviso «X minutos antes» de las actividades y la limpieza de lo que
//  cuelga de un registro purgado (notas, etiquetas, adjuntos).
//
//  notificar() es el de juguete de run_tests.php: deja los avisos en
//  $GLOBALS['__avisos']. Las variables llevan el prefijo $g.
// ============================================================

if (!function_exists('current_user_id')) {
    function current_user_id(): ?int { return 7; }
}

foreach (['actividades', 'notas', 'etiqueta_registro', 'adjuntos', 'contactos'] as $gt) {
    db()->exec("DROP TABLE IF EXISTS $gt");
}
db()->exec("CREATE TABLE actividades (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL, asunto TEXT NOT NULL,
    propietario_id INTEGER, contacto_id INTEGER, posible_cliente_id INTEGER, cuenta_id INTEGER, negocio_id INTEGER,
    estado TEXT NOT NULL DEFAULT 'No iniciada', prioridad TEXT NOT NULL DEFAULT 'Normal', vence_el TEXT, inicia_el TEXT,
    termina_el TEXT, duracion_min INTEGER, lugar TEXT, sentido TEXT, resultado TEXT, completada_en TEXT,
    recordar_min INTEGER, recordado_en TEXT, gcal_event_id TEXT, meet_url TEXT, descripcion TEXT, creado_por INTEGER,
    creado_en TEXT, actualizado_en TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE contactos (id INTEGER PRIMARY KEY AUTOINCREMENT, apellido TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE notas (id INTEGER PRIMARY KEY AUTOINCREMENT, entidad TEXT, entidad_id INTEGER, cuerpo TEXT)");
db()->exec("CREATE TABLE etiqueta_registro (etiqueta_id INTEGER, entidad TEXT, entidad_id INTEGER)");
db()->exec("CREATE TABLE adjuntos (id INTEGER PRIMARY KEY AUTOINCREMENT, entidad TEXT, entidad_id INTEGER, nombre TEXT,
    ruta TEXT, eliminado_en TEXT)");

$gAhora = strtotime('2031-03-10 10:00:00');
$gF = fn(int $seg) => date('Y-m-d H:i:s', $gAhora + $seg);
$gNueva = function (array $c) {
    $c += ['tipo' => 'reunion', 'asunto' => 'Demo', 'propietario_id' => 7];
    $cols = implode(', ', array_keys($c));
    q("INSERT INTO actividades ($cols) VALUES (" . implode(', ', array_fill(0, count($c), '?')) . ')', array_values($c));
    return last_id();
};

// ---- Aviso «X minutos antes» ----
$gToca    = $gNueva(['inicia_el' => $gF(1800), 'recordar_min' => 60]);            // aviso a las 9:30: toca
$gTodavia = $gNueva(['inicia_el' => $gF(7200), 'recordar_min' => 30]);            // aviso a las 11:30: todavía no
$gSinAv   = $gNueva(['inicia_el' => $gF(600)]);                                   // sin recordar_min
$gHecha   = $gNueva(['inicia_el' => $gF(600), 'recordar_min' => 15, 'completada_en' => $gF(-60)]);
$gBorr    = $gNueva(['inicia_el' => $gF(600), 'recordar_min' => 15, 'eliminado_en' => $gF(-60)]);
$gVieja   = $gNueva(['inicia_el' => $gF(-7200), 'recordar_min' => 15]);           // pasó hace 2 h
$gTarea   = $gNueva(['tipo' => 'tarea', 'vence_el' => $gF(300), 'recordar_min' => 10]);

$GLOBALS['__avisos'] = [];
eq('se avisan la reunión y la tarea que llegaron a su hora', actividades_recordatorios($gAhora), 2);
$gIds = array_map(fn($a) => (int)($a['entidad_id'] ?? 0), $GLOBALS['__avisos']);
sort($gIds);
eq('los avisos son de esas dos actividades', $gIds, [$gToca, $gTarea]);
eq('el aviso le llega al dueño', $GLOBALS['__avisos'][0]['usuario_id'] ?? null, 7);
ok('el aviso lleva a la actividad', str_starts_with((string)($GLOBALS['__avisos'][0]['url'] ?? ''), 'actividad-form?id='));
ok('la tarea dice «Vence»', (bool) array_filter($GLOBALS['__avisos'], fn($a) => str_starts_with((string)($a['detalle'] ?? ''), 'Vence ')));
eq('la vieja se marca sin avisar (no se manda un aviso tardío)',
   qv('SELECT recordado_en IS NOT NULL FROM actividades WHERE id = ?', [$gVieja]), 1);
eq('la que todavía no llegó queda sin marcar', qv('SELECT recordado_en FROM actividades WHERE id = ?', [$gTodavia]), null);
eq('completadas, en la papelera y sin aviso no se tocan',
   (int) qv('SELECT COUNT(*) FROM actividades WHERE id IN (?, ?, ?) AND recordado_en IS NOT NULL', [$gSinAv, $gHecha, $gBorr]), 0);

$GLOBALS['__avisos'] = [];
eq('una segunda corrida no repite avisos', actividades_recordatorios($gAhora + 60), 0);
eq('a su hora sale el aviso de la que faltaba', actividades_recordatorios($gAhora + 5400), 1);

// Editar la fecha vuelve a armar el aviso.
actividad_guardar(['tipo' => 'reunion', 'asunto' => 'Demo', 'inicia_el' => $gF(86400), 'recordar_min' => 60], $gToca);
eq('al mover la reunión, el aviso se vuelve a armar', qv('SELECT recordado_en FROM actividades WHERE id = ?', [$gToca]), null);
q('UPDATE actividades SET recordado_en = ? WHERE id = ?', [$gF(0), $gToca]);
actividad_guardar(['tipo' => 'reunion', 'asunto' => 'Demo con otro asunto', 'inicia_el' => $gF(86400), 'recordar_min' => 60], $gToca);
ok('cambiar sólo el asunto no repite el aviso', qv('SELECT recordado_en FROM actividades WHERE id = ?', [$gToca]) !== null);

// ---- Lo que cuelga de un registro purgado ----
q("INSERT INTO contactos (id, apellido) VALUES (1, 'Vivo'), (2, 'Purgado')");
q("INSERT INTO notas (entidad, entidad_id, cuerpo) VALUES ('contacto', 1, 'queda'), ('contacto', 2, 'se va'), ('cuenta', 2, 'otra entidad')");
q("INSERT INTO etiqueta_registro (etiqueta_id, entidad, entidad_id) VALUES (1, 'contacto', 1), (1, 'contacto', 2)");
q("INSERT INTO adjuntos (entidad, entidad_id, nombre, ruta) VALUES ('contacto', 1, 'a.pdf', 'x/a'), ('contacto', 2, 'b.pdf', 'x/b')");
q('DELETE FROM contactos WHERE id = 2');
purgar_dependientes('contacto', 'contactos');
eq('la nota del contacto purgado se borra; las demás quedan',
   q("SELECT cuerpo FROM notas ORDER BY id")->fetchAll(PDO::FETCH_COLUMN), ['queda', 'otra entidad']);
eq('su etiqueta también', (int) qv("SELECT COUNT(*) FROM etiqueta_registro"), 1);
eq('su adjunto pasa a la papelera (el archivo lo borra adjuntos_purgar)',
   qv("SELECT eliminado_en IS NOT NULL FROM adjuntos WHERE entidad_id = 2"), 1);
eq('el adjunto del contacto vivo no se toca', qv("SELECT eliminado_en FROM adjuntos WHERE entidad_id = 1"), null);

<?php
declare(strict_types=1);

// ============================================================
//  Casos con base: lo que hace el CRM solo con un rebote definitivo y con
//  una respuesta de baja / fallecimiento / «ya no trabaja»
//  (marcar_rebote, respuesta_aplicar_automatica). SQLite en memoria de
//  run_tests.php; notificar() es el de juguete. Prefijo $i.
// ============================================================

foreach (['contactos', 'posibles_clientes', 'desuscripciones', 'envios', 'envio_eventos', 'campanas'] as $it) {
    db()->exec("DROP TABLE IF EXISTS $it");
}
db()->exec("CREATE TABLE contactos (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, apellido TEXT, nombre_completo TEXT,
    email TEXT, email2 TEXT, email3 TEXT, no_email INTEGER DEFAULT 0, email_rebotado INTEGER DEFAULT 0, eliminado_en TEXT)");
db()->exec("CREATE TABLE posibles_clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, email2 TEXT,
    no_email INTEGER DEFAULT 0, email_rebotado INTEGER DEFAULT 0, eliminado_en TEXT)");
db()->exec("CREATE TABLE desuscripciones (email TEXT PRIMARY KEY, motivo TEXT, campana_id INTEGER, detalle TEXT, ip TEXT, creado_en TEXT)");
db()->exec("CREATE TABLE envios (id INTEGER PRIMARY KEY AUTOINCREMENT, campana_id INTEGER, contacto_id INTEGER, email TEXT,
    token TEXT, estado TEXT, motivo TEXT, enviado_en TEXT, rebotado_en TEXT, respondido_en TEXT, abierto_en TEXT, creado_en TEXT)");
db()->exec("CREATE TABLE envio_eventos (id INTEGER PRIMARY KEY AUTOINCREMENT, envio_id INTEGER, tipo TEXT, detalle TEXT, creado_en TEXT)");
db()->exec("CREATE TABLE campanas (id INTEGER PRIMARY KEY, nombre TEXT, propietario_id INTEGER, total INTEGER DEFAULT 0,
    enviados INTEGER DEFAULT 0, rebotados INTEGER DEFAULT 0, respondidos INTEGER DEFAULT 0, desuscriptos INTEGER DEFAULT 0,
    errores INTEGER DEFAULT 0, reuniones INTEGER DEFAULT 0, eliminado_en TEXT)");
q("INSERT INTO campanas (id, nombre, propietario_id) VALUES (70, 'Retomando', 7)");
$iEnv = function (string $email, int $contacto) {
    q("INSERT INTO envios (campana_id, contacto_id, email, token, estado, enviado_en) VALUES (70, ?, ?, ?, 'enviado', ?)",
      [$contacto, $email, bin2hex(random_bytes(16)), date('Y-m-d H:i:s', time() - 3600)]);
    return last_id();
};
$iVivo = fn(int $id) => qv('SELECT eliminado_en IS NULL FROM contactos WHERE id = ?', [$id]);

echo "== Automático · Rebote definitivo ==\n";
q("INSERT INTO contactos (id, nombre, email, email2) VALUES (1, 'Con dos correos', 'malo@x.i', 'bueno@x.i')");
marcar_rebote('malo@x.i', '550 5.1.1 no existe', true);
eq('el contacto se va a la papelera aunque tenga otro correo', $iVivo(1), 0);
eq('la dirección queda en la lista negra', qv("SELECT motivo FROM desuscripciones WHERE email = 'malo@x.i'"), 'rebote_duro');
eq('la otra dirección no se da de baja', qv("SELECT COUNT(*) FROM desuscripciones WHERE email = 'bueno@x.i'"), 0);
q("INSERT INTO contactos (id, nombre, email) VALUES (2, 'Pasajero', 'lleno@x.i')");
marcar_rebote('lleno@x.i', 'Mailbox full', false);
eq('un rebote pasajero no borra a nadie', $iVivo(2), 1);

echo "== Automático · Respuestas ==\n";
q("INSERT INTO contactos (id, nombre, email) VALUES (10, 'Baja', 'baja@x.i'), (11, 'Fallecido', 'info@fallecido.i'),
   (12, 'Compañero', 'colega@fallecido.i'), (13, 'Se fue', 'sefue@y.i'), (14, 'Común', 'comun@z.i')");
$GLOBALS['__avisos'] = [];
eq('BAJA', respuesta_aplicar_automatica(['de' => 'baja@x.i', 'asunto' => 'BAJA', 'texto' => '', 'envio_id' => $iEnv('baja@x.i', 10)]), 'baja');
eq('pidió la baja: a la papelera', $iVivo(10), 0);
eq('y a la lista negra como baja', qv("SELECT motivo FROM desuscripciones WHERE email = 'baja@x.i'"), 'baja');
ok('queda el evento de baja del envío', (int) qv("SELECT COUNT(*) FROM envio_eventos WHERE tipo = 'baja'") === 1);
ok('y el aviso al responsable', ($GLOBALS['__avisos'][0]['usuario_id'] ?? null) === 7);

eq('un compañero avisa que falleció',
   respuesta_aplicar_automatica(['de' => 'colega@fallecido.i', 'asunto' => 'RE: Seguimiento',
       'texto' => 'Les informo que Juan falleció el año pasado.', 'envio_id' => $iEnv('info@fallecido.i', 11)]), 'fallecido');
eq('se va la ficha a la que le escribimos', $iVivo(11), 0);
eq('la del compañero que avisó queda', $iVivo(12), 1);
eq('y su dirección no entra en la lista negra', qv("SELECT COUNT(*) FROM desuscripciones WHERE email = 'colega@fallecido.i'"), 0);

eq('respuesta automática de «ya no trabaja»',
   respuesta_aplicar_automatica(['de' => 'sefue@y.i', 'asunto' => 'Automatic reply',
       'texto' => 'I am no longer with the company.', 'envio_id' => $iEnv('sefue@y.i', 13)]), 'ya_no_trabaja');
eq('a la papelera', $iVivo(13), 0);

eq('una respuesta común no toca nada',
   respuesta_aplicar_automatica(['de' => 'comun@z.i', 'asunto' => 'Re: hola', 'texto' => 'Me interesa, ¿hablamos?',
       'envio_id' => $iEnv('comun@z.i', 14)]), null);
eq('la ficha sigue', $iVivo(14), 1);

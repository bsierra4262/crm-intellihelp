<?php
declare(strict_types=1);

// ============================================================
//  Casos del lote D2 con base (SQLite en memoria de run_tests.php):
//  lectura de la casilla de punta a punta con un servidor IMAP de mentira,
//  guardado de respuestas, contestar desde el CRM con un doble de envío
//  (no sale nada por la red) y alta/reactivación de cuentas de correo.
//
//  Las tablas se crean acá con las columnas que usan estas funciones. Se
//  borran al empezar y al terminar para no chocar con las de otros lotes
//  (los archivos de casos corren uno detrás de otro sobre la misma base).
// ============================================================

$__srcD2 = $SRC ?? (__DIR__ . '/../../app/web/app');
require_once $__srcD2 . '/mensajes.php';
require_once $__srcD2 . '/cuentas_correo.php';
require_once __DIR__ . '/../d2_imap_falso.php';
if (!function_exists('current_user_id')) {
    function current_user_id(): ?int { return 7; }
}

$__tablasD2 = ['imap_estado', 'envios', 'mensajes', 'contactos', 'cuentas_correo', 'campanas', 'usuarios'];
foreach ($__tablasD2 as $__t) { db()->exec("DROP TABLE IF EXISTS $__t"); }
db()->exec("CREATE TABLE imap_estado (buzon TEXT PRIMARY KEY, ultimo_uid INTEGER NOT NULL DEFAULT 0,
            uidvalidity INTEGER, ultima_corrida TEXT)");
db()->exec("CREATE TABLE envios (id INTEGER PRIMARY KEY AUTOINCREMENT, campana_id INTEGER NOT NULL,
            contacto_id INTEGER, email TEXT NOT NULL, estado TEXT NOT NULL DEFAULT 'enviado', enviado_en TEXT,
            message_id TEXT, respondido_en TEXT, rebotado_en TEXT)");
db()->exec("CREATE TABLE mensajes (id INTEGER PRIMARY KEY AUTOINCREMENT, contacto_id INTEGER, envio_id INTEGER,
            campana_id INTEGER, cuenta_correo_id INTEGER, direccion TEXT NOT NULL, de TEXT NOT NULL, de_nombre TEXT,
            para TEXT NOT NULL, asunto TEXT, cuerpo_texto TEXT, cuerpo_html TEXT, message_id TEXT UNIQUE,
            in_reply_to TEXT, referencias TEXT, fecha TEXT NOT NULL, usuario_id INTEGER, leido_en TEXT, creado_en TEXT)");
db()->exec("CREATE TABLE contactos (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, nombre_completo TEXT, eliminado_en TEXT)");
// smtp_pass NOT NULL SIN valor por defecto: así estaba en la base y así
// fallaba el alta sin contraseña en modo estricto.
db()->exec("CREATE TABLE cuentas_correo (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL,
            remitente TEXT NOT NULL UNIQUE, remitente_nombre TEXT, firma_html TEXT, responder_a TEXT,
            smtp_host TEXT NOT NULL DEFAULT 'smtp.gmail.com', smtp_port INTEGER NOT NULL DEFAULT 587,
            smtp_seguridad TEXT NOT NULL DEFAULT 'tls', smtp_user TEXT NOT NULL, smtp_pass TEXT NOT NULL,
            imap_host TEXT, imap_port INTEGER, imap_user TEXT, imap_pass TEXT, limite_diario INTEGER NOT NULL DEFAULT 300,
            usuario_id INTEGER, activo INTEGER NOT NULL DEFAULT 1, predeterminada INTEGER NOT NULL DEFAULT 0,
            ultimo_error TEXT, verificada_en TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE campanas (id INTEGER PRIMARY KEY, cuenta_correo_id INTEGER)");
db()->exec("CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombre TEXT)");

$hace = static fn(int $dias): string => date('Y-m-d H:i:s', time() - $dias * 86400);
q('INSERT INTO contactos (id, email, nombre_completo) VALUES (1, ?, ?)', ['juan@acme.com', 'Juan Pérez']);
q('INSERT INTO envios (id, campana_id, contacto_id, email, enviado_en, message_id) VALUES (10, 3, 1, ?, ?, ?)',
  ['juan@acme.com', $hace(2), '<aa11bb22cc33.1763300000@intellihelp.tech>']);
q('INSERT INTO envios (id, campana_id, contacto_id, email, enviado_en, message_id) VALUES (11, 4, 1, ?, ?, ?)',
  ['juan@acme.com', $hace(1), '<otro-envio.1763300050@intellihelp.tech>']);
q('INSERT INTO envios (id, campana_id, email, enviado_en, message_id) VALUES (12, 3, ?, ?, ?)',
  ['pedro@acme.com', $hace(2), '<bb22cc33.1763300300@intellihelp.tech>']);
q('INSERT INTO envios (id, campana_id, email, enviado_en, message_id) VALUES (13, 3, ?, ?, ?)',
  ['vieja@acme.com', $hace(200), '<viejo.1700000000@intellihelp.tech>']);
q('INSERT INTO envios (id, campana_id, email, enviado_en, message_id) VALUES (14, 5, ?, ?, ?)',
  ['ana@acme.com', $hace(3), '<ana.1763300400@intellihelp.tech>']);

// Las acciones de la casilla se reemplazan por dobles que anotan lo que
// pasaría: la respuesta sí se guarda de verdad con mensaje_entrante().
$rebotes = [];
$respuestas = [];
$acciones = [
    'rebote' => static function (string $d, string $det, bool $duro) use (&$rebotes): void { $rebotes[] = [$d, $duro]; },
    'respuesta' => static function (array $d) use (&$respuestas): bool {
        $id = mensaje_entrante($d);
        if ($id !== null) { $respuestas[] = $d; }
        return $id !== null;
    },
];
$cfg = ['user' => 'ventas@intellihelp.tech', 'pass' => 'x', 'cuenta_id' => 2];
$crlf = static fn(string $s): string => d2_crlf($s);

// Respuesta de Outlook a la campaña 3 (envío 10), aunque después Juan
// recibió otra campaña (envío 11): manda el In-Reply-To.
$respOutlook = $crlf("From: =?iso-8859-1?Q?Juan_P=E9rez?= <juan@acme.com>\nTo: ventas@intellihelp.tech\n"
    . "Subject: =?iso-8859-1?Q?RE:_Propuesta_de_soluci=F3n?=\nDate: Mon, 16 Nov 2026 14:03:11 +0000\n"
    . "Message-ID: <DB7PR02MB4321@outlook.com>\nIn-Reply-To: <aa11bb22cc33.1763300000@intellihelp.tech>\n"
    . "Content-Type: multipart/alternative;\n\tboundary=\"_000_DB7PR02MB_\"\n\n"
    . "--_000_DB7PR02MB_\nContent-Type: text/plain; charset=\"iso-8859-1\"\nContent-Transfer-Encoding: quoted-printable\n\n"
    . "Me interesa la soluci=F3n.\n\n--_000_DB7PR02MB_--\n");
// Un mensaje que hace fallar el guardado: Message-ID repetido de otra forma
// no sirve, así que se fuerza con una acción que lanza (como lanzaba el
// ValueError de un charset desconocido antes del arreglo).
$malo = $crlf("From: Pedro <pedro@acme.com>\nSubject: Re: hola\nMessage-ID: <malo-1@acme.com>\n"
    . "In-Reply-To: <bb22cc33.1763300300@intellihelp.tech>\nContent-Type: text/plain\n\nlanzá\n");
// Rebote SIN DSN de Exim, con la copia del original (Message-ID del envío 12).
$rebote = $crlf("From: Mail Delivery System <Mailer-Daemon@mx.ejemplo.com>\nSubject: Mail delivery failed: returning message to sender\n"
    . "Message-ID: <rebote-1@mx.ejemplo.com>\n\n"
    . "A message that you sent could not be delivered. This is a permanent error. The following address(es) failed:\n\n"
    . "  pedro@acme.com\n    SMTP error from remote mail server after RCPT TO:<pedro@acme.com>:\n"
    . "    550 5.1.1 User unknown\n\n------ This is a copy of the message, including all the headers. ------\n\n"
    . "From: Ventas <ventas@intellihelp.tech>\nTo: pedro@acme.com\nReply-To: juan@acme.com\n"
    . "Message-ID: <bb22cc33.1763300300@intellihelp.tech>\n\nHola\n");
// Correo nuevo (sin cabeceras de hilo) de alguien que recibió una campaña
// hace 200 días: ya no se cuenta como respuesta.
$viejo = $crlf("From: vieja@acme.com\nSubject: Otra cosa\nMessage-ID: <v-1@acme.com>\nContent-Type: text/plain\n\nHola\n");
// Correo sin cabeceras de hilo de alguien con un envío reciente: respaldo
// por dirección.
$sinHilo = $crlf("From: Ana <ana@acme.com>\nSubject: Consulta\nMessage-ID: <ana-1@acme.com>\nContent-Type: text/plain; charset=utf-8\n\nPrecio en C\xC3\xB3rdoba?\n");
// Un spam cualquiera.
$spam = $crlf("From: ofertas@spam.tld\nSubject: Gane dinero\nMessage-ID: <s-1@spam.tld>\n\nCompre\n");

echo "-- Casilla de punta a punta (servidor IMAP de mentira) --\n";
$accionesConFalla = $acciones;
$accionesConFalla['respuesta'] = static function (array $d) use ($acciones): bool {
    if (str_contains((string)$d['texto'], 'lanzá')) { throw new ValueError('mb_convert_encoding(): Argument #3 must be a valid encoding'); }
    return ($acciones['respuesta'])($d);
};
$fp = d2_imap_falso([101 => $respOutlook, 102 => $malo, 103 => $rebote, 104 => $viejo, 105 => $sinHilo, 106 => $spam], 555);
$r = imap_procesar_buzon($fp, $cfg, 'INBOX:ventas@intellihelp.tech', 30, $accionesConFalla);
fclose($fp);
eq('se revisaron los seis', $r['revisados'], 6);
eq('un mensaje que lanza NO traba la casilla: la marca llega al último', imap_ultimo_uid('INBOX:ventas@intellihelp.tech'), 106);
eq('se guardó el UIDVALIDITY', imap_uidvalidity_guardado('INBOX:ventas@intellihelp.tech'), 555);
eq('dos respuestas: Outlook (por In-Reply-To) y Ana (respaldo por dirección)', $r['respuestas'], 2);
$mOut = q1('SELECT * FROM mensajes WHERE message_id = ?', ['<DB7PR02MB4321@outlook.com>']);
eq('la de Outlook va al envío que contestó (10), no al más reciente (11)', (int)($mOut['envio_id'] ?? 0), 10);
eq('y a su campaña', (int)($mOut['campana_id'] ?? 0), 3);
contiene('con el texto decodificado', (string)($mOut['cuerpo_texto'] ?? ''), 'Me interesa la solución.');
eq('anotada en la casilla que la recibió', (int)($mOut['cuenta_correo_id'] ?? 0), 2);
$mAna = q1('SELECT * FROM mensajes WHERE message_id = ?', ['<ana-1@acme.com>']);
eq('sin cabeceras de hilo, el respaldo por dirección encuentra el envío reciente', (int)($mAna['envio_id'] ?? 0), 14);
ok('el correo de alguien con una campaña de hace 200 días no se toma como respuesta',
   !qv('SELECT 1 FROM mensajes WHERE de = ?', ['vieja@acme.com']));
ok('el spam no se guarda', !qv('SELECT 1 FROM mensajes WHERE de = ?', ['ofertas@spam.tld']));
eq('el rebote sin DSN se marca sólo a la dirección del envío (por su Message-ID)', $rebotes, [['pedro@acme.com', true]]);
ok('el Reply-To de la copia del original (juan@) no se marca como rebotado',
   !in_array('juan@acme.com', array_column($rebotes, 0), true));
ok('el mensaje que lanzó quedó registrado y salteado, no guardado', !qv('SELECT 1 FROM mensajes WHERE message_id = ?', ['<malo-1@acme.com>']));

echo "-- Releer no duplica ni vuelve a contar --\n";
$respuestas = []; $rebotes = [];
$fp = d2_imap_falso([101 => $respOutlook, 106 => $spam], 555);
$r = imap_procesar_buzon($fp, $cfg, 'INBOX:ventas@intellihelp.tech', 30, $acciones);
fclose($fp);
eq('con la marca en 106 no se procesa nada viejo', [$r['revisados'], $r['respuestas']], [0, 0]);
ok('el SEARCH pide desde el 107', in_array('UID SEARCH UID 107:*', D2ImapFalso::$comandos, true));

echo "-- UIDVALIDITY: la casilla se renumeró --\n";
$fp = d2_imap_falso([1 => $respOutlook, 2 => $sinHilo], 999);
$r = imap_procesar_buzon($fp, $cfg, 'INBOX:ventas@intellihelp.tech', 30, $acciones);
fclose($fp);
ok('con otro UIDVALIDITY se vuelve a buscar por fecha', (bool) preg_grep('/^UID SEARCH SINCE /', D2ImapFalso::$comandos));
eq('se leen los mensajes renumerados (antes quedaban debajo de la marca para siempre)', $r['revisados'], 2);
eq('lo ya guardado no se duplica', [$r['respuestas'], (int) qv('SELECT COUNT(*) FROM mensajes')], [0, 2]);
eq('la marca queda en el número nuevo', imap_ultimo_uid('INBOX:ventas@intellihelp.tech'), 2);
eq('y el UIDVALIDITY nuevo guardado', imap_uidvalidity_guardado('INBOX:ventas@intellihelp.tech'), 999);

echo "-- Una lectura que falla no avanza la marca --\n";
$fp = d2_imap_falso([3 => $spam, 4 => $sinHilo, 5 => $spam], 999, [4 => true]);
$r = imap_procesar_buzon($fp, $cfg, 'INBOX:ventas@intellihelp.tech', 30, $acciones);
fclose($fp);
eq('se procesa el 3 y se corta en el 4, que falló al leer', imap_ultimo_uid('INBOX:ventas@intellihelp.tech'), 3);

echo "-- Respuesta a una respuesta mandada desde el CRM --\n";
q("INSERT INTO mensajes (envio_id, campana_id, contacto_id, direccion, de, para, asunto, message_id, fecha)
   VALUES (10, 3, 1, 'saliente', 'ventas@intellihelp.tech', 'juan@acme.com', 'Re: x', '<crm-resp-1@intellihelp.tech>', ?)",
  [$hace(1)]);
$cruce = imap_cruzar_respuesta("In-Reply-To: <crm-resp-1@intellihelp.tech>\r\n", 'juan.otro@acme.com');
eq('cruza por el hilo guardado aunque escriba desde otra dirección', [$cruce['envio_id'] ?? null, $cruce['por'] ?? null], [10, 'hilo']);
eq('In-Reply-To desconocido y remitente sin envíos: no es respuesta',
   imap_cruzar_respuesta("In-Reply-To: <nada@x.tld>\r\n", 'nadie@x.tld'), null);

echo "-- mensaje_entrante --\n";
$largo = '<' . str_repeat('a', 300) . '@x.tld>';
$id1 = mensaje_entrante(['de' => 'ana@acme.com', 'para' => 'ventas@intellihelp.tech', 'asunto' => 'x',
    'message_id' => $largo, 'in_reply_to' => $largo, 'fecha' => date('Y-m-d H:i:s')]);
ok('un Message-ID de más de 255 se guarda (con uno estable derivado)', $id1 !== null);
$fila = q1('SELECT message_id, in_reply_to FROM mensajes WHERE id = ?', [(int)$id1]);
ok('y entra en la columna', strlen((string)$fila['message_id']) <= 255 && mb_strlen((string)$fila['in_reply_to']) <= 255);
eq('el mismo otra vez no se duplica', mensaje_entrante(['de' => 'ana@acme.com', 'message_id' => $largo, 'fecha' => date('Y-m-d H:i:s')]), null);

echo "-- conversacion_de: los más recientes --\n";
for ($i = 1; $i <= 5; $i++) {
    q("INSERT INTO mensajes (direccion, de, para, message_id, fecha) VALUES ('entrante', 'larga@acme.com', 'v@x', ?, ?)",
      ['<larga-' . $i . '@x>', date('Y-m-d H:i:s', 1790000000 + $i * 60)]);
}
$conv = conversacion_de('larga@acme.com', 3);
eq('con tope 3 trae los 3 últimos, en orden cronológico', array_column($conv, 'message_id'),
   ['<larga-3@x>', '<larga-4@x>', '<larga-5@x>']);

echo "-- Contestar desde el CRM, de punta a punta (con un doble de envío) --\n";
$GLOBALS['CONFIG']['smtp'] = ['host' => 'smtp.invalid', 'port' => 587, 'user' => '', 'pass' => ''];
q("INSERT INTO cuentas_correo (id, nombre, remitente, smtp_user, smtp_pass, activo, eliminado_en)
   VALUES (2, 'Ventas', 'ventas@intellihelp.tech', 'ventas@intellihelp.tech', ?, 1, NULL)", [cifrar('clave')]);
q("INSERT INTO cuentas_correo (id, nombre, remitente, smtp_user, smtp_pass, activo, eliminado_en, predeterminada)
   VALUES (9, 'Vieja', 'vieja@intellihelp.tech', 'vieja@intellihelp.tech', '', 1, ?, 1)", [$hace(5)]);
$enviados = [];
$doble = static function (array $msg, ?array $cfg, ?string &$err = null) use (&$enviados): string {
    $enviados[] = [$msg, $cfg];
    return 'enviado';
};
$idOut = (int) $mOut['id'];
[$tipo, $texto] = conversacion_responder(['mensaje_id' => $idOut, 'texto' => "Hola Juan,\nte llamo el martes."],
                                         'juan@acme.com', $doble);
eq('la respuesta sale', [$tipo, $texto], ['success', 'Respuesta enviada.']);
eq('se mandó una sola vez, por el doble', count($enviados), 1);
$msgEnv = $enviados[0][0] ?? [];
eq('va al remitente del mensaje', $msgEnv['para'] ?? '', 'juan@acme.com');
eq('encadenada al hilo (In-Reply-To = Message-ID del original)', $msgEnv['in_reply_to'] ?? '', '<DB7PR02MB4321@outlook.com>');
eq('«Re:» una sola vez', $msgEnv['asunto'] ?? '', 'RE: Propuesta de solución');
contiene('el texto del usuario va escapado en el HTML', (string)($msgEnv['html'] ?? ''), 'Hola Juan,<br />');
eq('sale por la casilla que recibió el original', $enviados[0][1]['from'] ?? '', 'ventas@intellihelp.tech');
$sal = q1("SELECT * FROM mensajes WHERE direccion = 'saliente' AND para = 'juan@acme.com' ORDER BY id DESC LIMIT 1");
eq('queda guardada como saliente del mismo envío', [(int)($sal['envio_id'] ?? 0), $sal['message_id'] ?? ''],
   [10, $msgEnv['message_id'] ?? 'x']);
ok('y la conversación queda leída', !qv("SELECT 1 FROM mensajes WHERE de = 'juan@acme.com' AND direccion = 'entrante' AND leido_en IS NULL"));

$enviados = [];
[$tipo, $texto] = conversacion_responder(['mensaje_id' => $idOut, 'texto' => 'x'], 'otra@persona.com', $doble);
ok('un mensaje de OTRA conversación no se puede contestar desde ésta', $tipo === 'error' && !$enviados);
[$tipo, $texto] = conversacion_responder(['mensaje_id' => $idOut, 'texto' => '   '], 'juan@acme.com', $doble);
eq('texto vacío: aviso claro y no se manda nada', [$tipo, count($enviados)], ['error', 0]);
$falla = static function (array $msg, ?array $cfg, ?string &$err = null): string { $err = '550 rechazado'; return 'rechazado'; };
[$tipo, $texto] = conversacion_responder(['mensaje_id' => $idOut, 'texto' => 'hola'], 'juan@acme.com', $falla);
ok('si el envío falla se avisa y no se guarda', $tipo === 'error' && str_contains($texto, 'rechazado'));
$base = static function (array $msg, ?array $cfg, ?string &$err = null): string {
    throw new PDOException("SQLSTATE[HY000]: General error: 2006 MySQL server has gone away (tabla secreta)");
};
[$tipo, $texto] = conversacion_responder(['mensaje_id' => $idOut, 'texto' => 'hola'], 'juan@acme.com', $base);
ok('un error de base no se muestra tal cual', $tipo === 'error' && !str_contains($texto, 'SQLSTATE') && !str_contains($texto, 'secreta'));

// La cuenta de la campaña dada de baja no se usa: se cae a una activa.
q("UPDATE cuentas_correo SET activo = 0, eliminado_en = ? WHERE id = 2", [$hace(1)]);
q("INSERT INTO campanas (id, cuenta_correo_id) VALUES (3, 2)");
$enviados = [];
mensaje_responder($idOut, 'otra', 7, $doble);
ok('una casilla dada de baja no se usa para contestar (ni la de la campaña ni la «predeterminada» borrada)',
   isset($enviados[0]) && $enviados[0][1] === null);
q("UPDATE cuentas_correo SET activo = 1, eliminado_en = NULL WHERE id = 2");

echo "-- Cuentas de correo: alta sin contraseña y remitente dado de baja --\n";
$base0 = ['nombre' => 'RRHH', 'remitente' => 'rrhh@intellihelp.tech', 'smtp_user' => 'rrhh@intellihelp.tech', 'activo' => '1'];
$lanzo = null;
try { $idR = cuenta_correo_guardar($base0); } catch (\Throwable $e) { $lanzo = $e; }
ok('el alta sin contraseña funciona (smtp_pass NOT NULL)', $lanzo === null && ($idR ?? 0) > 0);
eq('queda con contraseña vacía', qv('SELECT smtp_pass FROM cuentas_correo WHERE id = ?', [$idR ?? 0]), '');
cuenta_correo_guardar($base0 + ['smtp_pass' => 'abcd efgh ijkl mnop'], $idR);
$cifrada = (string) qv('SELECT smtp_pass FROM cuentas_correo WHERE id = ?', [$idR]);
eq('la contraseña se guarda cifrada', descifrar($cifrada), 'abcd efgh ijkl mnop');
cuenta_correo_eliminar($idR);
$lanzo = null;
try { $idR2 = cuenta_correo_guardar(['nombre' => 'RRHH nueva'] + $base0); } catch (\Throwable $e) { $lanzo = $e; }
ok('volver a cargar una casilla dada de baja no da error', $lanzo === null);
eq('la reactiva: misma fila', $idR2 ?? 0, $idR);
$filaR = q1('SELECT * FROM cuentas_correo WHERE id = ?', [$idR]);
eq('viva y con el nombre nuevo', [$filaR['eliminado_en'], $filaR['nombre']], [null, 'RRHH nueva']);
eq('conserva la contraseña que tenía', descifrar((string)$filaR['smtp_pass']), 'abcd efgh ijkl mnop');
$lanzo = null;
try { cuenta_correo_guardar(['nombre' => 'Otra'] + $base0); } catch (\Throwable $e) { $lanzo = $e; }
ok('una dirección de otra cuenta viva da un error claro, no un 500', $lanzo instanceof CuentaCorreoRepetida);
$lanzo = null;
try { cuenta_correo_guardar(['remitente' => 'rrhh@intellihelp.tech'] + $base0, 2); } catch (\Throwable $e) { $lanzo = $e; }
ok('editar otra cuenta para ponerle esa dirección, también', $lanzo instanceof CuentaCorreoRepetida);

foreach ($__tablasD2 as $__t) { db()->exec("DROP TABLE IF EXISTS $__t"); }
unset($GLOBALS['CONFIG']['smtp']);

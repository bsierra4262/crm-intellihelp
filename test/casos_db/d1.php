<?php
declare(strict_types=1);

// ============================================================
//  Casos del lote D1 (campañas, bajas y seguimiento) contra la SQLite en
//  memoria de run_tests.php. Nada de esto manda correos: sólo se prueban las
//  escrituras y lecturas de la base.
//
//  Las tablas se crean con IF NOT EXISTS y después se le agregan las
//  columnas que falten (otra área pudo haber creado la misma tabla con menos
//  columnas). Los correos de prueba llevan el sufijo «.d1» para no cruzarse
//  con los datos de otras áreas.
// ============================================================

$tablaD1 = static function (string $tabla, array $cols): void {
    db()->exec("CREATE TABLE IF NOT EXISTS $tabla (id INTEGER PRIMARY KEY AUTOINCREMENT)");
    foreach ($cols as $col => $tipo) {
        try { db()->exec("ALTER TABLE $tabla ADD COLUMN $col $tipo"); } catch (\Throwable $e) { /* ya estaba */ }
    }
};
$tablaD1('contactos', ['email' => 'TEXT', 'email2' => 'TEXT', 'email3' => 'TEXT', 'cargo' => 'TEXT',
                       'no_email' => 'INTEGER DEFAULT 0', 'email_rebotado' => 'INTEGER DEFAULT 0',
                       'eliminado_en' => 'TEXT', 'nombre_completo' => 'TEXT', 'ultima_actividad_en' => 'TEXT']);
$tablaD1('posibles_clientes', ['email' => 'TEXT', 'email2' => 'TEXT', 'cargo' => 'TEXT',
                               'no_email' => 'INTEGER DEFAULT 0', 'email_rebotado' => 'INTEGER DEFAULT 0',
                               'eliminado_en' => 'TEXT']);
$tablaD1('campanas', ['nombre' => 'TEXT', 'desuscriptos' => 'INTEGER DEFAULT 0', 'eliminado_en' => 'TEXT']);
$tablaD1('envios', ['campana_id' => 'INTEGER', 'contacto_id' => 'INTEGER', 'posible_cliente_id' => 'INTEGER',
                    'email' => 'TEXT', 'nombre' => 'TEXT', 'empresa' => 'TEXT', 'estado' => "TEXT DEFAULT 'pendiente'",
                    'motivo' => 'TEXT', 'token' => 'TEXT', 'enviado_en' => 'TEXT', 'rebotado_en' => 'TEXT',
                    'creado_en' => 'TEXT']);
$tablaD1('envio_eventos', ['envio_id' => 'INTEGER', 'tipo' => 'TEXT', 'detalle' => 'TEXT', 'ip' => 'TEXT']);
db()->exec("CREATE TABLE IF NOT EXISTS desuscripciones (email TEXT PRIMARY KEY, motivo TEXT, campana_id INTEGER,
            detalle TEXT, ip TEXT, creado_en TEXT)");

echo "== D1 · La baja no se cuenta dos veces y guarda la IP con hash ==\n";
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
q("INSERT INTO campanas (id, nombre, desuscriptos) VALUES (901, 'Campaña D1', 0)");
q("INSERT INTO contactos (email) VALUES ('baja@ejemplo.d1')");
ok('la primera baja es nueva', desuscribir('Baja@Ejemplo.d1', 'baja', 901, 'desde el enlace') === true);
ok('la segunda (el un clic de Gmail más el botón) no', desuscribir('baja@ejemplo.d1', 'baja', 901, 'otra vez') === false);
eq('el contador de la campaña sumó una sola', (int) qv('SELECT desuscriptos FROM campanas WHERE id = 901'), 1);
eq('una sola fila en la lista negra', (int) qv("SELECT COUNT(*) FROM desuscripciones WHERE email = 'baja@ejemplo.d1'"), 1);
$ipGuardada = (string) qv("SELECT ip FROM desuscripciones WHERE email = 'baja@ejemplo.d1'");
ok('la IP no se guarda en claro', $ipGuardada !== '' && !str_contains($ipGuardada, '198.51.100.23'));
eq('se guarda el hash de la IP confiable', $ipGuardada, ip_hash('198.51.100.23'));
eq('el contacto queda «no incluir en campañas»', (int) qv("SELECT no_email FROM contactos WHERE email = 'baja@ejemplo.d1'"), 1);

echo "== D1 · El enlace de baja funciona aunque la campaña se haya purgado ==\n";
$tok = str_repeat('a1', 16);
q("INSERT INTO envios (campana_id, email, token, estado) VALUES (901, 'vivo@ejemplo.d1', ?, 'enviado')", [$tok]);
eq('token de la cola: se encuentra', baja_buscar_token($tok)['email'] ?? null, 'vivo@ejemplo.d1');
$tokPurgado = str_repeat('b2', 16);
eq('sin la tabla de tokens (antes de la migración 015) no explota: no se encuentra', baja_buscar_token($tokPurgado), null);
db()->exec("CREATE TABLE IF NOT EXISTS bajas_tokens (token TEXT PRIMARY KEY, email TEXT NOT NULL, campana_id INTEGER, creado_en TEXT)");
q("INSERT INTO bajas_tokens (token, email, campana_id) VALUES (?, 'purgado@ejemplo.d1', 77)", [$tokPurgado]);
$b = baja_buscar_token($tokPurgado);
ok('con la migración, el token de una campaña purgada se encuentra', ($b['email'] ?? null) === 'purgado@ejemplo.d1' && $b['id'] === null);
ok('y la baja se puede hacer (la campaña ya no existe)', desuscribir($b['email'], 'baja', (int)$b['campana_id']) === true);
eq('un token mal copiado no encuentra nada', baja_buscar_token('abc'), null);

echo "== D1 · Un rebote marca sólo el último envío a ese correo ==\n";
q("INSERT INTO envios (campana_id, email, token, estado, enviado_en, creado_en) VALUES (901, 'rebota@ejemplo.d1', ?, 'enviado', ?, ?)",
  [str_repeat('c3', 16), date('Y-m-d H:i:s', time() - 30 * 86400), date('Y-m-d H:i:s', time() - 30 * 86400)]);
q("INSERT INTO envios (campana_id, email, token, estado, enviado_en, creado_en) VALUES (902, 'rebota@ejemplo.d1', ?, 'enviado', ?, ?)",
  [str_repeat('d4', 16), date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 3600)]);
marcar_rebote('rebota@ejemplo.d1', 'Mailbox full', false);
eq('el envío de hace una hora queda rebotado', qv("SELECT estado FROM envios WHERE campana_id = 902 AND email = 'rebota@ejemplo.d1'"), 'rebotado');
eq('el de una campaña de hace un mes, que sí llegó, no se toca',
   qv("SELECT estado FROM envios WHERE campana_id = 901 AND email = 'rebota@ejemplo.d1'"), 'enviado');

echo "== D1 · El mensaje real escapa los datos del destinatario y llena {{cargo}} ==\n";
q("INSERT INTO contactos (id, email, cargo) VALUES (9101, 'raro@ejemplo.d1', 'Gerente <b>IT</b>')");
$campD1 = ['id' => 901, 'propietario_id' => 0, 'asunto' => 'Hola {{nombre}}',
           'cuerpo_html' => '<p>{{nombre}} de {{empresa}}, {{cargo}}</p><a href="{{baja}}">Baja</a>', 'cuerpo_texto' => ''];
$envD1 = ['id' => 1, 'token' => str_repeat('e5', 16), 'email' => 'raro@ejemplo.d1', 'contacto_id' => 9101,
          'nombre' => '<img src=x onerror=alert(1)> Pérez', 'empresa' => '"><script>alert(1)</script>'];
$msgD1 = campana_armar_mensaje($campD1, $envD1);
no_contiene('el HTML no lleva la etiqueta del nombre', $msgD1['html'], '<img src=x');
no_contiene('ni el script de la empresa', $msgD1['html'], '<script>');
contiene('el cargo sale de la ficha, escapado', $msgD1['html'], 'Gerente &lt;b&gt;IT&lt;/b&gt;');
eq('el pie de baja no se duplica cuando la plantilla ya usó {{baja}}', substr_count($msgD1['html'], 'baja?t=' . str_repeat('e5', 16)), 1);
eq('el asunto va sin escapar (es texto, no HTML)', $msgD1['asunto'], 'Hola <img');

echo "== D1 · Lista blanca del modo prueba ==\n";
q("INSERT INTO usuarios (nombre, email, activo) VALUES ('Vendedora', 'Vendedora@IntelliHelp.d1', 1)");
q("INSERT INTO usuarios (nombre, email, activo) VALUES ('Ex', 'ex@intellihelp.d1', 0)");
$GLOBALS['__ajustes']['testing_lista_blanca'] = 'qa@gmail.d1, otro@x.d1';
$lb = campana_lista_blanca();
ok('incluye a los usuarios activos', isset($lb['vendedora@intellihelp.d1']));
ok('no a los inactivos', !isset($lb['ex@intellihelp.d1']));
ok('y los del ajuste', isset($lb['qa@gmail.d1']) && isset($lb['otro@x.d1']));
ok('un cliente queda afuera en prueba', !campana_destino_permitido('cliente@empresa.d1', true));
unset($GLOBALS['__ajustes']['testing_lista_blanca']);
contiene('el correo real lleva el píxel de apertura', $msgD1['html'], 'px?t=' . str_repeat('e5', 16));
no_contiene('la vista previa del CRM no (mirarla no es abrirla)',
            campana_armar_mensaje($campD1, $envD1, null, false)['html'], 'px?t=');

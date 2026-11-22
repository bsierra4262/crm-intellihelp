<?php
declare(strict_types=1);

// ============================================================
//  Casos del lote B1 contra la base SQLite en memoria de run_tests.php:
//  negocios (embudo propio, fecha de cierre, probabilidad, mover de etapa,
//  Kanban con etapas apagadas, totales por moneda, pronóstico), buscador de
//  los desplegables grandes, importación de Zoho (codificación, propietario y
//  empresa en la reimportación, papelera, correo secundario, empresas por
//  clave, traza en import_lotes), carga a una lista y arreglos de crm.php.
//
//  Crea sus tablas al empezar y las BORRA al terminar (DROP), porque los
//  casos de otras áreas crean las mismas con CREATE TABLE sin IF NOT EXISTS.
//  En `usuarios` (la crea run_tests.php) agrega los ids 901 a 903 y los saca
//  al final.
// ============================================================

$SRC = getenv('CRM_SRC') ?: (__DIR__ . '/../../app/web/app');
require_once $SRC . '/negocios.php';
require_once $SRC . '/importador.php';

if (!function_exists('current_user_id')) {
    /** Quien «está usando» el sistema en estas pruebas. */
    function current_user_id(): ?int { return $GLOBALS['__uid_b1'] ?? 901; }
}

$b1Tablas = ['cuentas', 'contactos', 'embudos', 'etapas', 'negocios', 'negocio_etapas', 'campanas',
             'import_lotes', 'listas', 'lista_miembros', 'cuotas'];
foreach ($b1Tablas as $t) { db()->exec("DROP TABLE IF EXISTS $t"); }
db()->exec("CREATE TABLE IF NOT EXISTS usuarios (id INTEGER PRIMARY KEY, nombre TEXT, email TEXT, activo INTEGER DEFAULT 1)");
db()->exec("CREATE TABLE cuentas (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, propietario_id INTEGER,
    tipo TEXT, sector TEXT, sitio_web TEXT, telefono TEXT, email TEXT, cuit TEXT, condicion_iva TEXT, empleados INTEGER,
    calle TEXT, localidad TEXT, provincia TEXT, codigo_postal TEXT, pais TEXT, linkedin TEXT, descripcion TEXT,
    zoho_id TEXT UNIQUE, creado_por INTEGER, pendiente_norm INTEGER DEFAULT 0, aprobado_en TEXT, creado_en TEXT,
    eliminado_en TEXT)");
db()->exec("CREATE TABLE contactos (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, apellido TEXT NOT NULL,
    nombre_completo TEXT GENERATED ALWAYS AS (TRIM(COALESCE(nombre, '') || ' ' || apellido)) VIRTUAL,
    cuenta_id INTEGER, propietario_id INTEGER, cargo TEXT, departamento TEXT, email TEXT, email2 TEXT, email3 TEXT,
    telefono TEXT, movil TEXT, conmutador TEXT, fuente TEXT, linkedin TEXT, sitio_web TEXT, calle TEXT, localidad TEXT,
    provincia TEXT, codigo_postal TEXT, pais TEXT, descripcion TEXT, no_email INTEGER NOT NULL DEFAULT 0,
    email_rebotado INTEGER NOT NULL DEFAULT 0, zoho_id TEXT UNIQUE, creado_por INTEGER, pendiente_norm INTEGER DEFAULT 0,
    aprobado_en TEXT, creado_en TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE embudos (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL UNIQUE, activo INTEGER NOT NULL DEFAULT 1,
    predeterminado INTEGER NOT NULL DEFAULT 0, orden INTEGER NOT NULL DEFAULT 0)");
db()->exec("CREATE TABLE etapas (id INTEGER PRIMARY KEY AUTOINCREMENT, embudo_id INTEGER NOT NULL, nombre TEXT NOT NULL,
    probabilidad INTEGER NOT NULL DEFAULT 0, tipo TEXT NOT NULL DEFAULT 'abierta', color TEXT NOT NULL DEFAULT '#663399',
    orden INTEGER NOT NULL DEFAULT 0, activo INTEGER NOT NULL DEFAULT 1)");
db()->exec("CREATE TABLE negocios (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, cuenta_id INTEGER, contacto_id INTEGER,
    propietario_id INTEGER, embudo_id INTEGER NOT NULL, etapa_id INTEGER NOT NULL, importe REAL NOT NULL DEFAULT 0,
    moneda TEXT NOT NULL DEFAULT 'ARS', probabilidad INTEGER NOT NULL DEFAULT 0,
    ingreso_esperado REAL GENERATED ALWAYS AS (ROUND(importe * probabilidad / 100.0, 2)) VIRTUAL,
    fecha_cierre TEXT, cerrado_en TEXT, motivo_perdida TEXT, tipo TEXT, fuente TEXT, campana_id INTEGER, siguiente_paso TEXT,
    descripcion TEXT, zoho_id TEXT, creado_por INTEGER, creado_en TEXT DEFAULT CURRENT_TIMESTAMP, eliminado_en TEXT)");
db()->exec("CREATE TABLE negocio_etapas (id INTEGER PRIMARY KEY AUTOINCREMENT, negocio_id INTEGER NOT NULL, etapa_id INTEGER NOT NULL,
    desde TEXT DEFAULT CURRENT_TIMESTAMP, hasta TEXT, usuario_id INTEGER)");
db()->exec("CREATE TABLE campanas (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, estado TEXT DEFAULT 'borrador',
    reuniones INTEGER NOT NULL DEFAULT 0, creado_en TEXT DEFAULT CURRENT_TIMESTAMP, eliminado_en TEXT)");
db()->exec("CREATE TABLE import_lotes (id INTEGER PRIMARY KEY AUTOINCREMENT, origen TEXT NOT NULL, modulo TEXT NOT NULL, archivo TEXT,
    filas INTEGER DEFAULT 0, creados INTEGER DEFAULT 0, actualizados INTEGER DEFAULT 0, omitidos INTEGER DEFAULT 0,
    errores INTEGER DEFAULT 0, resumen TEXT, creado_por INTEGER, creado_en TEXT DEFAULT CURRENT_TIMESTAMP)");
db()->exec("CREATE TABLE listas (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, eliminado_en TEXT)");
db()->exec("CREATE TABLE lista_miembros (id INTEGER PRIMARY KEY AUTOINCREMENT, lista_id INTEGER NOT NULL, contacto_id INTEGER,
    posible_cliente_id INTEGER, email TEXT NOT NULL, nombre TEXT, empresa TEXT, UNIQUE (lista_id, email))");
db()->exec("CREATE TABLE cuotas (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL, periodo TEXT NOT NULL,
    objetivo REAL NOT NULL DEFAULT 0, moneda TEXT NOT NULL DEFAULT 'ARS', UNIQUE (usuario_id, periodo))");

try {
    q("DELETE FROM usuarios WHERE id IN (901, 902, 903)");
    q("INSERT INTO usuarios (id, nombre, email, activo) VALUES (901, 'Brian Sierra', 'b@x.test', 1),
       (902, 'Ana Gómez', 'a@x.test', 1), (903, 'Baja Vieja', 'v@x.test', 0)");

    // ---- Embudos: el predeterminado y otro ----
    q("INSERT INTO embudos (id, nombre, predeterminado, orden) VALUES (1, 'Ventas', 1, 1), (2, 'Licitaciones', 0, 2)");
    q("INSERT INTO etapas (id, embudo_id, nombre, probabilidad, tipo, orden, activo) VALUES
        (1, 1, 'Contacto', 10, 'abierta', 1, 1), (2, 1, 'Propuesta', 50, 'abierta', 2, 1),
        (3, 1, 'Ganado', 100, 'ganada', 3, 1), (4, 1, 'Perdido', 0, 'perdida', 4, 1),
        (5, 2, 'Pliego', 20, 'abierta', 1, 1), (6, 2, 'Adjudicado', 100, 'ganada', 2, 1),
        (7, 2, 'Vieja', 30, 'abierta', 3, 0)");

    echo "-- Negocio de otro embudo --\n";
    $nid = negocio_guardar(['nombre' => 'Licitación hospital', 'etapa_id' => 5, 'importe' => '1000', 'moneda' => 'USD']);
    $n = negocio_get($nid);
    eq('el alta queda en el embudo de su etapa', [(int)$n['embudo_id'], (int)$n['etapa_id']], [2, 5]);
    eq('con la probabilidad de la etapa', (int)$n['probabilidad'], 20);
    $ops = array_map(fn($e) => (int)$e['id'], etapas_para_negocio(2, 5));
    eq('el formulario ofrece las etapas de SU embudo', $ops, [5, 6]);
    negocio_guardar(['nombre' => 'Licitación hospital (editado)', 'etapa_id' => 5, 'probabilidad' => '20',
                     'importe' => '1000', 'moneda' => 'USD'], $nid);
    $n = negocio_get($nid);
    eq('editar sin tocar la etapa no lo muda de embudo', [(int)$n['embudo_id'], (int)$n['etapa_id']], [2, 5]);
    eq('ni abre un tramo nuevo', (int) qv('SELECT COUNT(*) FROM negocio_etapas WHERE negocio_id = ?', [$nid]), 1);

    echo "-- Probabilidad que sigue a la etapa --\n";
    negocio_guardar(['nombre' => 'Licitación hospital', 'etapa_id' => 6, 'probabilidad' => '20', 'importe' => '1000',
                     'moneda' => 'USD'], $nid);
    eq('cambia la etapa y nadie tocó la probabilidad: toma la nueva', (int) negocio_get($nid)['probabilidad'], 100);
    negocio_guardar(['nombre' => 'Licitación hospital', 'etapa_id' => 5, 'probabilidad' => '35', 'importe' => '1000',
                     'moneda' => 'USD'], $nid);
    eq('si la cambian a mano, queda la de mano', (int) negocio_get($nid)['probabilidad'], 35);

    echo "-- Fecha de cierre --\n";
    $g = negocio_guardar(['nombre' => 'Ganado en marzo', 'etapa_id' => 3, 'importe' => '500']);
    q("UPDATE negocios SET cerrado_en = '2026-03-10 11:00:00' WHERE id = ?", [$g]);
    negocio_guardar(['nombre' => 'Ganado en marzo', 'etapa_id' => 3, 'probabilidad' => '100', 'importe' => '500',
                     'descripcion' => 'Corrijo la descripción'], $g);
    eq('corregir un negocio ganado no cambia su fecha de cierre', negocio_get($g)['cerrado_en'], '2026-03-10 11:00:00');
    negocio_guardar(['nombre' => 'Ganado en marzo', 'etapa_id' => 1, 'probabilidad' => '100', 'importe' => '500'], $g);
    eq('reabierto: sin fecha de cierre', negocio_get($g)['cerrado_en'], null);

    echo "-- Etapa desactivada --\n";
    q("UPDATE negocios SET etapa_id = 7, embudo_id = 2 WHERE id = ?", [$nid]);
    $ops = etapas_para_negocio(2, 7);
    ok('la etapa actual apagada sigue en la lista, marcada', in_array(7, array_map(fn($e) => (int)$e['id'], $ops), true)
        && !empty(array_values(array_filter($ops, fn($e) => (int)$e['id'] === 7))[0]['inactiva']));
    negocio_guardar(['nombre' => 'Licitación hospital', 'etapa_id' => 7, 'probabilidad' => '35', 'importe' => '1000',
                     'moneda' => 'USD'], $nid);
    eq('guardar sin tocar la deja en su etapa apagada', (int) negocio_get($nid)['etapa_id'], 7);
    $cols = embudo_kanban(2);
    ok('el Kanban muestra la etapa apagada que tiene negocios', isset($cols[7]) && !empty($cols[7]['etapa']['inactiva']));
    eq('el total de la columna va por moneda', $cols[7]['total'], ['ARS' => 0.0, 'USD' => 1000.0]);
    $sinUso = embudo_kanban(1);
    ok('una etapa apagada sin negocios no aparece', !isset($sinUso[7]));

    echo "-- Mover de etapa --\n";
    $m = negocio_guardar(['nombre' => 'A mover', 'etapa_id' => 1, 'importe' => '100']);
    $tramos = fn() => (int) qv('SELECT COUNT(*) FROM negocio_etapas WHERE negocio_id = ?', [$m]);
    eq('a la misma etapa no hace nada', negocio_mover($m, 1), false);
    eq('ni abre un tramo', $tramos(), 1);
    $err = null;
    try { negocio_mover($m, 5); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
    ok('una etapa de otro embudo se rechaza con un mensaje claro', $err !== null && str_contains($err, 'embudo'));
    q("UPDATE etapas SET activo = 0 WHERE id = 2");
    $err = null;
    try { negocio_mover($m, 2); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
    ok('una etapa desactivada se rechaza', $err !== null && str_contains($err, 'desactivada'));
    q("UPDATE etapas SET activo = 1 WHERE id = 2");
    negocio_mover($m, 4, 'Precio');
    $n = negocio_get($m);
    eq('perdido: guarda el motivo y la probabilidad de la etapa', [$n['motivo_perdida'], (int)$n['probabilidad']], ['Precio', 0]);
    ok('y pone fecha de cierre', $n['cerrado_en'] !== null);
    negocio_mover($m, 2);
    $n = negocio_get($m);
    eq('reabierto: se borra el motivo de pérdida y la fecha de cierre', [$n['motivo_perdida'], $n['cerrado_en']], [null, null]);
    eq('cada movimiento real abre un tramo', $tramos(), 3);
    eq('y deja un solo tramo abierto', (int) qv('SELECT COUNT(*) FROM negocio_etapas WHERE negocio_id = ? AND hasta IS NULL', [$m]), 1);

    echo "-- Totales por moneda --\n";
    $l = negocios_listar(['estado' => 'todos']);
    ok('el listado suma pesos y dólares por separado',
       abs($l['suma']['importe']['USD'] - 1000) < 0.01 && abs($l['suma']['importe']['ARS'] - 600) < 0.01);
    q("UPDATE negocios SET propietario_id = 902, fecha_cierre = '2026-11-15' WHERE id IN (?, ?)", [$nid, $g]);
    q("UPDATE negocios SET etapa_id = 6 WHERE id = ?", [$nid]);
    q("UPDATE negocios SET etapa_id = 3 WHERE id = ?", [$g]);
    $pr = array_values(array_filter(pronostico('2026-11'), fn($r) => (int)$r['id'] === 902))[0] ?? [];
    eq('el pronóstico separa lo ganado en pesos y en dólares', [(float)($pr['ganado'] ?? -1), (float)($pr['ganado_usd'] ?? -1)], [500.0, 1000.0]);
    eq('un período imposible devuelve vacío en vez de romper', pronostico('2026-13'), []);

    echo "-- Vínculos que no existen --\n";
    q("INSERT INTO cuentas (id, nombre) VALUES (50, 'Viva SA')");
    q("INSERT INTO cuentas (id, nombre, eliminado_en) VALUES (51, 'Borrada SA', '2026-01-01')");
    eq('empresa inexistente: error de formulario, no 500', array_keys(negocio_validar_vinculos(['cuenta_id' => 999])), ['cuenta_id']);
    eq('empresa en la papelera: también', array_keys(negocio_validar_vinculos(['cuenta_id' => 51])), ['cuenta_id']);
    eq('salvo que ya fuera la del negocio', negocio_validar_vinculos(['cuenta_id' => 51], ['cuenta_id' => 51]), []);
    eq('una viva pasa', negocio_validar_vinculos(['cuenta_id' => 50, 'moneda' => 'USD']), []);
    eq('moneda fuera de lista', array_keys(negocio_validar_vinculos(['moneda' => 'EURO'])), ['moneda']);

    echo "-- Buscador de los desplegables --\n";
    q("INSERT INTO contactos (id, nombre, apellido, email, cuenta_id) VALUES (70, 'Juan Carlos', 'Pérez', 'jcp@viva.test', 50),
        (71, 'Juana', 'Pérez', 'juana@otra.test', NULL), (72, 'Juan', 'Borrado', 'jb@x.test', NULL)");
    q("UPDATE contactos SET eliminado_en = '2026-01-01' WHERE id = 72");
    $r = buscar_registros('contacto', 'juan pérez');
    eq('varias palabras: cada una en algún campo', array_map(fn($x) => $x['id'], $r), [70, 71]);
    eq('el detalle trae la empresa y el correo', $r[0]['detalle'], 'Viva SA · jcp@viva.test');
    eq('no trae los de la papelera', array_column(buscar_registros('contacto', 'borrado'), 'id'), []);
    eq('menos de 2 letras no busca', buscar_registros('contacto', 'j'), []);
    eq('un tipo desconocido no busca', buscar_registros('usuarios', 'brian'), []);
    eq('los comodines de LIKE se buscan literales', buscar_registros('cuenta', '%%'), []);
    eq('la opción actual se dibuja aunque esté en la papelera', buscar_opcion_actual('cuenta', 51)[0]['nombre'], 'Borrada SA (en la papelera)');
    eq('sin vínculo, ninguna opción', buscar_opcion_actual('cuenta', ''), []);

    echo "-- crm.php: empleados, recorte y sitio web --\n";
    $avisosB1 = [];
    set_error_handler(static function (int $n, string $msg) use (&$avisosB1): bool { $avisosB1[] = $msg; return true; });
    $cid = cuenta_guardar(['nombre' => 'Sin empleados SRL']);
    restore_error_handler();
    eq('sin la clave empleados: null y sin avisos de PHP', [qv('SELECT empleados FROM cuentas WHERE id = ?', [$cid]), $avisosB1], [null, []]);
    $ct = contacto_guardar(['apellido' => 'Largo', 'cargo' => str_repeat('x', 300), 'codigo_postal' => str_repeat('9', 40)]);
    eq('el cargo largo entra cortado en 150', mb_strlen((string) qv('SELECT cargo FROM contactos WHERE id = ?', [$ct])), 150);
    eq('el código postal en 30', strlen((string) qv('SELECT codigo_postal FROM contactos WHERE id = ?', [$ct])), 30);
    q("INSERT INTO cuentas (id, nombre, sitio_web) VALUES (60, 'Not Acme', 'https://www.notacme.com')");
    q("INSERT INTO cuentas (id, nombre, sitio_web) VALUES (61, 'Dominio Propio', 'https://www.dominiopropio.com/inicio')");
    $web = cuenta_asegurar_web('Acme Nueva', 'ana@acme.com', 901);
    ok('acme.com no se pega a la empresa de notacme.com', $web !== 60);
    eq('pero sí a la que tiene ese dominio exacto', cuenta_asegurar_web('Otro nombre', 'ana@dominiopropio.com', 901), 61);

    echo "-- Importación de Zoho --\n";
    $dir = sys_get_temp_dir();
    $csv = static function (string $contenido) use ($dir): string {
        $p = tempnam($dir, 'b1csv');
        file_put_contents($p, $contenido);
        return $p;
    };
    // Windows-1252, separado por punto y coma, como lo guarda Excel en castellano.
    $cab1252 = "ID de registro;Nombre;Apellidos;Nombre de Cuenta;T\xEDtulo;Correo electr\xF3nico;M\xF3vil;Propietario de Contacto;Hora de creaci\xF3n\r\n";
    $f1 = $csv($cab1252
        . "zcrm_1;Mar\xEDa;Mu\xF1oz;ACME S.A.;Gerente de Compras;maria@acme.test;11 5555-0001;Ana G\xF3mez;13/02/2024 09:30\r\n"
        . "zcrm_2;Jos\xE9;L\xF3pez;Acme SA;Analista;jose@acme.test;;Nadie;\r\n");
    $sim = importar_contactos_zoho($f1, true, 901);
    eq('simulación: dos altas y una sola empresa nueva', [$sim['creados'], $sim['cuentas_nuevas']], [2, 1]);
    eq('detecta Windows-1252', $sim['codificacion'], 'Windows-1252');
    eq('la simulación no escribe nada', (int) qv("SELECT COUNT(*) FROM contactos WHERE zoho_id LIKE 'zcrm_%'"), 0);
    $r1 = importar_contactos_zoho($f1, false, 901, 'contactos_zoho.csv');
    eq('importa las dos filas', [$r1['creados'], $r1['errores']], [2, 0]);
    $maria = q1("SELECT * FROM contactos WHERE zoho_id = 'zcrm_1'");
    eq('los acentos se guardan bien y se reconoce «Título» y «Móvil»',
       [$maria['nombre'], $maria['apellido'], $maria['cargo'], $maria['movil'] !== null], ['María', 'Muñoz', 'Gerente de Compras', true]);
    eq('el correo (columna con tilde) se reconoce', $maria['email'], 'maria@acme.test');
    eq('la fecha día/mes se respeta', substr((string)$maria['creado_en'], 0, 10), '2024-02-13');
    eq('propietario por nombre del archivo', (int)$maria['propietario_id'], 902);
    $jose = q1("SELECT * FROM contactos WHERE zoho_id = 'zcrm_2'");
    eq('«ACME S.A.» y «Acme SA» son la misma empresa', (int)$jose['cuenta_id'], (int)$maria['cuenta_id']);
    eq('propietario desconocido: el de por defecto', (int)$jose['propietario_id'], 901);
    $lote = q1("SELECT * FROM import_lotes WHERE modulo = 'contactos' ORDER BY id DESC LIMIT 1");
    eq('la traza guarda el nombre original y queda terminada', [$lote['archivo'], importar_estado_lote($lote), (int)$lote['creados']],
       ['contactos_zoho.csv', 'terminada', 2]);

    // Reimportación: otro propietario y otra empresa en el archivo.
    q("UPDATE contactos SET propietario_id = 901 WHERE id = ?", [(int)$maria['id']]);
    $f2 = $csv("ID de registro,Nombre,Apellidos,Nombre de Cuenta,Correo electrónico,Propietario de Contacto,Teléfono Directo\n"
        . "zcrm_1,María,Muñoz,Otra Empresa,maria@acme.test,Ana Gómez,11 4444-0000\n");
    $r2 = importar_contactos_zoho($f2, false, 902);
    $maria2 = q1('SELECT * FROM contactos WHERE id = ?', [(int)$maria['id']]);
    eq('reimportar actualiza, no duplica', [$r2['actualizados'], $r2['creados']], [1, 0]);
    eq('no le cambia el propietario', (int)$maria2['propietario_id'], 901);
    eq('ni la empresa', (int)$maria2['cuenta_id'], (int)$maria['cuenta_id']);
    ok('pero completa lo que faltaba', $maria2['telefono'] !== null && $maria2['telefono'] !== '');

    // Papelera, correo secundario y caída a correo cuando el ID no está.
    q("INSERT INTO contactos (nombre, apellido, email, eliminado_en) VALUES ('En', 'Papelera', 'papelera@x.test', '2026-01-01')");
    q("INSERT INTO contactos (nombre, apellido, email, email2, fuente) VALUES ('Web', 'Persona', 'web@x.test', 'segundo@x.test', 'Sitio web')");
    $webId = (int) qv("SELECT id FROM contactos WHERE email = 'web@x.test'");
    $f3 = $csv("ID de registro,Nombre,Apellidos,Correo electrónico\n"
        . "zcrm_9,En,Papelera,papelera@x.test\n"
        . "zcrm_10,Web,Persona,segundo@x.test\n"
        . "zcrm_11,Sin,Correo,no-es-un-correo\n");
    $r3 = importar_contactos_zoho($f3, false, 901);
    eq('el de la papelera se omite (no se actualiza ni se duplica)', $r3['omitidos'], 1);
    eq('el que llegó por la web se encuentra por su correo secundario', [$r3['actualizados'], (int) qv("SELECT COUNT(*) FROM contactos WHERE apellido = 'Persona'")], [1, 1]);
    eq('y queda con el ID de Zoho para la próxima', qv('SELECT zoho_id FROM contactos WHERE id = ?', [$webId]), 'zcrm_10');
    eq('sin pisarle la fuente', qv('SELECT fuente FROM contactos WHERE id = ?', [$webId]), 'Sitio web');
    ok('el correo inválido queda anotado en los avisos', !empty($r3['avisos']));
    ok('y el motivo de la papelera en lo omitido', (bool) array_filter(array_keys($r3['detalle_omitidos']), fn($k) => str_contains($k, 'papelera')));

    // Simulación con filas repetidas en el mismo archivo.
    $f4 = $csv("Nombre,Apellidos,Correo electrónico\nRepe,Tido,repe@x.test\nRepe,Tido,repe@x.test\n");
    $s4 = importar_contactos_zoho($f4, true, 901);
    eq('simulación: la fila repetida cuenta como actualización', [$s4['creados'], $s4['actualizados']], [1, 1]);
    // UTF-8 con BOM: el primer encabezado se reconoce.
    $f5 = $csv("\xEF\xBB\xBFApellidos,Nombre\nConBom,Uno\n");
    $r5 = importar_contactos_zoho($f5, false, 901);
    eq('UTF-8 con BOM: el primer encabezado se reconoce', [$r5['creados'], (int) qv("SELECT COUNT(*) FROM contactos WHERE apellido = 'ConBom'")], [1, 1]);

    echo "-- Carga a una lista --\n";
    q("INSERT INTO listas (id, nombre) VALUES (1, 'Newsletter')");
    q("INSERT INTO contactos (nombre, apellido, email, no_email) VALUES ('No', 'Quiere', 'noquiere@x.test', 1)");
    $err = null;
    try { importar_lista_csv($f1, 999); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
    ok('una lista que no existe se rechaza', $err !== null);
    $fl = $csv("Nombre,Apellidos,Correo\nA,B,ok@x.test\nNo,Quiere,noquiere@x.test\nC,D,malo@\nA,B,ok@x.test\n");
    $rl = importar_lista_csv($fl, 1, 'lista.csv');
    eq('agrega sólo el que corresponde', [$rl['agregados'], $rl['omitidos']], [1, 3]);
    ok('no suma a quien pidió no recibir campañas', !qv("SELECT 1 FROM lista_miembros WHERE email = 'noquiere@x.test'"));
    eq('deja su fila en import_lotes', (int) qv("SELECT COUNT(*) FROM import_lotes WHERE modulo = 'lista' AND archivo = 'lista.csv'"), 1);

    foreach ([$f1, $f2, $f3, $f4, $f5, $fl] as $p) { @unlink($p); }
} finally {
    foreach ($b1Tablas as $t) { db()->exec("DROP TABLE IF EXISTS $t"); }
    q("DELETE FROM usuarios WHERE id IN (901, 902, 903)");
}

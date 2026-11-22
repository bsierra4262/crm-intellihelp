<?php
declare(strict_types=1);

// ============================================================
//  Casos con base del lote B2 (SQLite en memoria de run_tests.php):
//  conversión de posibles clientes, actividades y empresas repetidas.
//
//  Las tablas del área se tiran y se vuelven a crear acá con todas las
//  columnas que usan las funciones probadas: otro archivo de casos que corra
//  antes pudo haberlas creado con menos columnas. Las variables llevan el
//  prefijo $b2 para no pisar las de run_tests.php.
// ============================================================

if (!function_exists('current_user_id')) {
    // auth.php no se carga en esta batería: el usuario «de la sesión» es éste.
    function current_user_id(): ?int { return $GLOBALS['__b2_uid'] ?? null; }
}
$GLOBALS['__b2_uid'] = 7;

foreach (['cuentas', 'contactos', 'posibles_clientes', 'actividades', 'negocios', 'etapas', 'embudos',
          'negocio_etapas', 'notas', 'adjuntos', 'etiqueta_registro'] as $b2t) {
    db()->exec("DROP TABLE IF EXISTS $b2t");
}
db()->exec("CREATE TABLE IF NOT EXISTS usuarios (id INTEGER PRIMARY KEY, nombre TEXT, email TEXT, activo INTEGER DEFAULT 1)");
db()->exec("INSERT OR IGNORE INTO usuarios (id, nombre, email, activo) VALUES (7, 'Comercial Siete', 'siete@intellihelp.tech', 1)");
db()->exec("CREATE TABLE cuentas (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, propietario_id INTEGER,
    tipo TEXT, sector TEXT, sitio_web TEXT, telefono TEXT, email TEXT, cuit TEXT, condicion_iva TEXT, empleados INTEGER,
    calle TEXT, localidad TEXT, provincia TEXT, codigo_postal TEXT, pais TEXT, linkedin TEXT, descripcion TEXT,
    zoho_id TEXT, creado_por INTEGER, pendiente_norm INTEGER NOT NULL DEFAULT 0, aprobado_en TEXT, aprobado_por INTEGER,
    creado_en TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE contactos (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, apellido TEXT NOT NULL,
    nombre_completo TEXT GENERATED ALWAYS AS (TRIM(COALESCE(nombre, '') || ' ' || apellido)) VIRTUAL,
    cuenta_id INTEGER, propietario_id INTEGER, cargo TEXT, departamento TEXT, email TEXT, email2 TEXT, email3 TEXT,
    telefono TEXT, movil TEXT, conmutador TEXT, fuente TEXT, linkedin TEXT, sitio_web TEXT, calle TEXT, localidad TEXT,
    provincia TEXT, codigo_postal TEXT, pais TEXT, descripcion TEXT, no_email INTEGER NOT NULL DEFAULT 0,
    email_rebotado INTEGER NOT NULL DEFAULT 0, ultima_actividad_en TEXT, creado_por INTEGER,
    pendiente_norm INTEGER NOT NULL DEFAULT 0, aprobado_en TEXT, creado_en TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE posibles_clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, apellido TEXT NOT NULL,
    empresa TEXT NOT NULL DEFAULT '', cargo TEXT, propietario_id INTEGER, email TEXT, email2 TEXT, telefono TEXT, movil TEXT,
    conmutador TEXT, sitio_web TEXT, linkedin TEXT, fuente TEXT, estado TEXT NOT NULL DEFAULT 'Sin contactar',
    calificacion TEXT, sector TEXT, empleados INTEGER, puntaje INTEGER NOT NULL DEFAULT 0, calle TEXT, localidad TEXT,
    provincia TEXT, codigo_postal TEXT, pais TEXT, descripcion TEXT, no_email INTEGER NOT NULL DEFAULT 0,
    email_rebotado INTEGER NOT NULL DEFAULT 0, ultima_actividad_en TEXT, convertido_en TEXT, convertido_por INTEGER,
    cuenta_id INTEGER, contacto_id INTEGER, negocio_id INTEGER, creado_por INTEGER, creado_en TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE actividades (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL, asunto TEXT NOT NULL,
    propietario_id INTEGER, contacto_id INTEGER, posible_cliente_id INTEGER, cuenta_id INTEGER, negocio_id INTEGER,
    estado TEXT NOT NULL DEFAULT 'No iniciada', prioridad TEXT NOT NULL DEFAULT 'Normal', vence_el TEXT, inicia_el TEXT,
    termina_el TEXT, duracion_min INTEGER, lugar TEXT, sentido TEXT, resultado TEXT, completada_en TEXT,
    recordar_min INTEGER, recordado_en TEXT, gcal_event_id TEXT, meet_url TEXT, descripcion TEXT, creado_por INTEGER,
    creado_en TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE embudos (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, predeterminado INTEGER NOT NULL DEFAULT 0)");
db()->exec("CREATE TABLE etapas (id INTEGER PRIMARY KEY AUTOINCREMENT, embudo_id INTEGER, nombre TEXT, orden INTEGER,
    probabilidad INTEGER DEFAULT 0, activo INTEGER DEFAULT 1, tipo TEXT DEFAULT 'abierta', color TEXT)");
db()->exec("CREATE TABLE negocios (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, cuenta_id INTEGER,
    contacto_id INTEGER, propietario_id INTEGER, embudo_id INTEGER NOT NULL, etapa_id INTEGER NOT NULL,
    importe REAL NOT NULL DEFAULT 0, moneda TEXT NOT NULL DEFAULT 'ARS', probabilidad INTEGER NOT NULL DEFAULT 0,
    fecha_cierre TEXT, fuente TEXT, creado_por INTEGER, creado_en TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE negocio_etapas (id INTEGER PRIMARY KEY AUTOINCREMENT, negocio_id INTEGER, etapa_id INTEGER, usuario_id INTEGER)");
db()->exec("CREATE TABLE notas (id INTEGER PRIMARY KEY AUTOINCREMENT, entidad TEXT, entidad_id INTEGER, titulo TEXT,
    cuerpo TEXT, creado_por INTEGER, creado_en TEXT, eliminado_en TEXT)");
db()->exec("CREATE TABLE adjuntos (id INTEGER PRIMARY KEY AUTOINCREMENT, entidad TEXT, entidad_id INTEGER, nombre TEXT)");
db()->exec("CREATE TABLE etiqueta_registro (etiqueta_id INTEGER NOT NULL, entidad TEXT NOT NULL, entidad_id INTEGER NOT NULL,
    PRIMARY KEY (etiqueta_id, entidad, entidad_id))");

$b2Embudo = (int) (function () { q("INSERT INTO embudos (nombre, predeterminado) VALUES ('Ventas', 1)"); return last_id(); })();
q("INSERT INTO etapas (embudo_id, nombre, orden, probabilidad, activo) VALUES (?, 'Calificación', 1, 10, 1)", [$b2Embudo]);
$b2Etapa = last_id();

/** Un posible cliente con valores por defecto; $extra pisa lo que haga falta. */
$b2Posible = static function (array $extra = []): int {
    $d = $extra + ['nombre' => 'Juana', 'apellido' => 'Prueba', 'empresa' => 'Acme S.A.', 'email' => 'juana@acme.test',
                   'estado' => 'Contactado', 'propietario_id' => 7, 'fuente' => 'Sitio web'];
    $cols = implode(', ', array_keys($d));
    q("INSERT INTO posibles_clientes ($cols) VALUES (" . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
    return last_id();
};
$b2Cuenta = static fn(string $sql, array $p = []) => q1($sql, $p);

// ---- Conversión completa: empresa, contacto, negocio y lo que cuelga ----
$b2P1 = $b2Posible(['calificacion' => 'Caliente', 'sector' => 'Salud', 'empleados' => 50, 'telefono' => '1155554444']);
q("INSERT INTO notas (entidad, entidad_id, cuerpo) VALUES ('posible_cliente', ?, 'Llamar el lunes')", [$b2P1]);
q("INSERT INTO adjuntos (entidad, entidad_id, nombre) VALUES ('posible_cliente', ?, 'propuesta.pdf')", [$b2P1]);
q("INSERT INTO etiqueta_registro (etiqueta_id, entidad, entidad_id) VALUES (3, 'posible_cliente', ?)", [$b2P1]);
q("INSERT INTO actividades (tipo, asunto, posible_cliente_id) VALUES ('tarea', 'Mandar propuesta', ?)", [$b2P1]);
$b2R = posible_convertir($b2P1, ['crear_negocio' => '1', 'negocio_importe' => '1.234,56', 'negocio_moneda' => 'USD',
                                 'negocio_cierre' => '2026-12-31']);
$b2Neg = q1('SELECT * FROM negocios WHERE id = ?', [(int)$b2R['negocio_id']]);
eq('el importe «1.234,56» se guarda 1234,56 (no 1,234)', [(float)$b2Neg['importe'], $b2Neg['moneda']], [1234.56, 'USD']);
$b2Cu = q1('SELECT * FROM cuentas WHERE id = ?', [(int)$b2R['cuenta_id']]);
eq('la empresa nueva recibe sector y empleados', [$b2Cu['nombre'], $b2Cu['sector'], (int)$b2Cu['empleados']], ['Acme S.A.', 'Salud', 50]);
eq('el resultado dice qué se creó', [$b2R['cuenta_nueva'], $b2R['contacto_nuevo'], $b2R['negocio_omitido']], [true, true, false]);
$b2Pc = q1('SELECT * FROM posibles_clientes WHERE id = ?', [$b2P1]);
eq('el posible cliente conserva su estado y queda marcado',
   [$b2Pc['estado'], $b2Pc['convertido_en'] !== null, (int)$b2Pc['contacto_id']], ['Contactado', true, (int)$b2R['contacto_id']]);
eq('la etiqueta pasa al contacto',
   (int) qv("SELECT COUNT(*) FROM etiqueta_registro WHERE entidad = 'contacto' AND entidad_id = ?", [(int)$b2R['contacto_id']]), 1);
eq('notas, adjuntos y actividades se mudan',
   [(int) qv("SELECT COUNT(*) FROM notas WHERE entidad = 'contacto' AND entidad_id = ? AND titulo IS NULL", [(int)$b2R['contacto_id']]),
    (int) qv("SELECT COUNT(*) FROM adjuntos WHERE entidad = 'contacto' AND entidad_id = ?", [(int)$b2R['contacto_id']]),
    (int) qv('SELECT COUNT(*) FROM actividades WHERE contacto_id = ? AND posible_cliente_id IS NULL', [(int)$b2R['contacto_id']])],
   [1, 1, 1]);
$b2Nota = (string) qv("SELECT cuerpo FROM notas WHERE entidad = 'contacto' AND entidad_id = ? AND titulo = 'Datos del posible cliente'",
                      [(int)$b2R['contacto_id']]);
contiene('la calificación queda escrita en una nota del contacto', $b2Nota, 'Calificación: Caliente');

// ---- Doble conversión ----
$b2Antes = [(int) qv('SELECT COUNT(*) FROM cuentas'), (int) qv('SELECT COUNT(*) FROM contactos'), (int) qv('SELECT COUNT(*) FROM negocios')];
$b2Cod = 0;
try { posible_convertir($b2P1, ['crear_negocio' => '1']); } catch (RuntimeException $e) { $b2Cod = $e->getCode(); }
eq('convertir dos veces responde 409', $b2Cod, 409);
eq('y no duplica empresa, contacto ni negocio',
   [(int) qv('SELECT COUNT(*) FROM cuentas'), (int) qv('SELECT COUNT(*) FROM contactos'), (int) qv('SELECT COUNT(*) FROM negocios')], $b2Antes);

// ---- Contacto que ya existía: se completa, no se pisa ----
q("INSERT INTO cuentas (nombre) VALUES ('Otra Empresa SRL')");
$b2Otra = last_id();
q("INSERT INTO contactos (nombre, apellido, email, cargo, cuenta_id) VALUES ('Pedro', 'Existente', 'pedro@beta.test', 'Jefe', ?)", [$b2Otra]);
$b2Ct = last_id();
$b2P2 = $b2Posible(['nombre' => 'Pedro', 'apellido' => 'Existente', 'empresa' => 'Beta SRL', 'email' => 'pedro@beta.test',
                    'cargo' => 'Gerente', 'telefono' => '1144443333', 'no_email' => 1, 'descripcion' => 'Viene de la feria']);
$b2R2 = posible_convertir($b2P2, []);
$b2C2 = q1('SELECT * FROM contactos WHERE id = ?', [$b2Ct]);
eq('se reutiliza el contacto por correo', [$b2R2['contacto_id'], $b2R2['contacto_nuevo']], [$b2Ct, false]);
eq('lo vacío se completa y lo cargado no se pisa', [$b2C2['telefono'], $b2C2['cargo'], $b2C2['descripcion']],
   ['1144443333', 'Jefe', 'Viene de la feria']);
eq('conserva su empresa y suma la baja de campañas', [(int)$b2C2['cuenta_id'], (int)$b2C2['no_email']], [$b2Otra, 1]);

// ---- Sin embudo, sin empresa y con datos inválidos ----
q('UPDATE embudos SET predeterminado = 0');
$b2P3 = $b2Posible(['empresa' => '', 'email' => 'sola@gmail.test']);
$b2R3 = posible_convertir($b2P3, ['crear_negocio' => '1', 'negocio_importe' => '100']);
eq('sin embudo predeterminado el negocio no se crea y se avisa', [$b2R3['negocio_id'], $b2R3['negocio_omitido']], [null, true]);
eq('sin empresa el contacto queda suelto', [$b2R3['cuenta_id'], (int) qv('SELECT COUNT(*) FROM contactos WHERE id = ? AND cuenta_id IS NULL', [$b2R3['contacto_id']])], [null, 1]);
contiene('y el mensaje no dice que se creó una empresa', conversion_mensaje($b2R3), 'quedó sin empresa');
q('UPDATE embudos SET predeterminado = 1');
$b2P4 = $b2Posible(['email' => 'otro@acme.test']);
$b2Inv = false;
try { posible_convertir($b2P4, ['crear_negocio' => '1', 'negocio_moneda' => 'EUR']); } catch (InvalidArgumentException $e) { $b2Inv = true; }
ok('una moneda inválida no convierte nada', $b2Inv && qv('SELECT convertido_en FROM posibles_clientes WHERE id = ?', [$b2P4]) === null);

// ---- Actividades: completar, reabrir y editar ----
q("INSERT INTO contactos (apellido) VALUES ('Activo')");
$b2CtA = last_id();
q("INSERT INTO actividades (tipo, asunto, contacto_id) VALUES ('llamada', 'Llamar', ?)", [$b2CtA]);
$b2A = last_id();
ok('completar devuelve true', actividad_completar($b2A, true));
ok('completar marca el último contacto', qv('SELECT ultima_actividad_en FROM contactos WHERE id = ?', [$b2CtA]) !== null);
q('UPDATE contactos SET ultima_actividad_en = NULL WHERE id = ?', [$b2CtA]);
actividad_completar($b2A, false);
eq('reabrir NO toca el último contacto', qv('SELECT ultima_actividad_en FROM contactos WHERE id = ?', [$b2CtA]), null);
ok('una actividad que no existe no se completa', !actividad_completar(999999, true));
q("INSERT INTO actividades (tipo, asunto, eliminado_en) VALUES ('tarea', 'Borrada', '2026-01-01 00:00:00')");
ok('ni una que está en la papelera', !actividad_completar(last_id(), true));

q("INSERT INTO actividades (tipo, asunto, estado, completada_en) VALUES ('tarea', 'Hecha', 'Completada', '2026-01-05 09:00:00')");
$b2H = last_id();
actividad_guardar(['tipo' => 'tarea', 'asunto' => 'Hecha (editada)', 'estado' => 'Completada', 'propietario_id' => 7], $b2H);
eq('editar una completada conserva cuándo se completó', qv('SELECT completada_en FROM actividades WHERE id = ?', [$b2H]), '2026-01-05 09:00:00');

// ---- Actividades: validación del formulario ----
[$b2D, $b2E] = actividad_validar(['asunto' => 'Reunión', 'tipo' => 'reunion', 'inicia_el' => '2026-11-22T10:00',
                                  'termina_el' => '2026-11-22T11:00', 'estado' => 'En curso', 'prioridad' => 'Alta',
                                  'contacto_id' => (string)$b2CtA, 'propietario_id' => '7']);
eq('lo válido pasa y la fecha queda en formato de base', [$b2E, $b2D['inicia_el']], [[], '2026-11-22 10:00:00']);
[, $b2E] = actividad_validar(['asunto' => 'x', 'tipo' => 'reunion', 'inicia_el' => '2026-11-22T10:00', 'termina_el' => '2026-11-22T09:00']);
ok('una reunión que termina antes de empezar no pasa', isset($b2E['termina_el']));
[, $b2E] = actividad_validar(['asunto' => 'x', 'vence_el' => '2026-02-30T10:00', 'estado' => 'Inventado', 'contacto_id' => '999999']);
ok('fecha imposible, estado fuera de lista e id inexistente: errores, no un 500',
   isset($b2E['vence_el'], $b2E['estado'], $b2E['contacto_id']));

// ---- Empresas repetidas con otra forma societaria ----
q("INSERT INTO cuentas (nombre) VALUES ('Gamma Servicios S.A.')");
$b2G = last_id();
eq('«Gamma Servicios SA» es la misma empresa', (int)(cuenta_repetida('Gamma Servicios SA')['id'] ?? 0), $b2G);
eq('editando esa misma empresa no choca consigo', cuenta_repetida('Gamma Servicios SA', $b2G), null);
eq('con el nombre vacío no se busca', cuenta_repetida('  '), null);

// ---- Papelera: de quién es cada registro ----
eq('el propietario de un contacto', registro_propietario('contacto', $b2Ct), null);
eq('el de un posible cliente', registro_propietario('posible_cliente', $b2P1), 7);
eq('las listas no tienen propietario', registro_propietario('lista', 1), null);

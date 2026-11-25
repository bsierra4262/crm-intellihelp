<?php
// ============================================================
//  Casos del lote E2: base de datos, cron, migraciones, reinicio.
//
//  Sin base: se incluyen cron.php, reinicio.php y migrar.php sólo por sus
//  funciones (cada uno se corta antes de conectar si encuentra su constante
//  *_SOLO_FUNCIONES), y se lee el texto de schema.sql y de las migraciones.
//  Las herramientas en Python (deploy.py, unificar_listas.py) tienen sus
//  propias pruebas en test/herramientas_test.py.
// ============================================================

// Local: <repo>/app. En el servidor (deploy.py test) CRM_SRC es <webroot>/app
// y cron.php, reinicio.php y migrar.php viven en <webroot>; app/db no se sube,
// así que los controles de schema y migraciones se saltean ahí.
$__raizApp = getenv('CRM_SRC') ? dirname((string) getenv('CRM_SRC')) : dirname(__DIR__, 2) . '/app';
if (!defined('CRON_SOLO_FUNCIONES'))     { define('CRON_SOLO_FUNCIONES', true); }
if (!defined('REINICIO_SOLO_FUNCIONES')) { define('REINICIO_SOLO_FUNCIONES', true); }
if (!defined('MIGRAR_SOLO_FUNCIONES'))   { define('MIGRAR_SOLO_FUNCIONES', true); }
require_once $__raizApp . '/cron.php';
require_once $__raizApp . '/reinicio.php';
require_once $__raizApp . '/migrar.php';

// ---- reinicio.php: sólo la instalación de pruebas ----
// El escenario del hallazgo: la copia de pruebas sin su crm-pruebas.php cae a
// la config.php de producción, y producción (por error) dice 'testing'.
ok('reinicio: carpeta crm-pruebas con la config de producción NO se reinicia',
   reinicio_permitido('crm-pruebas', '/home/u/crm_config/config.php', 'u566451014_crm', 'testing') !== null);
eq('reinicio: carpeta crm-pruebas con su propia config, en testing, sí',
   reinicio_permitido('crm-pruebas', '/home/u/crm_config/crm-pruebas.php', 'u566451014_crm_pruebas', 'testing'), null);
eq('reinicio: una base *_pruebas en otra carpeta (local), sí',
   reinicio_permitido('web', '/repo/app/server-config/config.php', 'crm_pruebas', 'testing'), null);
ok('reinicio: la carpeta de producción no, aunque diga testing',
   reinicio_permitido('crm', '/home/u/crm_config/config.php', 'u566451014_crm', 'testing') !== null);
ok('reinicio: sin la fila app_entorno se trata como producción',
   reinicio_permitido('crm-pruebas', '/home/u/crm_config/crm-pruebas.php', 'u566451014_crm_pruebas', null) !== null);
ok('reinicio: en producción no, aunque sea la carpeta de pruebas',
   reinicio_permitido('crm-pruebas', '/home/u/crm_config/crm-pruebas.php', 'u566451014_crm_pruebas', 'produccion') !== null);

// ---- reinicio.php: confirmación explícita ----
ok('reinicio: --confirmar con el nombre exacto de la base confirma',
   reinicio_confirmado(['--hacerlo', '--confirmar=crm_pruebas'], 'crm_pruebas', null));
ok('reinicio: --confirmar con otro nombre no',
   !reinicio_confirmado(['--hacerlo', '--confirmar=u566451014_crm'], 'crm_pruebas', null));
ok('reinicio: sin --confirmar y sin teclado no se confirma',
   !reinicio_confirmado(['--hacerlo'], 'crm_pruebas', null));
ok('reinicio: escribir el nombre de la base confirma',
   reinicio_confirmado(['--hacerlo'], 'crm_pruebas', static fn() => "crm_pruebas\n"));
ok('reinicio: escribir «si» no alcanza',
   !reinicio_confirmado(['--hacerlo'], 'crm_pruebas', static fn() => "si\n"));

// ---- reinicio.php: datos personales fuera del código ----
$__fuenteReinicio = (string) file_get_contents($__raizApp . '/reinicio.php');
no_contiene('reinicio: el Gmail personal ya no está en el código', $__fuenteReinicio, 'bsierra4262');
no_contiene('reinicio: ni el teléfono personal', $__fuenteReinicio, '50274827');
no_contiene('reinicio: ni el de la línea interna', $__fuenteReinicio, '77006868');
$__cfgViejo = $GLOBALS['CONFIG']['pruebas'] ?? null;
$GLOBALS['CONFIG']['pruebas'] = ['correo_externo' => ' alguien@ejemplo.com '];
eq('reinicio: el correo externo sale de la config', reinicio_dato('correo_externo'), 'alguien@ejemplo.com');
eq('reinicio: un dato que no está devuelve el valor por defecto', reinicio_dato('telefono_interno', 'x'), 'x');
if ($__cfgViejo === null) { unset($GLOBALS['CONFIG']['pruebas']); } else { $GLOBALS['CONFIG']['pruebas'] = $__cfgViejo; }

// ---- reinicio.php: sólo se borran archivos de la carpeta de adjuntos ----
eq('reinicio: ruta de adjunto válida', reinicio_ruta_adjunto('/s/adjuntos/', '2026/05/' . str_repeat('a', 32) . '.pdf'),
   '/s/adjuntos/2026/05/' . str_repeat('a', 32) . '.pdf');
eq('reinicio: una ruta con ../ no se toca', reinicio_ruta_adjunto('/s/adjuntos', '../../crm_config/config.php'), null);

// ---- cron.php ----
ok('cron: sin clave configurada se niega siempre', !cron_clave_valida('', ''));
ok('cron: la clave correcta pasa', cron_clave_valida('clave-larga-123', 'clave-larga-123'));
ok('cron: otra clave no', !cron_clave_valida('clave-larga-123', 'clave-larga-124'));
$__ahora = 1_800_000_000;
ok('cron: el primer intento del día toca', cron_toca_diario('2026-11-21', '', '2026-11-22', $__ahora));
ok('cron: ya hecho hoy, no toca', !cron_toca_diario('2026-11-22', '', '2026-11-22', $__ahora));
ok('cron: falló hace 10 minutos, todavía no se reintenta',
   !cron_toca_diario('2026-11-21', (string) ($__ahora - 600), '2026-11-22', $__ahora));
ok('cron: falló hace más de una hora, se reintenta hoy mismo',
   cron_toca_diario('2026-11-21', (string) ($__ahora - 3700), '2026-11-22', $__ahora));
$__fuenteCron = (string) file_get_contents($__raizApp . '/cron.php');
contiene('cron: el bloqueo lleva la base adelante', $__fuenteCron, "GET_LOCK(CONCAT(DATABASE(), ':cron'), 0)");
no_contiene('cron: ya no hay bloqueo con nombre fijo', $__fuenteCron, "GET_LOCK('crm_cron'");
no_contiene('cron: la clave no se compara con !==', $__fuenteCron, "!== \$clave");
ok('cron: la marca de la purga se escribe después de purgar',
   strpos($__fuenteCron, "purgar_papelera();") < strpos($__fuenteCron, "setting_set('papelera_ultima_purga', \$hoy)"));
// Cada catch que registra un error con log_app() reabre antes la conexión:
// si la que se cayó fue ella, el registro del error fallaría también.
preg_match_all('/catch \(\\\\Throwable \$e\) \{(.*?)\n\s*\}/s', $__fuenteCron, $__catches);
$__sinReanudar = 0; $__conLog = 0;
foreach ($__catches[1] as $__c) {
    if (!str_contains($__c, 'log_app(')) { continue; }
    $__conLog++;
    $__pos = strpos($__c, 'db_reanudar()');
    if ($__pos === false || $__pos > strpos($__c, 'log_app(')) { $__sinReanudar++; }
}
ok('cron: todos los catch reabren la conexión antes de registrar el error', $__conLog >= 8 && $__sinReanudar === 0);

// ---- schema.sql trae todas las migraciones ----
// Una instalación nueva hecha sólo con schema.sql tiene que quedar igual que
// una migrada: cada tabla, columna, índice y ajuste que agrega una migración
// tiene que estar también en schema.sql (y lo que una migración saca, no).
if (!is_file($__raizApp . '/db/schema.sql')) {
    echo "  (schema y migraciones: salteado, app/db no está en esta copia)
";
} else {
$__schema = (string) file_get_contents($__raizApp . '/db/schema.sql');
$__bloques = [];
foreach (migrar_sentencias($__schema) as $__s) {
    if (preg_match('/^CREATE TABLE IF NOT EXISTS `(\w+)`/i', $__s, $__m)) { $__bloques[$__m[1]] = $__s; }
}
// 40 hasta la 009 + la 018; la 010 suma notificacion_lecturas, la 015 bajas_tokens y la 020 ithub_sesiones_cerradas.
eq('schema: crea las 43 tablas', count($__bloques), 43);
$__faltan = [];
// Índices que alguna migración saca después (la 018 saca los de la 006): no
// tienen que estar en schema.sql.
$__sacados = [];
foreach (glob($__raizApp . '/db/migracion-*.sql') as $__mig) {
    preg_match_all('/DROP INDEX (?:IF EXISTS )?`(\w+)`/i', (string) file_get_contents($__mig), $__d);
    $__sacados = array_merge($__sacados, $__d[1]);
}
foreach (glob($__raizApp . '/db/migracion-*.sql') as $__mig) {
    $__n = basename($__mig);
    foreach (migrar_sentencias((string) file_get_contents($__mig)) as $__s) {
        if (preg_match('/^CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $__s, $__m) && !isset($__bloques[$__m[1]])) {
            $__faltan[] = "$__n: tabla {$__m[1]}";
        }
        // Las ALTER, también las que la 002 arma adentro de un texto.
        if (preg_match_all('/ALTER TABLE `(\w+)`(.*?)(?:\'|$)/s', $__s, $__alts, PREG_SET_ORDER)) {
            foreach ($__alts as [, $__t, $__cuerpo]) {
                $__def = $__bloques[$__t] ?? '';
                preg_match_all('/ADD COLUMN (?:IF NOT EXISTS )?`(\w+)`/i', $__cuerpo, $__c);
                foreach ($__c[1] as $__col) {
                    if (!preg_match('/^\s*`' . $__col . '`/m', $__def)) { $__faltan[] = "$__n: $__t.$__col"; }
                }
                preg_match_all('/ADD (?:UNIQUE )?INDEX (?:IF NOT EXISTS )?`(\w+)`/i', $__cuerpo, $__i);
                foreach (array_diff($__i[1], $__sacados) as $__ix) {
                    if (!str_contains($__def, "KEY `$__ix`")) { $__faltan[] = "$__n: índice $__t.$__ix"; }
                }
                preg_match_all('/DROP INDEX (?:IF EXISTS )?`(\w+)`/i', $__cuerpo, $__d);
                foreach ($__d[1] as $__ix) {
                    if (str_contains($__def, "KEY `$__ix`")) { $__faltan[] = "$__n: sobra el índice $__t.$__ix"; }
                }
            }
        }
        if (preg_match('/^INSERT INTO `?app_settings`?/i', $__s)) {
            preg_match_all("/\('(\w+)',/", $__s, $__k);
            foreach ($__k[1] as $__clave) {
                if (!str_contains($__schema, "('$__clave',")) { $__faltan[] = "$__n: ajuste $__clave"; }
            }
        }
    }
}
eq('schema: tiene todo lo que agregan las migraciones', $__faltan, []);
no_contiene('schema: reimportar ya no pisa las probabilidades del embudo', $__schema,
            'ON DUPLICATE KEY UPDATE `probabilidad` = VALUES(`probabilidad`)');
}

<?php
declare(strict_types=1);

// ============================================================
//  Arranque de la aplicación: configuración, errores, sesión.
//  Todo lo que sigue asume que esto ya corrió.
//
//  Lo incluyen index.php (cada pedido web) y los scripts de consola
//  (app/cron.php, migrar.php, reinicio.php, los tests). En orden:
//    1. localiza y carga la configuración de ESTA instalación ($CONFIG);
//    2. define las constantes globales (APP, WEBROOT, BASE_PATH, STORAGE,
//       APP_ENV, ASSETS_V) y la zona horaria;
//    3. carga todos los módulos del núcleo (no hay autoload: todo se
//       incluye en cada pedido, en el orden de dependencias de abajo);
//    4. instala los manejadores de errores que los registran en la
//       auditoría (tabla `actividad`, vía log_error de actividad.php);
//    5. en web, abre la sesión y aplica el cierre automático por
//       inactividad y por sesión máxima.
//
//  Trampas:
//    · La zona horaria se fija ANTES de cargar db.php por una razón: db()
//      copia el desfase de PHP a la sesión de MySQL al conectarse.
//    · En consola (PHP_SAPI 'cli') no hay sesión ni BASE_PATH deducido.
// ============================================================

// ---- Localizar la configuración ----
//
// Primero se busca una configuración propia de la instalación, por el nombre
// de su carpeta. El mismo código está en `crm/` (producción) y en
// `crm-pruebas/` (pruebas); sin esto, la copia de pruebas tomaría la
// configuración de producción y se conectaría a la base real.
//
// Producción sigue funcionando igual: no existe `crm_config/crm.php` (el
// archivo que le tocaría por la carpeta `crm/`, dentro de la carpeta de
// configuraciones del hosting), así que cae en `crm_config/config.php`, la
// de siempre.
//
// La variable de entorno CRM_CONFIG, si alguien la define, gana sobre todo lo
// demás (el .htaccess ya no la fija, ver ahí por qué). Los candidatos se
// prueban en orden y se usa el primero que existe.
//
// La carpeta de configuraciones del hosting (`crm_config/`, al lado de
// `domains/`) no está escrita con el usuario del hosting: sale de, en orden,
//   · la variable de entorno CRM_CONFIG_DIR, si alguien la define;
//   · la carpeta personal deducida de la ruta de este archivo o de la raíz
//     web (/home/<usuario>/domains/… → /home/<usuario>/crm_config), que da
//     la misma carpeta que antes estaba escrita a mano;
//   · la variable HOME del proceso (en consola, por ejemplo).
$configFile = $_SERVER['CRM_CONFIG'] ?? (getenv('CRM_CONFIG') ?: '');
if (!$configFile || !is_file($configFile)) {
    $carpeta = basename(dirname(__DIR__));                  // crm, crm-pruebas, …
    $dirsConfig = [];
    $dirEnv = $_SERVER['CRM_CONFIG_DIR'] ?? (getenv('CRM_CONFIG_DIR') ?: '');
    if ($dirEnv !== '') { $dirsConfig[] = rtrim((string)$dirEnv, '/'); }
    // La ruta de este archivo y, por si el hosting resolviera un enlace en
    // __DIR__, la raíz web que informa el servidor: las dos empiezan con la
    // carpeta personal (/home/<usuario>/domains/…).
    foreach ([__DIR__, (string)($_SERVER['DOCUMENT_ROOT'] ?? '')] as $rutaInst) {
        if (preg_match('#^(/home/[^/]+)/#', str_replace('\\', '/', $rutaInst), $mHome)) {
            $dirsConfig[] = $mHome[1] . '/crm_config';
        }
    }
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    if (is_string($home) && $home !== '' && $home !== '/') { $dirsConfig[] = rtrim($home, '/') . '/crm_config'; }
    $candidatos = [];
    foreach (array_unique($dirsConfig) as $d) {
        $candidatos[] = $d . '/' . $carpeta . '.php';         // config propia de ESTA instalación
        $candidatos[] = $d . '/config.php';                   // Hostinger (producción)
    }
    $candidatos[] = dirname(__DIR__, 2) . '/server-config/config.php';  // repo: app/server-config/config.php
    $candidatos[] = dirname(__DIR__) . '/config.local.php';             // local
    foreach ($candidatos as $cand) {
        if (is_file($cand)) { $configFile = $cand; break; }
    }
    // Todo esto corre en el ámbito global, el mismo de los controladores: no
    // se dejan variables sueltas que una página pudiera leer sin querer.
    unset($dirsConfig, $dirEnv, $rutaInst, $mHome, $home, $candidatos, $d, $cand);
}
if (!$configFile || !is_file($configFile)) {
    http_response_code(500);
    exit('Error: no se encontró la configuración de la aplicación.');
}
// El archivo de configuración devuelve un array (ver config.example.php). Se
// deja también en $GLOBALS porque las funciones lo leen desde ahí.
$CONFIG = require $configFile;
$GLOBALS['CONFIG'] = $CONFIG;

// ---- Constantes ----
// APP: la carpeta app/ (código). WEBROOT: la carpeta pública de la instalación.
define('APP', __DIR__);
define('WEBROOT', dirname(__DIR__));

/**
 * Ruta base de la aplicación.
 *
 * Se deduce de SCRIPT_NAME para que el MISMO código responda igual servido
 * como subdominio (crm.intellihelp.tech → '') y como subcarpeta
 * (intellihelp.tech/crm → '/crm'). La config puede forzarla si hiciera falta.
 */
$basePath = rtrim((string)($CONFIG['app']['base_path'] ?? ''), '/');
if ($basePath === '' && PHP_SAPI !== 'cli') {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $basePath = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}
define('BASE_PATH', $basePath);

// STORAGE: dónde se guardan los adjuntos y las imágenes de campañas. Tiene que
// quedar fuera del web root. El último respaldo (`storage` al lado de la
// carpeta de la instalación) en Hostinger cae dentro de public_html, o sea,
// servido por HTTP: por eso el config de cada instalación lo define siempre.
// Por si alguna vez faltara, adjunto_guardar() deja un .htaccess que niega
// todo en la carpeta de adjuntos y nunca guarda en disco una extensión
// ejecutable (adjunto_ext_disco en registros.php).
// APP_ENV ('prod' | 'dev') sólo decide si los errores se muestran en pantalla.
define('STORAGE', $_SERVER['CRM_STORAGE'] ?? ($CONFIG['app']['storage'] ?? (dirname(WEBROOT) . '/storage')));
define('APP_ENV', $CONFIG['app']['env'] ?? 'prod');

/**
 * Versión de los assets (cache-busting).
 *
 * El `.htaccess` cachea CSS y JS por un año, así que si la dirección del
 * archivo no cambia, el navegador sigue mostrando la hoja de estilos vieja.
 *
 * La versión sale de la fecha de modificación de los propios archivos: si
 * cambia alguno, cambia la versión y el navegador no se queda con la hoja
 * cacheada. No depende de acordarse de subir un número a mano.
 * Entran TODAS las hojas (assets/css/*.css) y todos los scripts
 * (assets/js/*.js), también los que se agreguen después: con una lista fija,
 * un cambio en un script que no figuraba no renovaba la caché.
 */
define('ASSETS_V', (function (): string {
    $mayor = 0;
    $dir = dirname(__DIR__) . '/assets/';
    $archivos = array_merge(glob($dir . 'css/*.css') ?: [], glob($dir . 'js/*.js') ?: []);
    foreach ($archivos as $a) {
        $t = @filemtime($a);
        if ($t !== false && $t > $mayor) { $mayor = $t; }
    }
    // Si no se pudo leer ninguno, se cae a un valor fijo antes que servir sin
    // versión, porque eso dejaría el caché de un año pegado indefinidamente.
    return $mayor > 0 ? (string) $mayor : '3';
})());

// Zona horaria de toda la aplicación (Buenos Aires en producción). Tiene que
// ir antes de la primera conexión a la base: db() toma de acá el desfase.
date_default_timezone_set($CONFIG['app']['timezone'] ?? 'UTC');

// ---- Manejo de errores ----
error_reporting(E_ALL);
if (APP_ENV === 'prod') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    ini_set('display_errors', '1');
}

// ---- Núcleo ----
// Estos archivos definen funciones y constantes; no deberían consultar la base
// ni producir salida al incluirse. Si alguno llegara a ejecutar algo al
// cargarse, lo que use tiene que estar incluido antes: por eso db, helpers,
// actividad y auth van primero.
require APP . '/db.php';
require APP . '/helpers.php';
require APP . '/actividad.php';
require APP . '/auth.php';
require APP . '/google.php';
require APP . '/icons.php';
require APP . '/crm.php';
require APP . '/api.php';
require APP . '/registros.php';
require APP . '/actividades.php';
require APP . '/negocios.php';
require APP . '/bloques.php';
require APP . '/preajustes.php';
require APP . '/campanas.php';
require APP . '/cuentas_correo.php';
require APP . '/imap.php';
require APP . '/mensajes.php';
require APP . '/datos_prueba.php';
require APP . '/modulos.php';
require APP . '/agenda.php';
require APP . '/notificaciones.php';

// ---- Registro de errores en la auditoría ----
// Cada error queda con un CÓDIGO estable (ERR-XXXXXX) por archivo:línea, para
// poder identificar qué falló y dónde sin exponer la traza al usuario.
$GLOBALS['SEV_MAP'] = [
    E_WARNING => 'E_WARNING', E_USER_WARNING => 'E_USER_WARNING', E_USER_ERROR => 'E_USER_ERROR',
    E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING',
    E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_ERROR => 'E_ERROR', E_PARSE => 'E_PARSE',
    E_CORE_ERROR => 'E_CORE_ERROR', E_COMPILE_ERROR => 'E_COMPILE_ERROR',
];

// Cada warning o error de PHP queda en la auditoría. `$dentro` evita la
// recursión: si registrar el error produjera otro error (la base caída, por
// ejemplo), ese segundo no se vuelve a intentar registrar.
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    // Ignorar ruido (notices / deprecations); registrar warnings y peores.
    if ($no & (E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED)) { return false; }
    static $dentro = false;
    if (!$dentro && function_exists('log_error')) {
        $dentro = true;
        try { log_error('error.php', $str, $file, $line, $GLOBALS['SEV_MAP'][$no] ?? ('E#' . $no)); } catch (\Throwable $e) {}
        $dentro = false;
    }
    return false;   // deja también el manejo estándar de PHP
});

// Excepción no atrapada: se registra y se muestra un texto genérico, nunca la
// traza (podría llevar rutas del servidor o datos de la consulta).
set_exception_handler(static function (\Throwable $e): void {
    if (function_exists('log_error')) {
        log_error('error.excepcion', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
    error_log('[crm] ' . $e);
    if (!headers_sent()) { http_response_code(500); }
    echo 'Ocurrió un error inesperado. Ya quedó registrado; reintentá en unos segundos.';
});

// Los errores fatales no pasan por set_error_handler: se recogen al terminar
// el script con error_get_last().
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && function_exists('log_error')) {
        log_error('error.fatal', $e['message'], $e['file'], $e['line'], $GLOBALS['SEV_MAP'][$e['type']] ?? 'FATAL');
    }
});

// ---- Sesión (sólo web) ----
// La API v1 no usa sesión: la autentica la firma de cada pedido, y quien
// llama es otro servidor, no una persona con navegador. Abrirle una sesión
// dejaba un archivo nuevo en el servidor por cada consulta de horarios.
if (PHP_SAPI !== 'cli' && !str_starts_with(current_route(), 'api/v1/')) {
    // La cookie de sesión: sólo para la ruta de esta instalación (así /crm y
    // /crm-pruebas no se pisan la sesión), invisible para JavaScript
    // (httponly), no se manda en POST desde otros sitios (Lax) y sólo por
    // HTTPS cuando se entró por HTTPS (también detrás del CDN, que avisa con
    // X-Forwarded-Proto).
    $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (BASE_PATH ?: '') . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $seguro,
    ]);
    session_name('ith_crm');
    session_start();

    // ---- Auto-cierre de sesión (lo impone el SERVIDOR) ----
    // Inactividad (10 min) + sesión máxima (8 h). El aviso del navegador es
    // cosmético: la próxima request pasado el vencimiento destruye la sesión
    // aunque el cliente no colabore.
    if (!empty($_SESSION['uid'])) {
        $ahora   = time();
        $idleTtl = (int)($CONFIG['app']['idle_ttl'] ?? 600);
        $absTtl  = (int)($CONFIG['app']['abs_ttl']  ?? 28800);
        $idle    = $ahora - (int)($_SESSION['ultima_actividad'] ?? $ahora);
        $abs     = $ahora - (int)($_SESSION['login_en']         ?? $ahora);
        $venIdle = $idleTtl > 0 && $idle > $idleTtl;
        $venAbs  = $absTtl  > 0 && $abs  > $absTtl;
        // Un TTL en 0 desactiva ese control. El texto del detalle dice los
        // tiempos que están configurados (10 min y 8 h por defecto).
        if ($venIdle || $venAbs) {
            $motivo = $venIdle ? 'inactividad' : 'expiro';
            $uid    = (int)$_SESSION['uid'];
            try {
                log_app('sesion.cierre_auto', ['tipo' => 'auth', 'usuario_id' => $uid,
                    'detalle' => 'Cierre automático por ' . ($venIdle
                        ? 'inactividad (' . duracion_legible($idleTtl) . ')'
                        : 'sesión máxima (' . duracion_legible($absTtl) . ')')]);
            } catch (\Throwable $e) {}
            auth_logout();
            // Los endpoints que responden JSON reciben 440 para que el cliente
            // reaccione; la navegación normal se manda al login con el aviso.
            $ruta = current_route();
            $esAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
                   || str_starts_with($ruta, 'api/') || str_starts_with($ruta, 'api-')
                   || isset($_GET['__vivo']);
            if ($esAjax) {
                http_response_code(440);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'sesion_expirada', 'motivo' => $motivo]);
                exit;
            }
            redirect('login?expiro=' . $motivo);
        }
        // El refresco automático NO cuenta como actividad. Lo dispara el
        // navegador solo, cada pocos segundos, con la pantalla abierta y nadie
        // delante: si contara, una pantalla olvidada mantendría la sesión viva
        // indefinidamente y el cierre automático no cerraría nunca nada.
        if (!isset($_GET['__vivo'])) {
            $_SESSION['ultima_actividad'] = $ahora;
        }
    }
}

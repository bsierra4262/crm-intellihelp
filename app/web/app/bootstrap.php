<?php
declare(strict_types=1);

// ============================================================
//  Arranque de la aplicación: configuración, errores, sesión.
//  Todo lo que sigue asume que esto ya corrió.
// ============================================================

// ---- Localizar la configuración ----
// 1) archivo indicado por CRM_CONFIG (fuera del web root, producción)
// 2) rutas conocidas (Hostinger, repositorio, local)
$configFile = $_SERVER['CRM_CONFIG'] ?? (getenv('CRM_CONFIG') ?: '');
if (!$configFile || !is_file($configFile)) {
    foreach ([
        '/home/u566451014/crm_config/config.php',           // Hostinger (producción)
        dirname(__DIR__, 2) . '/server-config/config.php',  // repo: app/server-config/config.php
        dirname(__DIR__) . '/config.local.php',             // local
    ] as $cand) {
        if (is_file($cand)) { $configFile = $cand; break; }
    }
}
if (!$configFile || !is_file($configFile)) {
    http_response_code(500);
    exit('Error: no se encontró la configuración de la aplicación.');
}
$CONFIG = require $configFile;
$GLOBALS['CONFIG'] = $CONFIG;

// ---- Constantes ----
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

define('STORAGE', $_SERVER['CRM_STORAGE'] ?? ($CONFIG['app']['storage'] ?? (dirname(WEBROOT) . '/storage')));
define('APP_ENV', $CONFIG['app']['env'] ?? 'prod');

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
require APP . '/db.php';
require APP . '/helpers.php';
require APP . '/actividad.php';
require APP . '/auth.php';
require APP . '/google.php';
require APP . '/icons.php';
require APP . '/crm.php';
require APP . '/registros.php';
require APP . '/actividades.php';
require APP . '/negocios.php';
require APP . '/campanas.php';
require APP . '/imap.php';
require APP . '/agenda.php';

// ---- Registro de errores en la auditoría ----
// Cada error queda con un CÓDIGO estable (ERR-XXXXXX) por archivo:línea, para
// poder identificar qué falló y dónde sin exponer la traza al usuario.
$GLOBALS['SEV_MAP'] = [
    E_WARNING => 'E_WARNING', E_USER_WARNING => 'E_USER_WARNING', E_USER_ERROR => 'E_USER_ERROR',
    E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING',
    E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_ERROR => 'E_ERROR', E_PARSE => 'E_PARSE',
    E_CORE_ERROR => 'E_CORE_ERROR', E_COMPILE_ERROR => 'E_COMPILE_ERROR',
];

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

set_exception_handler(static function (\Throwable $e): void {
    if (function_exists('log_error')) {
        log_error('error.excepcion', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
    error_log('[crm] ' . $e);
    if (!headers_sent()) { http_response_code(500); }
    echo 'Ocurrió un error inesperado. Ya quedó registrado; reintentá en unos segundos.';
});

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && function_exists('log_error')) {
        log_error('error.fatal', $e['message'], $e['file'], $e['line'], $GLOBALS['SEV_MAP'][$e['type']] ?? 'FATAL');
    }
});

// ---- Sesión (sólo web) ----
if (PHP_SAPI !== 'cli') {
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
        if ($venIdle || $venAbs) {
            $motivo = $venIdle ? 'inactividad' : 'expiro';
            $uid    = (int)$_SESSION['uid'];
            try {
                log_app('sesion.cierre_auto', ['tipo' => 'auth', 'usuario_id' => $uid,
                    'detalle' => 'Cierre automático por ' . ($venIdle ? 'inactividad (10 min)' : 'sesión máxima (8 h)')]);
            } catch (\Throwable $e) {}
            auth_logout();
            // Los endpoints que responden JSON reciben 440 para que el cliente
            // reaccione; la navegación normal se manda al login con el aviso.
            $ruta = current_route();
            $esAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
                   || str_starts_with($ruta, 'api/') || str_starts_with($ruta, 'api-');
            if ($esAjax) {
                http_response_code(440);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'sesion_expirada', 'motivo' => $motivo]);
                exit;
            }
            redirect('login?expiro=' . $motivo);
        }
        $_SESSION['ultima_actividad'] = $ahora;
    }
}

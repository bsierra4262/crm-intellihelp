<?php
declare(strict_types=1);

// ============================================================
//  Helpers generales
// ============================================================

/** Escapa para HTML. */
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Construye una URL interna de la app. */
function url(string $path = ''): string
{
    return (BASE_PATH ?: '') . '/' . ltrim($path, '/');
}

/**
 * URL absoluta, para lo que sale de la aplicación: enlaces de la agenda
 * pública, baja de suscripción, callback de Google. Usa la URL canónica de la
 * configuración para que no dependa del host por el que entró el visitante.
 */
function url_abs(string $path = ''): string
{
    $base = rtrim((string)($GLOBALS['CONFIG']['app']['canonical'] ?? ''), '/');
    if ($base === '') {
        $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (BASE_PATH ?: '');
    }
    return $base . '/' . ltrim($path, '/');
}

/** Ruta actual (desde el front controller). */
function current_route(): string
{
    $r = $_GET['r'] ?? '';
    if ($r === '' && isset($_SERVER['PATH_INFO'])) {
        $r = $_SERVER['PATH_INFO'];
    }
    return trim((string)$r, '/');
}

/** Redirige a una ruta interna y corta la ejecución. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** Método HTTP actual. */
function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return method() === 'POST';
}

/**
 * Envía la respuesta ya armada al cliente y deja el script corriendo en
 * segundo plano (si el SAPI lo permite: LiteSpeed/FPM). Sirve para no hacer
 * esperar al usuario por tareas lentas.
 */
function finish_request(): void
{
    if (function_exists('ignore_user_abort')) { ignore_user_abort(true); }
    if (function_exists('session_write_close')) { @session_write_close(); }
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    elseif (function_exists('litespeed_finish_request')) { litespeed_finish_request(); }
    elseif (!headers_sent()) { @ob_end_flush(); @flush(); }
}

/**
 * IP real del cliente. En hosting compartido REMOTE_ADDR suele ser el proxy;
 * el cliente real viaja en X-Forwarded-For / CF-Connecting-IP.
 */
function client_ip(): ?string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $h) {
        if (empty($_SERVER[$h])) { continue; }
        foreach (explode(',', (string)$_SERVER[$h]) as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) { return $ip; }
        }
    }
    return null;
}

// ---- CSRF ----

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $ok = isset($_POST['_csrf'], $_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], (string)$_POST['_csrf']);
    if (!$ok) {
        http_response_code(419);
        exit('Token de seguridad inválido. Volvé atrás y reintentá.');
    }
}

// ---- Mensajes flash ----

function flash(string $msg, string $tipo = 'info'): void
{
    $_SESSION['_flash'][] = ['msg' => $msg, 'type' => $tipo];
}

function take_flash(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

// ---- Render de vistas ----

function render(string $vista, array $vars = [], string $titulo = ''): void
{
    $title = $titulo !== '' ? $titulo : ($GLOBALS['CONFIG']['app']['name'] ?? 'CRM');
    extract($vars, EXTR_SKIP);
    ob_start();
    include APP . '/views/' . $vista . '.php';
    $content = ob_get_clean();
    include APP . '/views/layout.php';
}

/** Responde JSON y termina. */
function json_out($datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Descarga un listado como CSV y termina la ejecución.
 *
 * Va con BOM UTF-8 y separador ';' porque el equipo abre estos archivos con
 * Excel en español: sin eso, los acentos salen rotos y todo cae en una columna.
 *
 * @param array<int,array<string,mixed>> $filas
 * @param array<string,string>           $columnas  clave del array => encabezado
 */
function exportar_csv(string $archivo, array $filas, array $columnas): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF");
    fputcsv($salida, array_values($columnas), ';', '"', '\\');
    foreach ($filas as $fila) {
        $linea = [];
        foreach (array_keys($columnas) as $k) {
            $linea[] = (string)($fila[$k] ?? '');
        }
        fputcsv($salida, $linea, ';', '"', '\\');
    }
    fclose($salida);
    exit;
}

// ============================================================
//  Formato y datos
// ============================================================

/** Formatea un importe en formato argentino. */
function money($n, string $moneda = 'ARS'): string
{
    $sim = $moneda === 'USD' ? 'US$' : '$';
    return $sim . ' ' . number_format((float)$n, 2, ',', '.');
}

/** Fecha corta dd/mm/aaaa. */
function fecha_corta(?string $f): string
{
    if (!$f || str_starts_with($f, '0000')) { return '—'; }
    $t = strtotime($f);
    return $t ? date('d/m/Y', $t) : '—';
}

/** Fecha y hora dd/mm/aaaa HH:MM. */
function fecha_hora(?string $f): string
{
    if (!$f || str_starts_with($f, '0000')) { return '—'; }
    $t = strtotime($f);
    return $t ? date('d/m/Y H:i', $t) : '—';
}

/** "hace 3 días", "en 2 horas". Devuelve '—' si no hay fecha. */
function tiempo_relativo(?string $f): string
{
    if (!$f) { return '—'; }
    $t = strtotime($f);
    if (!$t) { return '—'; }
    $d = time() - $t;
    $futuro = $d < 0;
    $d = abs($d);
    $unidades = [31536000 => 'año', 2592000 => 'mes', 604800 => 'semana',
                 86400 => 'día', 3600 => 'hora', 60 => 'minuto'];
    foreach ($unidades as $seg => $nombre) {
        if ($d >= $seg) {
            $n = (int) floor($d / $seg);
            $plural = $n > 1 ? ($nombre === 'mes' ? 'meses' : $nombre . 's') : $nombre;
            return $futuro ? "en $n $plural" : "hace $n $plural";
        }
    }
    return 'recién';
}

/**
 * Fecha larga en castellano ("jueves 30 de julio de 2026").
 * No usamos strftime() porque está obsoleto desde PHP 8.1 y depende del locale
 * del servidor, que en hosting compartido no siempre está instalado.
 */
function fecha_larga(?string $f = null): string
{
    $t     = $f ? strtotime($f) : time();
    $dias  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
              'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $dias[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' de '
         . $meses[(int)date('n', $t)] . ' de ' . date('Y', $t);
}

/** Iniciales para el avatar de respaldo. */
function iniciales(string $texto): string
{
    $partes = preg_split('/\s+/', trim($texto)) ?: [];
    $ini = '';
    foreach (array_slice($partes, 0, 2) as $p) {
        if ($p !== '') { $ini .= mb_strtoupper(mb_substr($p, 0, 1)); }
    }
    return $ini !== '' ? $ini : '?';
}

/** Recorta un texto agregando puntos suspensivos. */
function recortar(?string $t, int $largo = 60): string
{
    $t = trim((string)$t);
    return mb_strlen($t) > $largo ? mb_substr($t, 0, $largo - 1) . '…' : $t;
}

/** Token aleatorio de 32 caracteres (enlaces públicos, desuscripción). */
function token32(): string
{
    return bin2hex(random_bytes(16));
}

/** Valida una dirección de correo. */
function email_valido(?string $email): bool
{
    return (bool) filter_var(trim((string)$email), FILTER_VALIDATE_EMAIL);
}

/** Normaliza un correo: minúsculas y sin espacios. */
function normalizar_email(?string $email): string
{
    return strtolower(trim((string)$email));
}

// ---- Dominio fiscal argentino (mismo criterio que APP Tickets) ----

/** Deja sólo dígitos. */
function normalizar_cuit($s): string
{
    return preg_replace('/\D/', '', (string)$s) ?? '';
}

/** Valida CUIT/CUIL por dígito verificador (módulo 11). */
function validar_cuit($s): bool
{
    $c = normalizar_cuit($s);
    if (strlen($c) !== 11) { return false; }
    $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += ((int)$c[$i]) * $pesos[$i];
    }
    $dv = 11 - ($sum % 11);
    if ($dv === 11) { $dv = 0; }
    if ($dv === 10) { return false; }
    return $dv === (int)$c[10];
}

/** Formatea CUIT como NN-NNNNNNNN-N. */
function formatear_cuit($s): string
{
    $c = normalizar_cuit($s);
    return strlen($c) === 11
        ? substr($c, 0, 2) . '-' . substr($c, 2, 8) . '-' . substr($c, 10, 1)
        : (string)$s;
}

// ---- Paginación ----

/**
 * Calcula los datos de paginación.
 * @return array{page:int, per:int, offset:int, paginas:int}
 */
function paginar(int $total, $page, int $per = 25): array
{
    $page    = max(1, (int)$page);
    $paginas = max(1, (int)ceil($total / $per));
    $page    = min($page, $paginas);
    return ['page' => $page, 'per' => $per, 'offset' => ($page - 1) * $per, 'paginas' => $paginas];
}

/** Reconstruye la query string actual cambiando algunos parámetros. */
function query_con(array $cambios): string
{
    $q = $_GET;
    unset($q['r']);
    foreach ($cambios as $k => $v) {
        if ($v === null || $v === '') { unset($q[$k]); } else { $q[$k] = $v; }
    }
    return $q ? '?' . http_build_query($q) : '';
}

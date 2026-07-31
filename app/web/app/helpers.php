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
        && hash_equals($_SESSION['_csrf'], (string)($_POST['_csrf'] ?? ''));
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

/**
 * Ejecuta una vista con sus datos y devuelve el HTML.
 *
 * Es una clase y no una función suelta por un motivo concreto. `extract()`
 * escribe las variables del array en el ámbito local, así que cualquier
 * variable propia que estuviera ahí —la ruta del archivo, por ejemplo— puede
 * quedar pisada por un dato de la vista que se llame igual. Dentro de un método
 * eso no puede pasar: la ruta viaja en `$this->ruta`, y `$this` es lo único que
 * `extract()` no puede tocar.
 *
 * Esto no es teoría. La versión anterior usaba `extract($vars, EXTR_SKIP)`
 * dentro de la propia `render()`, y como `$vista` y `$titulo` son parámetros de
 * `render()`, toda vista que recibiera un dato con esos nombres se quedaba con
 * el valor interno en su lugar, en silencio:
 *   · `campana-ver` pedía la vista previa del correo y recibía el texto
 *     «campana-ver», así que reventaba al leerlo como arreglo;
 *   · cada pantalla de error mostraba el título equivocado;
 *   · la solapa elegida en Actividades no llegaba nunca.
 */
final class Vista
{
    public function __construct(private string $ruta, private array $datos) {}

    public function html(): string
    {
        extract($this->datos, EXTR_OVERWRITE);
        ob_start();
        try {
            include $this->ruta;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            // Se descarta lo que la vista alcanzó a escribir. Si el buffer
            // quedara abierto, PHP lo volcaría al terminar y la página saldría
            // con el HTML suelto de la vista, sin <head> y por lo tanto sin
            // estilos: una pared de texto en vez de un error entendible.
            ob_end_clean();
            throw $e;
        }
    }
}

/**
 * Dibuja una vista dentro del layout.
 *
 * La vista y el layout se ejecutan en ámbitos separados: así una variable de la
 * vista no puede pisar lo que el layout necesita, ni al revés.
 */
function render(string $vista, array $vars = [], string $titulo = ''): void
{
    $titulo = $titulo !== '' ? $titulo : ($GLOBALS['CONFIG']['app']['name'] ?? 'CRM');

    try {
        $cuerpo = (new Vista(APP . '/views/' . $vista . '.php', $vars))->html();
    } catch (\Throwable $e) {
        // Una vista rota no puede llevarse puesta la página entera. Se registra
        // el error de verdad y se muestra la pantalla de error con el diseño de
        // siempre, para que se entienda qué pasó y se pueda salir de ahí.
        if (function_exists('log_error')) {
            log_error('error.vista', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
        }
        error_log('[crm] vista ' . $vista . ': ' . $e);
        if (!headers_sent()) { http_response_code(500); }
        $cuerpo = (new Vista(APP . '/views/error.php', [
            'codigo'  => 500,
            'titulo'  => 'No pudimos dibujar esta pantalla',
            'mensaje' => 'El error quedó registrado. Probá de nuevo; si sigue pasando, avisanos.',
        ]))->html();
        $titulo = 'Error';
    }

    // Estos tres no son datos de la vista sino del marco que la rodea, y los
    // manda la página: `bodyClass` es lo que distingue una pantalla pública
    // —la de reservar una reunión, la de darse de baja— de una de adentro del
    // sistema. Se pasan al layout de forma explícita; antes viajaban por el
    // ámbito compartido, que es justamente lo que rompía todo lo demás.
    $marco = ['content' => $cuerpo, 'title' => $titulo];
    foreach (['bodyClass', 'extraHead', 'extraFoot'] as $k) {
        if (isset($vars[$k])) { $marco[$k] = $vars[$k]; }
    }

    echo (new Vista(APP . '/views/layout.php', $marco))->html();
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

/**
 * Imprime un par etiqueta/valor de una ficha (contacto, empresa, posible cliente).
 *
 * Estaba copiado como función anónima dentro de tres vistas, y cada copia había
 * quedado un poco distinta: por eso el mismo dato se veía de una forma en la
 * ficha de un contacto y de otra en la de una empresa. Al haber una sola copia,
 * un correo se muestra igual en todas las pantallas.
 *
 * Un valor vacío no se esconde: se muestra un guión en gris. Un campo que
 * desaparece deja la duda de si el dato falta o si la pantalla se rompió.
 *
 * @param string $tipo texto | mail | tel | url
 */
function dato(string $etiqueta, $valor, string $tipo = 'texto'): void
{
    $v = trim((string)$valor);
    echo '<div><div class="k">' . e($etiqueta) . '</div>'
       . '<div class="v' . ($v === '' ? ' empty' : '') . '">';
    if ($v === '') {
        echo '—';
    } elseif ($tipo === 'mail') {
        echo '<a href="mailto:' . e($v) . '">' . e($v) . '</a>';
    } elseif ($tipo === 'tel') {
        // El href se limpia de espacios y guiones; el texto se muestra con
        // separadores, que es como se lee un teléfono en cualquier lado.
        echo '<a href="tel:' . e((string)preg_replace('/[^\d+]/', '', $v)) . '">'
           . e(telefono_formato($v)) . '</a>';
    } elseif ($tipo === 'url') {
        $href = preg_match('#^https?://#i', $v) ? $v : 'https://' . $v;
        echo '<a href="' . e($href) . '" target="_blank" rel="noopener">' . e(recortar($v, 40)) . '</a>';
    } else {
        echo e($v);
    }
    echo '</div></div>';
}

/**
 * Pliega las líneas de un archivo de calendario (iCalendar) al ancho que exige
 * el formato: 75 octetos, y la línea siguiente arranca con un espacio.
 *
 * Vive acá y no dentro de la agenda porque tiene dos trampas que conviene poder
 * probar sola:
 *   · el corte se cuenta en OCTETOS pero no puede caer en el medio de un
 *     carácter. Partir una «ó» al medio deja el archivo ilegible, y escribiendo
 *     en castellano eso pasa a cada rato;
 *   · el espacio del principio de la línea continuada también ocupa, así que
 *     ahí entran 74 y no 75. Contando 75 se generaban líneas de 76 octetos.
 *
 * @param array<int,string> $lineas
 */
function ics_plegar(array $lineas): string
{
    $salida = '';
    foreach ($lineas as $l) {
        $limite = 75;
        $actual = '';
        foreach (preg_split('//u', $l, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $car) {
            if (strlen($actual) + strlen($car) > $limite) {
                $salida .= $actual . "\r\n ";
                $actual = '';
                $limite = 74;
            }
            $actual .= $car;
        }
        $salida .= $actual . "\r\n";
    }
    return $salida;
}

/**
 * Muestra un teléfono de forma legible, como en cualquier aplicación de
 * contactos: «+54 9 11 5027-4827» en vez de «5491150274827».
 *
 * Sólo cambia cómo se ve. Lo que se guarda queda tal cual lo cargaron, porque
 * es lo que se usa para armar los enlaces de WhatsApp y de llamada, y ahí los
 * espacios y los guiones molestan.
 *
 * Si el número no encaja en ningún patrón conocido se devuelve como vino: es
 * preferible mostrarlo crudo antes que partirlo mal y que quede irreconocible.
 */
function telefono_formato(?string $v): string
{
    $v = trim((string)$v);
    if ($v === '') { return ''; }

    $d = preg_replace('/\D+/', '', $v) ?? '';
    if ($d === '') { return $v; }

    // Argentina: 54 [9] área + número. El «9» va en los móviles.
    if (str_starts_with($d, '54')) {
        $resto = substr($d, 2);
        $movil = str_starts_with($resto, '9');
        if ($movil) { $resto = substr($resto, 1); }
        // Códigos de área de 2, 3 o 4 dígitos; el abonado siempre tiene 6 a 8.
        foreach ([2, 3, 4] as $largoArea) {
            $abonado = strlen($resto) - $largoArea;
            if ($abonado >= 6 && $abonado <= 8) {
                $area = substr($resto, 0, $largoArea);
                $num  = substr($resto, $largoArea);
                $mitad = (int) ceil(strlen($num) / 2);
                return '+54 ' . ($movil ? '9 ' : '') . $area . ' '
                     . substr($num, 0, $mitad) . '-' . substr($num, $mitad);
            }
        }
        return '+54 ' . ($movil ? '9 ' : '') . $resto;
    }

    // Número local sin código de país: se separa igual para poder leerlo.
    if (strlen($d) >= 8 && strlen($d) <= 11 && !str_starts_with($v, '+')) {
        $area  = strlen($d) === 8 ? '' : substr($d, 0, strlen($d) - 8);
        $num   = substr($d, strlen($d) - 8);
        $mitad = (int) ceil(strlen($num) / 2);
        return trim($area . ' ' . substr($num, 0, $mitad) . '-' . substr($num, $mitad));
    }

    // Otro país (o algo que no reconocemos): se muestra tal cual.
    return $v;
}

/** Tamaño de un archivo en la unidad que se entiende de un vistazo. */
function peso_legible(int $bytes): string
{
    if ($bytes < 1024)            { return $bytes . ' B'; }
    if ($bytes < 1024 * 1024)     { return round($bytes / 1024) . ' KB'; }
    return round($bytes / 1048576, 1) . ' MB';
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

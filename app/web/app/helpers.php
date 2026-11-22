<?php
declare(strict_types=1);

// ============================================================
//  Helpers generales
//
//  Funciones sin estado de negocio que usa todo el sistema:
//    · escape de salida (e) y armado de direcciones (url, url_abs, redirect);
//    · ruta y método del pedido; IP del visitante;
//    · CSRF y mensajes flash (los dos viven en la sesión);
//    · render de vistas con layout, refresco automático (vivo) y JSON;
//    · exportación CSV a prueba de fórmulas;
//    · formato de importes, fechas, teléfonos, CUIT y tamaños;
//    · paginación.
//
//  Lo incluye bootstrap.php. No lee ni escribe tablas, salvo de forma
//  indirecta a través de log_error en render().
//
//  Regla de escape: toda salida a HTML pasa por e(), en texto y en atributos.
//  Las funciones que devuelven HTML (csrf_field, dato, icon en icons.php)
//  ya escapan lo que reciben.
// ============================================================

/**
 * Escapa para HTML (texto y atributos entre comillas simples o dobles).
 *
 * null se imprime vacío. ENT_SUBSTITUTE reemplaza el UTF-8 inválido en vez
 * de devolver una cadena vacía. No sirve para meter datos dentro de un
 * <script> ni de un style: ahí va json_encode o una lista blanca.
 */
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Construye una URL interna de la app, relativa al dominio.
 *
 * url('contacto-ver?id=3') da '/crm/contacto-ver?id=3'. No escapa: en un
 * atributo HTML hay que envolverla en e().
 */
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

/**
 * Ruta actual (desde el front controller), sin barras al principio ni al final.
 *
 * Sale de `?r=`, que arma el .htaccess; PATH_INFO queda de respaldo para
 * servidores sin mod_rewrite (index.php/contactos).
 */
function current_route(): string
{
    $r = $_GET['r'] ?? '';
    if ($r === '' && isset($_SERVER['PATH_INFO'])) {
        $r = $_SERVER['PATH_INFO'];
    }
    return trim((string)$r, '/');
}

/**
 * Redirige a una ruta interna y corta la ejecución (exit).
 *
 * Siempre arma la dirección con url(), así que queda dentro de la aplicación
 * mientras $path no traiga una barra extra al principio. Quien pase un valor
 * que viene del usuario tiene que filtrarlo antes (ver registro-eliminar.php).
 */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/**
 * ¿El pedido salió de una página de este mismo sitio (o lo escribió la
 * persona en la barra de direcciones)?
 *
 * Sirve para lo que no puede llevar token CSRF porque es una navegación por
 * GET (el cierre de sesión que dispara idle.js). Mira primero
 * `Sec-Fetch-Site`, que pone el navegador y una página no puede falsificar:
 * 'same-origin' (la navegó un script o un enlace propio) o 'none' (dirección
 * escrita, favorito) cuentan; 'same-site' y 'cross-site' no. Si el navegador
 * no la manda (muy viejo, o un intermediario la quitó), se compara el host
 * del Referer con el del pedido.
 *
 * @return bool|null true/false, o null si no hay forma de saberlo
 */
function pedido_mismo_origen(array $srv): ?bool
{
    $sfs = strtolower(trim((string)($srv['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($sfs !== '') {
        return in_array($sfs, ['same-origin', 'none'], true);
    }
    $ref = (string)($srv['HTTP_REFERER'] ?? '');
    if ($ref === '') { return null; }
    $hostRef = strtolower((string) parse_url($ref, PHP_URL_HOST));
    $host    = strtolower((string) preg_replace('/:\d+$/', '', (string)($srv['HTTP_HOST'] ?? '')));
    return $hostRef !== '' && $hostRef === $host;
}

/** Método HTTP actual. */
function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/** ¿El pedido es un POST? Los formularios que modifican datos usan POST + CSRF. */
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
 *
 * Ojo: le cree a cualquier cabecera, que el visitante puede inventar. Para
 * todo lo que decide algo (límites, auditoría) se usa ip_confiable(), y para
 * guardar la IP de alguien sin sesión, log_ip() (actividad.php), que la deja
 * como hash. No se usa para nada nuevo: queda por compatibilidad.
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

/**
 * IP del cliente para todo lo que decide algo: límites de envíos, hash de la
 * IP del visitante, auditoría.
 *
 * client_ip() le cree a cualquier cabecera, y X-Forwarded-For o Client-IP las
 * escribe quien hace el pedido: cambiándolas en cada envío se esquivaba el
 * límite de la agenda pública. En Hostinger el CDN ya resuelve la IP del
 * visitante y la deja en REMOTE_ADDR (lo comprobamos mandando cabeceras
 * inventadas: llegan agregadas a X-Forwarded-For, pero REMOTE_ADDR es la de
 * verdad), así que se usa ésa. Sólo si REMOTE_ADDR es un proxy propio, cargado
 * en la configuración (`app.proxies_confiables`, IPs o rangos CIDR), se lee
 * X-Forwarded-For de derecha a izquierda: el último salto que no sea un proxy
 * propio es el que agregó el proxy, no el que inventó el cliente.
 */
function ip_confiable(): ?string
{
    $remota = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!filter_var($remota, FILTER_VALIDATE_IP)) { return null; }

    $proxies = (array)($GLOBALS['CONFIG']['app']['proxies_confiables'] ?? []);
    $esProxy = static function (string $ip) use ($proxies): bool {
        foreach ($proxies as $p) {
            if (is_string($p) && ip_en_rango($ip, $p)) { return true; }
        }
        return false;
    };
    if (!$proxies || !$esProxy($remota)) { return $remota; }

    $saltos = array_reverse(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($saltos as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { break; }
        if (!$esProxy($ip)) { return $ip; }
    }
    return $remota;
}

/** ¿La IP está en el rango? Acepta una IP sola o un rango CIDR, v4 o v6. */
function ip_en_rango(string $ip, string $rango): bool
{
    [$red, $bits] = array_pad(explode('/', trim($rango), 2), 2, null);
    $a = @inet_pton($ip);
    $b = @inet_pton((string)$red);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) { return false; }
    $bits = $bits === null ? strlen($a) * 8 : max(0, min(strlen($a) * 8, (int)$bits));
    $bytes = intdiv($bits, 8);
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) { return false; }
    $resto = $bits % 8;
    if ($resto === 0) { return true; }
    $mascara = (0xFF << (8 - $resto)) & 0xFF;
    return (ord($a[$bytes]) & $mascara) === (ord($b[$bytes]) & $mascara);
}

/**
 * La IP reducida a la red que identifica a un visitante, para contar envíos.
 *
 * En IPv4 es la IP. En IPv6 cualquier conexión hogareña o servidor tiene un
 * /64 entero: contando por IP completa, cada pedido podía salir de una
 * dirección nueva. Se cuenta por el /64.
 */
function ip_red(?string $ip): ?string
{
    $ip = trim((string)$ip);
    $bin = $ip !== '' ? @inet_pton($ip) : false;
    if ($bin === false) { return null; }
    if (strlen($bin) === 4) { return $ip; }
    return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
}

/**
 * ¿El texto no tiene direcciones web? Para los datos que escribe un visitante
 * y vuelven en un correo que sale con la marca de la empresa (el nombre, la
 * empresa): ahí una dirección es un enlace que la empresa le manda a un
 * tercero.
 */
function texto_sin_enlaces(string $s): bool
{
    return !preg_match('#://|\bwww\.#i', $s);
}

// ---- CSRF ----
// Un token por sesión (no por formulario): se genera la primera vez que se
// pide y dura hasta que la sesión se cierra. Viaja en el campo oculto `_csrf`
// de cada formulario POST, y en `window.CSRF` / <meta name="csrf"> para los
// pedidos que arma JavaScript (layout.php).

/** El token CSRF de la sesión; lo crea (64 hex) si todavía no existe. */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Campo oculto con el token CSRF, listo para imprimir dentro de un <form>. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Corta el pedido con 419 si el token del POST no coincide con el de la
 * sesión (exit). hash_equals compara en tiempo constante.
 *
 * Sólo mira $_POST: no sirve para pedidos JSON que manden el token en una
 * cabecera. Si la sesión venció entre que se abrió el formulario y se
 * envió, también falla (la sesión nueva tiene otro token).
 *
 * Para que no se pierda lo escrito, la respuesta no es una línea de texto: es
 * una pantalla que explica qué pasó, muestra lo que se había escrito para
 * poder copiarlo y ofrece volver atrás. Los pedidos en segundo plano
 * (fetch/XHR) reciben JSON.
 */
function verify_csrf(): void
{
    $ok = isset($_POST['_csrf'], $_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], (string)($_POST['_csrf'] ?? ''));
    if ($ok) { return; }

    http_response_code(419);
    $ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
         || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if ($ajax || !function_exists('render') || !defined('APP') || !is_file(APP . '/views/error.php')) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => 'token_invalido',
            'mensaje' => 'La página estuvo abierta mucho tiempo. Recargala y volvé a intentar.'], JSON_UNESCAPED_UNICODE));
    }
    render('error', [
        'codigo'      => 419,
        'titulo'      => 'No pudimos guardar: la página estuvo abierta mucho tiempo',
        'mensaje'     => 'Por seguridad, cada página abierta tiene un permiso que vence con la sesión. '
                       . 'Lo que escribiste no se guardó, pero está acá abajo para que lo copies. '
                       . 'Volvé atrás, recargá la página y pegalo de nuevo.',
        'campos'      => csrf_campos_escritos($_POST),
        'volverAtras' => true,
    ], 'No se pudo guardar');
    exit;
}

/**
 * Lo que la persona escribió en un formulario rechazado por el token, para
 * mostrárselo y que no lo pierda: sólo los campos de texto con contenido, sin
 * los ocultos de uso interno (los que empiezan con «_») ni los ids.
 *
 * @return array<string,string> nombre del campo => texto (recortado a 20.000)
 */
function csrf_campos_escritos(array $post): array
{
    $out = [];
    foreach ($post as $k => $v) {
        $k = (string)$k;
        if ($k === '' || $k[0] === '_' || in_array($k, ['id', 'accion', 'entidad', 'entidad_id', 'volver'], true)) { continue; }
        if (is_array($v)) { $v = implode(', ', array_filter(array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v))); }
        $v = trim((string)$v);
        if ($v === '') { continue; }
        $out[$k] = mb_substr($v, 0, 20000);
        if (count($out) >= 30) { break; }
    }
    return $out;
}

// ---- Mensajes flash ----
// Avisos de una sola vez («Nota agregada.») que sobreviven a la redirección
// posterior a un POST: se guardan en la sesión y los imprime el layout (o la
// vista del login) en la pantalla siguiente.

/**
 * Encola un aviso para la próxima pantalla.
 *
 * @param string $tipo info | success | error (se usa como clase CSS flash-<tipo>)
 */
function flash(string $msg, string $tipo = 'info'): void
{
    $_SESSION['_flash'][] = ['msg' => $msg, 'type' => $tipo];
}

/** Devuelve los avisos pendientes y los borra de la sesión. */
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
 * Con `extract($vars, EXTR_SKIP)` dentro de `render()`, como `$vista` y
 * `$titulo` son parámetros de `render()`, toda vista que recibiera un dato con
 * esos nombres se quedaría con el valor interno en su lugar, en silencio: una
 * vista previa recibiría el nombre de la vista en vez del arreglo, y una
 * pantalla de error, el título equivocado.
 */
final class Vista
{
    /**
     * @param string $ruta  archivo de la vista (ruta absoluta)
     * @param array  $datos variables que la vista va a ver con su nombre
     */
    public function __construct(private string $ruta, private array $datos) {}

    /**
     * Ejecuta la vista y devuelve lo que imprimió.
     *
     * @throws \Throwable cualquier error de la vista, después de descartar
     *                    la salida parcial
     */
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
 * ¿Esta request es el refresco automático de una pantalla?
 *
 * El navegador vuelve a pedir la MISMA dirección con `__vivo=1` cada pocos
 * segundos y se queda sólo con las zonas marcadas. No hace falta un endpoint
 * por pantalla: la pantalla ya sabe dibujarse.
 */
function vivo_pedido(): bool
{
    return isset($_GET['__vivo']);
}

/**
 * Contesta el refresco: manda el cuerpo sin el layout, o nada si no cambió.
 *
 * Casi todas las consultas devuelven exactamente lo mismo que la anterior —no
 * pasó nada en esos ocho segundos—. Con la huella del contenido, esas
 * respuestas viajan vacías (204, sin contenido) y el navegador ni se entera:
 * no repinta, no pierde el lugar donde estabas, no parpadea. Sólo cuando algo
 * cambió de verdad se manda el HTML.
 *
 * La huella (md5 del cuerpo) va en la primera línea, dentro de un comentario
 * HTML «vivo:huella»; vivo.js la lee y la devuelve en `__h` en el pedido
 * siguiente. Termina siempre con exit.
 */
function vivo_responder(string $cuerpo): void
{
    $huella = md5($cuerpo);

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-LiteSpeed-Cache-Control: no-cache');

    // Nada de ETag ni de 304. Esto se probó contra el servidor de producción: la
    // CDN que tiene el hosting adelante se COME la cabecera `If-None-Match`
    // —no llega a PHP—, así que el servidor no tenía forma de enterarse de que
    // el navegador ya tenía esa versión y le mandaba la pantalla entera cada
    // vez. El camino que sí funciona es el que no depende de cabeceras: la
    // huella viaja adentro del HTML y vuelve como un parámetro más de la
    // dirección, que ningún intermediario puede tocar.
    if (isset($_GET['__h']) && (string)$_GET['__h'] === $huella) {
        http_response_code(204);   // sin contenido: no cambió nada
        exit;
    }

    echo '<!--vivo:' . $huella . "-->\n" . $cuerpo;
    exit;
}

/**
 * Dibuja una vista dentro del layout y la imprime.
 *
 * @param string $vista  nombre del archivo en app/views/, sin la extensión
 * @param array  $vars   variables para la vista; `bodyClass`, `extraHead` y
 *                       `extraFoot` además se pasan al layout
 * @param string $titulo título de la pestaña y de la barra (por defecto, el
 *                       nombre de la app)
 *
 * En un refresco automático (`__vivo`) no vuelve: responde sólo el cuerpo y
 * hace exit. Si la vista falla, se registra el error y se dibuja la pantalla
 * de error 500 dentro del layout.
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
        // el error y se muestra la pantalla de error con el diseño de
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

    // El refresco automático se lleva sólo el cuerpo: el menú, la cabecera y
    // los estilos ya están en la pantalla y no cambian.
    if (vivo_pedido()) {
        vivo_responder($cuerpo);
    }

    // Estos tres no son datos de la vista sino del marco que la rodea, y los
    // manda la página: `bodyClass` es lo que distingue una pantalla pública
    // —la de reservar una reunión, la de darse de baja— de una de adentro del
    // sistema. Se pasan al layout de forma explícita y no por el ámbito
    // compartido, por el mismo motivo que explica la clase Vista.
    $marco = ['content' => $cuerpo, 'title' => $titulo];
    foreach (['bodyClass', 'extraHead', 'extraFoot'] as $k) {
        if (isset($vars[$k])) { $marco[$k] = $vars[$k]; }
    }

    echo (new Vista(APP . '/views/layout.php', $marco))->html();
}

/**
 * Responde JSON y termina (exit).
 *
 * @param mixed $datos  lo que se serializa (sin escapar los acentos)
 * @param int   $codigo código HTTP
 */
function json_out($datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Una celda de CSV que Excel o LibreOffice no puedan tomar como fórmula.
 *
 * Lo que empieza con = + - @ (o una tabulación o un retorno) se ejecuta como
 * fórmula al abrir el archivo, y varias exportaciones llevan datos que escribe
 * cualquiera desde la web: un nombre como =HYPERLINK("https://…"&E2) mandaba
 * afuera los datos de otras filas con un clic. Se le antepone un apóstrofo,
 * que la planilla muestra como texto. Los números (un teléfono con +, un
 * importe negativo) quedan como están.
 */
function csv_celda(string $v): string
{
    if ($v === '' || !preg_match('/^[=+\-@\t\r]/', $v)) { return $v; }
    if (preg_match('/^[+\-]?[0-9][0-9 ().\/-]*$/', $v)) { return $v; }
    return "'" . $v;
}

/**
 * Descarga un listado como CSV y termina la ejecución.
 *
 * Va con BOM UTF-8 y separador ';' porque el equipo abre estos archivos con
 * Excel en español: sin eso, los acentos salen rotos y todo cae en una columna.
 * Cada celda pasa por csv_celda() para que no se ejecute como fórmula.
 *
 * El nombre de archivo va tal cual a la cabecera: lo arma siempre el código,
 * nunca el usuario.
 *
 * @param string                         $archivo   nombre sugerido para la descarga
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
            $linea[] = csv_celda((string)($fila[$k] ?? ''));
        }
        fputcsv($salida, $linea, ';', '"', '\\');
    }
    fclose($salida);
    exit;
}

// ============================================================
//  Formato y datos
// ============================================================

/**
 * Formatea un importe en formato argentino («$ 1.234,50»).
 *
 * Sólo distingue USD (US$); cualquier otra moneda se muestra con «$».
 */
function money($n, string $moneda = 'ARS'): string
{
    $sim = $moneda === 'USD' ? 'US$' : '$';
    return $sim . ' ' . number_format((float)$n, 2, ',', '.');
}

/**
 * Importes agrupados por moneda, en una línea: «$ 1.500,00 · US$ 200,00».
 *
 * El sistema no convierte monedas: sumar pesos con dólares da un número que
 * no es ninguna de las dos cosas. Los totales se muestran por separado.
 * Sin importes devuelve el cero en pesos.
 *
 * @param array<string,float|int|string> $porMoneda moneda => importe
 */
function money_por_moneda(array $porMoneda): string
{
    $partes = [];
    // Primero pesos, después dólares, después lo que haya.
    uksort($porMoneda, static fn($a, $b) => (['ARS' => 0, 'USD' => 1][$a] ?? 2) <=> (['ARS' => 0, 'USD' => 1][$b] ?? 2) ?: strcmp((string)$a, (string)$b));
    foreach ($porMoneda as $moneda => $importe) {
        if ((float)$importe == 0.0 && count($porMoneda) > 1) { continue; }
        $partes[] = money((float)$importe, (string)($moneda ?: 'ARS'));
    }
    return $partes ? implode(' · ', $partes) : money(0);
}

/**
 * Agrupa filas con columnas `moneda` y $campo en moneda => suma.
 *
 * @param array<int,array<string,mixed>> $filas
 * @return array<string,float>
 */
function sumar_por_moneda(array $filas, string $campo): array
{
    $out = [];
    foreach ($filas as $f) {
        $m = (string)($f['moneda'] ?? '') ?: 'ARS';
        $out[$m] = ($out[$m] ?? 0.0) + (float)($f[$campo] ?? 0);
    }
    return $out;
}

/** Una duración en segundos dicha como se lee: «10 min», «8 h», «1 h 30 min». */
function duracion_legible(int $seg): string
{
    if ($seg <= 0) { return 'sin límite'; }
    $h = intdiv($seg, 3600);
    $m = intdiv($seg % 3600, 60);
    if ($h && $m) { return $h . ' h ' . $m . ' min'; }
    if ($h)       { return $h . ' h'; }
    return max(1, $m) . ' min';
}

/**
 * Patrón para LIKE '%texto%' que busca el texto literal: % y _ escritos por
 * la persona dejan de ser comodines (con la barra invertida, el escape por
 * defecto de MariaDB).
 */
function like_contiene(string $texto): string
{
    return '%' . strtr($texto, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}

/**
 * Valor de la cabecera Content-Disposition para descargar un archivo con su
 * nombre original.
 *
 * Va dos veces: `filename` en ASCII (los acentos y lo raro pasan a «_») para
 * los navegadores viejos, y `filename*` en UTF-8 (RFC 6266 / 5987), que es el
 * que usan todos los actuales, así un «Cotización.pdf» se baja con su acento.
 * Las comillas, barras y saltos de línea se quitan: no pueden cortar la
 * cabecera ni meter otra.
 */
function descarga_disposicion(string $nombre): string
{
    $limpio = trim(str_replace(['"', '\\', '/', "\r", "\n", "\0"], '', $nombre));
    if ($limpio === '') { $limpio = 'archivo'; }
    // Con /u un carácter acentuado es UNA letra (un «_»), no sus dos bytes.
    // Si el nombre no es UTF-8 válido, /u falla y se cae al reemplazo por byte.
    $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $limpio) ?? (string) preg_replace('/[^\x20-\x7E]/', '_', $limpio);
    $limpio = mb_check_encoding($limpio, 'UTF-8') ? $limpio : $ascii;
    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($limpio);
}

/** Fecha corta dd/mm/aaaa. '—' si viene vacía, en ceros o no se entiende. */
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
 * Fecha larga en castellano ("jueves 30 de julio de 2026"). Sin argumento, hoy.
 * Una fecha que strtotime no entiende no está contemplada (daría un error de
 * tipos con strict_types).
 *
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

/** Iniciales para el avatar de respaldo: hasta dos palabras, '?' si no hay texto. */
function iniciales(string $texto): string
{
    $partes = preg_split('/\s+/', trim($texto)) ?: [];
    $ini = '';
    foreach (array_slice($partes, 0, 2) as $p) {
        if ($p !== '') { $ini .= mb_strtoupper(mb_substr($p, 0, 1)); }
    }
    return $ini !== '' ? $ini : '?';
}

/** Recorta un texto agregando puntos suspensivos; el resultado mide como mucho $largo caracteres. */
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

/** Tamaño de un archivo en la unidad que se entiende de un vistazo (B, KB, MB). */
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

/**
 * Normaliza un correo: minúsculas y sin espacios en los extremos.
 * Es la forma en que se guardan y se comparan los correos en todo el sistema.
 */
function normalizar_email(?string $email): string
{
    return strtolower(trim((string)$email));
}

// ---- Dominio fiscal argentino (mismo criterio que APP Tickets) ----
// El CUIT se guarda sólo con dígitos y se muestra con guiones.

/** Deja sólo dígitos. */
function normalizar_cuit($s): string
{
    return preg_replace('/\D/', '', (string)$s) ?? '';
}

/**
 * Valida CUIT/CUIL por dígito verificador (módulo 11).
 *
 * Un cálculo que da verificador 10 no corresponde a ningún CUIT válido
 * (en ese caso se asigna otro prefijo), así que se rechaza.
 */
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

/** Formatea CUIT como NN-NNNNNNNN-N; si no tiene 11 dígitos, lo devuelve como vino. */
function formatear_cuit($s): string
{
    $c = normalizar_cuit($s);
    return strlen($c) === 11
        ? substr($c, 0, 2) . '-' . substr($c, 2, 8) . '-' . substr($c, 10, 1)
        : (string)$s;
}

// ---- Paginación ----

/**
 * Cuántas filas por página quiere ver esta persona.
 *
 * Se elige en la pantalla y se recuerda mientras dure la sesión: quien trabaja
 * con una base grande no tiene por qué volver a elegir «100» en cada listado
 * que abre. Se limita a los valores de la lista para que nadie pida 50.000
 * filas escribiendo el número en la dirección y tire abajo la pantalla.
 */
function filas_por_pagina(): int
{
    $permitidos = [25, 50, 100];
    if (isset($_GET['por'])) {
        $p = (int) $_GET['por'];
        if (in_array($p, $permitidos, true)) {
            $_SESSION['filas_por_pagina'] = $p;
            return $p;
        }
    }
    $g = (int) ($_SESSION['filas_por_pagina'] ?? 0);
    return in_array($g, $permitidos, true) ? $g : 25;
}

/**
 * Calcula los datos de paginación.
 *
 * La página pedida se encierra entre 1 y la última: un «page=999» viejo no
 * deja una pantalla vacía. Si $per no se pasa, se usa la preferencia de la
 * sesión (filas_por_pagina).
 *
 * @param int        $total cantidad total de filas del listado
 * @param int|string $page  página pedida (suele venir de $_GET)
 * @return array{page:int, per:int, offset:int, paginas:int}
 */
function paginar(int $total, $page, ?int $per = null): array
{
    $per     = $per ?: filas_por_pagina();
    $page    = max(1, (int)$page);
    $paginas = max(1, (int)ceil($total / $per));
    $page    = min($page, $paginas);
    return ['page' => $page, 'per' => $per, 'offset' => ($page - 1) * $per, 'paginas' => $paginas];
}

/**
 * Reconstruye la query string actual cambiando algunos parámetros.
 *
 * @param array $cambios parámetro => valor; null o '' lo quita
 * @return string '?a=1&b=2', o '' si no queda ninguno (sin escapar para HTML)
 */
function query_con(array $cambios): string
{
    $q = $_GET;
    // Los parámetros del refresco automático NO son de la pantalla y no pueden
    // filtrarse a los enlaces. Si se colaran pasarían dos cosas, las dos malas:
    // hacer clic en «página 2» devolvería la pantalla sin diseño (porque sigue
    // pidiendo el refresco), y como la huella del contenido quedaría escrita
    // adentro del propio contenido, cada respuesta cambiaría respecto de la
    // anterior y el servidor volvería a mandar la pantalla entera cada vez.
    unset($q['r'], $q['__vivo'], $q['__h']);
    foreach ($cambios as $k => $v) {
        if ($v === null || $v === '') { unset($q[$k]); } else { $q[$k] = $v; }
    }
    return $q ? '?' . http_build_query($q) : '';
}

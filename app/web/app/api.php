<?php
declare(strict_types=1);

// ============================================================
//  API v1: lo que entra desde la web (intellihelp.tech).
//
//  La API de lectura (`api/estado`) se conforma con una clave: lo peor que
//  puede pasar si se filtra es que alguien lea unos contadores. Ésta escribe
//  —crea contactos, empresas y reuniones en el calendario de Carlos—, así que
//  la clave sola no alcanza:
//
//    · cada pedido va FIRMADO con un secreto que nunca viaja (HMAC-SHA256);
//    · la firma incluye la hora, y se acepta sólo ±5 minutos: un pedido
//      capturado no sirve para repetirlo mañana;
//    · la firma incluye la ruta completa, con la carpeta de la instalación:
//      una firma hecha para pruebas no sirve en producción, ni al revés;
//    · la firma incluye también la clave de idempotencia y el hash de la IP
//      del visitante: un pedido capturado no se puede reenviar cambiándolas
//      para que parezca otro pedido de otro visitante;
//    · una escritura firmada se acepta una sola vez: la misma firma repetida
//      dentro de la tolerancia se rechaza;
//    · cada escritura lleva una clave de idempotencia: si la web reintenta
//      porque se le cortó la conexión, la reunión no se duplica.
//
//  Contrato completo en md/INTEGRACION-WEB.md §5. Lo que está acá son las
//  piezas comunes; los controladores viven en pages/api/v1/.
//
//  Todo responde JSON, también los errores inesperados: el manejador global
//  de bootstrap.php contesta texto plano, y del otro lado hay un programa que
//  no sabe qué hacer con una frase en castellano.
// ============================================================

/** Tamaño máximo del cuerpo de un pedido, en bytes. */
const API_MAX_CUERPO = 16384;

/** Tolerancia del reloj, en segundos, entre la web y el CRM. */
const API_TOLERANCIA_SEG = 300;

/** Versión del texto de consentimiento: letras, números, punto, guión y guión bajo. */
const API_PATRON_VERSION = '/^[a-z0-9._-]{3,40}$/';

// ------------------------------------------------------------
//  Firma
// ------------------------------------------------------------

/**
 * El texto que se firma. Tiene que ser byte por byte el mismo de los dos lados,
 * por eso es una función aparte con sus propias pruebas.
 *
 * `$ruta` es la ruta con la consulta tal cual viaja en la línea del pedido
 * (`/crm-pruebas/api/v1/disponibilidad?tipo=…`); `$cuerpo`, los bytes exactos
 * del cuerpo, vacío en un GET. `$idem` y `$ipHash` son las cabeceras
 * X-ITH-Idempotency y X-ITH-Client-IP-Hash tal cual viajan (vacías en un GET):
 * de ellas dependen la idempotencia y los límites por visitante, así que no
 * pueden quedar fuera de lo firmado.
 */
function api_firma_canonica(string $ts, string $metodo, string $ruta, string $idem,
                            string $ipHash, string $cuerpo): string
{
    return $ts . "\n" . strtoupper($metodo) . "\n" . $ruta . "\n" . $idem . "\n" . $ipHash . "\n" . $cuerpo;
}

/** La firma: HMAC-SHA256 en hexadecimal. */
function api_firma(string $secreto, string $ts, string $metodo, string $ruta, string $idem,
                   string $ipHash, string $cuerpo): string
{
    return hash_hmac('sha256', api_firma_canonica($ts, $metodo, $ruta, $idem, $ipHash, $cuerpo), $secreto);
}

/**
 * ¿La marca de tiempo del pedido está dentro de la tolerancia?
 *
 * Segundos Unix, sin decimales, a no más de API_TOLERANCIA_SEG del reloj del
 * CRM, para un lado o para el otro. Un pedido capturado deja de servir a los
 * cinco minutos. Aparte de api_autenticar() para poder probarla sin servidor.
 */
function api_hora_valida(string $ts, ?int $ahora = null): bool
{
    return (bool) preg_match('/^\d{9,11}$/', $ts)
        && abs(($ahora ?? time()) - (int)$ts) <= API_TOLERANCIA_SEG;
}

/**
 * ¿La firma recibida es la que corresponde a ese pedido?
 *
 * Se acepta en mayúsculas o minúsculas (es hexadecimal) y se compara con
 * hash_equals(), que tarda lo mismo acierte o no: comparar con === dejaría
 * adivinar la firma de a un carácter midiendo cuánto tarda la respuesta.
 */
function api_firma_coincide(string $secreto, string $ts, string $metodo, string $ruta, string $idem,
                            string $ipHash, string $cuerpo, string $firma): bool
{
    $firma = strtolower(trim($firma));
    if ($secreto === '' || !preg_match('/^[0-9a-f]{64}$/', $firma)) { return false; }
    return hash_equals(api_firma($secreto, $ts, $metodo, $ruta, $idem, $ipHash, $cuerpo), $firma);
}

/**
 * ¿Esta firma ya se usó? Registra la firma de una escritura y devuelve true si
 * ya estaba registrada.
 *
 * La hora sola deja cinco minutos para repetir un pedido capturado tal cual.
 * Cada escritura legítima de la web lleva su propia hora y su propia firma
 * (un reintento se vuelve a firmar), así que una firma repetida sólo puede
 * ser una copia. Queda registrada diez minutos, más que toda la tolerancia
 * para un lado y para el otro. Si la tabla no está, no se frena a nadie: la
 * idempotencia sigue impidiendo que el pedido repetido duplique algo.
 */
function api_firma_usada(string $firma): bool
{
    $clave = 'sig:' . strtolower($firma);
    $vence = date('Y-m-d H:i:s', time() + 2 * API_TOLERANCIA_SEG);
    try {
        if (q('INSERT IGNORE INTO api_limites (clave, ventana, conteo, vence_en) VALUES (?, 0, 1, ?)',
              [$clave, $vence])->rowCount() === 1) {
            return false;
        }
        // Ya estaba. Si es un registro vencido que la limpieza todavía no
        // borró, se renueva y el pedido pasa (a esa altura la hora ya no lo
        // dejaría pasar si fuera una copia).
        return q('UPDATE api_limites SET vence_en = ? WHERE clave = ? AND ventana = 0 AND vence_en < NOW()',
                 [$vence, $clave])->rowCount() === 0;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * ¿El secreto de la instalación sirve para cifrar el de las claves?
 *
 * `crm_secret()` tiene un respaldo derivado del nombre de la base para no
 * guardar los tokens de Google en claro, pero ese respaldo se adivina: con él,
 * cualquiera con el nombre de la base podría descifrar el secreto de la web y
 * firmar pedidos. Para la API de escritura se exige uno configurado explícitamente.
 */
function api_secreto_app_ok(): bool
{
    return strlen((string)($GLOBALS['CONFIG']['app']['secret'] ?? '')) >= 32;
}

/**
 * ¿Ya corrió la migración 008?
 *
 * El código se puede subir antes de migrar —por descuido o por orden de los
 * pasos— y en ese caso la API tiene que decirlo, no reventar con un 500.
 */
function api_migracion_lista(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try {
        db()->query('SELECT secreto_cifrado FROM api_tokens LIMIT 1');
        db()->query('SELECT id FROM entradas_web LIMIT 1');
        db()->query('SELECT clave FROM api_limites LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

// ------------------------------------------------------------
//  Respuestas
// ------------------------------------------------------------

/**
 * Datos del pedido en curso para la auditoría: acción, clave y entrada.
 * Se van completando a medida que se sabe más; la línea se escribe una sola
 * vez, al responder.
 */
function api_contexto(array $cambios = []): array
{
    static $ctx = ['accion' => 'api/v1', 'actor' => 'desconocido', 'entrada_id' => null, 'escrito' => false];
    foreach ($cambios as $k => $v) { $ctx[$k] = $v; }
    return $ctx;
}

/**
 * Deja la línea del pedido en la auditoría de la API.
 *
 * NUNCA lleva datos de la persona: ni nombre, ni correo, ni teléfono, ni
 * mensaje. La auditoría la lee cualquier administrador y se conserva sin
 * plazo; los datos personales viven en `entradas_web`, que sí se anonimiza.
 */
function api_auditar(int $codigo, string $error = ''): void
{
    $ctx = api_contexto();
    if ($ctx['escrito']) { return; }
    api_contexto(['escrito' => true]);
    $detalle = 'HTTP ' . $codigo . ($error !== '' ? ' · ' . $error : '')
             . ($ctx['entrada_id'] ? ' · entrada ' . (int)$ctx['entrada_id'] : '');
    log_api((string)$ctx['accion'], [
        'tipo'       => $codigo === 401 || $codigo === 403 ? 'auth' : ($codigo >= 500 ? 'error' : 'accion'),
        'actor'      => (string)$ctx['actor'],
        'entidad'    => $ctx['entrada_id'] ? 'entrada_web' : null,
        'entidad_id' => $ctx['entrada_id'] ? (int)$ctx['entrada_id'] : null,
        'detalle'    => $detalle,
    ]);
}

/** Manda las cabeceras y el cuerpo, sin cortar la ejecución. */
function api_enviar(int $codigo, array $datos): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-LiteSpeed-Cache-Control: no-cache');
    }
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    api_auditar($codigo, (string)($datos['error'] ?? ''));
}

function api_responder(int $codigo, array $datos): never
{
    api_enviar($codigo, $datos);
    exit;
}

/**
 * Responde y deja el proceso vivo para lo lento.
 *
 * La reserva contesta apenas queda guardada en la base; el evento de Google con
 * Meet y el correo se hacen después, con la conexión ya cerrada. Si la web
 * esperara todo eso, un corte por tiempo la dejaría mostrando un error con la
 * reunión ya tomada.
 */
function api_responder_y_seguir(int $codigo, array $datos): void
{
    $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('Content-Length: ' . strlen((string)$cuerpo));
        header('Connection: close');
    }
    echo $cuerpo;
    api_auditar($codigo);
    finish_request();
}

function api_error(int $codigo, string $error, string $mensaje, array $extra = []): never
{
    api_responder($codigo, ['ok' => false, 'error' => $error, 'mensaje' => $mensaje] + $extra);
}

/** 405 con la lista de métodos que sí se aceptan. */
function api_metodo(array $permitidos): void
{
    if (in_array(method(), $permitidos, true)) { return; }
    if (!headers_sent()) { header('Allow: ' . implode(', ', $permitidos)); }
    api_error(405, 'metodo_no_permitido', 'Este recurso sólo acepta ' . implode(' y ', $permitidos) . '.');
}

/**
 * Error inesperado: queda en la auditoría con su código ERR-XXXXXX y la web
 * recibe ese código, nunca la traza. Con el código se encuentra el renglón en
 * la sección CRON sin exponer nada del servidor.
 */
function api_fallo(\Throwable $e): never
{
    // Si falta una tabla es que el código llegó antes que la migración.
    if ($e instanceof \PDOException && ($e->getCode() === '42S02' || $e->getCode() === '42S22')) {
        api_error(503, 'migracion_pendiente', 'El CRM se está actualizando. Probá de nuevo en unos minutos.');
    }
    $codigo = 'ERR-' . strtoupper(substr(md5($e->getFile() . ':' . $e->getLine()), 0, 6));
    if (function_exists('log_error')) {
        log_error('error.api', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }
    error_log('[crm] api: ' . $e);
    api_error(500, 'error_interno', 'No pudimos procesar el pedido. Ya quedó registrado.', ['codigo' => $codigo]);
}

// ------------------------------------------------------------
//  Autenticación
// ------------------------------------------------------------

/** Una cabecera del pedido (`X-ITH-Key` → `HTTP_X_ITH_KEY`). */
function api_cabecera(string $nombre): string
{
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $nombre));
    return trim((string)($_SERVER[$k] ?? ''));
}

/**
 * El cuerpo crudo, leído una sola vez. Se corta en `$max` + 1 bytes: no tiene
 * sentido cargar en memoria un cuerpo de varios megas para después rechazarlo.
 */
function api_cuerpo_crudo(int $max = API_MAX_CUERPO): string
{
    static $cuerpo = null;
    if ($cuerpo === null) {
        $largo = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($largo > $max) {
            api_error(413, 'cuerpo_demasiado_grande', 'El pedido es demasiado grande.');
        }
        $leido = file_get_contents('php://input', false, null, 0, $max + 1);
        $cuerpo = $leido === false ? '' : $leido;
    }
    if (strlen($cuerpo) > $max) {
        api_error(413, 'cuerpo_demasiado_grande', 'El pedido es demasiado grande.');
    }
    return $cuerpo;
}

/**
 * Autentica el pedido. Devuelve la fila de `api_tokens` o responde y termina.
 *
 * El orden importa: primero lo que no necesita la base (cabeceras, hora,
 * configuración), después la clave, y recién al final la firma, que es lo
 * único que prueba que quien llama tiene el secreto.
 */
function api_autenticar(string $alcance = 'escritura'): array
{
    $clave = api_cabecera('X-ITH-Key');
    $ts    = api_cabecera('X-ITH-Timestamp');
    $firma = api_cabecera('X-ITH-Signature');
    if ($clave === '') {
        api_error(401, 'falta_clave', 'Falta la clave de acceso.');
    }
    if (!api_hora_valida($ts)) {
        api_error(401, 'hora_fuera_de_rango', 'La hora del pedido no coincide con la del CRM.');
    }

    if (!api_secreto_app_ok()) {
        api_error(503, 'configuracion_incompleta', 'La API no está habilitada en esta instalación.');
    }
    if (!api_migracion_lista()) {
        api_error(503, 'migracion_pendiente', 'El CRM se está actualizando. Probá de nuevo en unos minutos.');
    }

    $token = q1('SELECT * FROM api_tokens WHERE token_hash = ? AND activo = 1', [hash('sha256', $clave)]);
    if (!$token) {
        api_error(401, 'clave_invalida', 'La clave no existe o está dada de baja.');
    }
    api_contexto(['actor' => (string)$token['nombre']]);

    if ($alcance === 'escritura' && ($token['scope'] !== 'escritura' || empty($token['secreto_cifrado']))) {
        api_error(403, 'sin_permiso', 'Esta clave no tiene permiso para escribir en el CRM.');
    }

    $secreto = descifrar((string)$token['secreto_cifrado']);
    if ($secreto === '') {
        // Pasa si cambió el secreto de la instalación: lo cifrado ya no se
        // puede leer y la única salida es generar otra clave.
        api_error(503, 'configuracion_incompleta', 'Hay que generar otra clave para la web.');
    }

    $esGet = method() === 'GET';
    if (!api_firma_coincide($secreto, $ts, method(), (string)($_SERVER['REQUEST_URI'] ?? ''),
                            $esGet ? '' : api_cabecera('X-ITH-Idempotency'),
                            $esGet ? '' : api_cabecera('X-ITH-Client-IP-Hash'),
                            $esGet ? '' : api_cuerpo_crudo(), $firma)) {
        api_error(401, 'firma_invalida', 'La firma del pedido no es válida.');
    }
    // Las consultas de horarios no cambian nada: repetirlas no hace daño, y
    // dos visitantes en el mismo segundo pueden generar la misma firma.
    if (!$esGet && api_firma_usada($firma)) {
        api_error(401, 'firma_repetida', 'Este pedido ya se recibió.');
    }

    q('UPDATE api_tokens SET usos = usos + 1, ultimo_uso = NOW() WHERE id = ?', [(int)$token['id']]);
    return $token;
}

/** La clave de idempotencia del pedido, obligatoria en las escrituras. */
function api_idempotencia(): string
{
    $idem = api_cabecera('X-ITH-Idempotency');
    if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $idem)) {
        api_error(400, 'parametros_invalidos', 'Falta la clave de idempotencia o no tiene el formato esperado.');
    }
    return $idem;
}

/**
 * El hash de la IP del visitante, que calcula la web con su propia sal.
 * El CRM nunca ve la IP: con el hash alcanza para limitar los envíos.
 */
function api_ip_visitante(): string
{
    $h = strtolower(api_cabecera('X-ITH-Client-IP-Hash'));
    if (!preg_match('/^[0-9a-f]{64}$/', $h)) {
        api_error(400, 'parametros_invalidos', 'Falta el identificador del visitante.');
    }
    return $h;
}

/** Lee y decodifica el cuerpo JSON (máximo $max bytes) o responde 400/413/415. */
function api_cuerpo_json(int $max = API_MAX_CUERPO): array
{
    $tipo = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''))[0]));
    if ($tipo !== 'application/json') {
        api_error(415, 'tipo_de_contenido', 'El cuerpo tiene que ser JSON (application/json).');
    }
    $crudo = api_cuerpo_crudo($max);
    try {
        $j = json_decode($crudo, true, 8, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        api_error(400, 'json_invalido', 'El cuerpo no es un JSON válido.');
    }
    if (!is_array($j) || ($j !== [] && array_is_list($j))) {
        api_error(400, 'json_invalido', 'El cuerpo tiene que ser un objeto JSON.');
    }
    return $j;
}

/**
 * Huella del pedido para la idempotencia: la misma clave con OTRO pedido es
 * un error de la web, no un reintento. Se calcula sobre el JSON con las claves
 * ordenadas y no sobre los bytes, así un reintento que vuelve a codificar el
 * mismo contenido con otro espaciado sigue siendo el mismo pedido.
 */
function api_cuerpo_hash(array $j): string
{
    $ordenar = static function ($v) use (&$ordenar) {
        if (!is_array($v)) { return $v; }
        if (!array_is_list($v)) { ksort($v, SORT_STRING); }
        return array_map($ordenar, $v);
    };
    return hash('sha256', (string) json_encode($ordenar($j), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** 429 con Retry-After si se pasa. */
function api_limitar(string $clave, int $max, int $segundos): void
{
    if (!limite_superado($clave, $max, $segundos)) { return; }
    $espera = limite_espera($segundos);
    if (!headers_sent()) { header('Retry-After: ' . $espera); }
    api_error(429, 'demasiados_pedidos', 'Llegaron demasiados pedidos seguidos. Probá de nuevo más tarde.',
              ['reintentar_en' => $espera]);
}

// ------------------------------------------------------------
//  Validación (md/INTEGRACION-WEB.md §6.2, la misma regla en la web)
// ------------------------------------------------------------

/** ¿Es texto de una sola línea? */
function api_una_linea(string $s): bool
{
    return !preg_match('/[\x00-\x1F\x7F]/u', $s);
}

/** Saca los caracteres de control, salvo el salto de línea y la tabulación. */
function api_limpiar_texto(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
}

/**
 * Lee una fecha ISO 8601 con desplazamiento (o segundos Unix) y la devuelve
 * en segundos. Null si no se entiende.
 */
function api_leer_instante($v): ?int
{
    if (is_int($v)) { return $v > 0 ? $v : null; }
    if (!is_string($v)) { return null; }
    $v = trim($v);
    if (preg_match('/^\d{9,11}$/', $v)) { return (int)$v; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $v)) {
        return null;
    }
    try {
        return (new DateTimeImmutable($v))->getTimestamp();
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Limpia los datos de origen (canal, UTM, página…).
 *
 * Nunca hacen fallar el pedido: lo que no cumple el formato se guarda vacío.
 * Un parámetro raro en la dirección de la landing no puede costarnos una
 * reunión. Por lo mismo, las claves que no se conocen se ignoran en vez de
 * rechazarse.
 */
function api_limpiar_origen($o): array
{
    $o = is_array($o) ? $o : [];
    $txt = static function ($v, int $max): string {
        $v = is_scalar($v) ? trim((string)$v) : '';
        return ($v !== '' && api_una_linea($v) && mb_strlen($v) <= $max) ? $v : '';
    };
    $ruta = static function ($v): string {
        $v = is_string($v) ? trim($v) : '';
        return (preg_match('#^/[A-Za-z0-9/_.-]{0,199}$#', $v) && !str_contains($v, '..') && !str_contains($v, '//'))
            ? $v : '';
    };
    $idClic = static function ($v): string {
        $v = is_string($v) ? trim($v) : '';
        return preg_match('/^[A-Za-z0-9._~-]{1,200}$/', $v) ? $v : '';
    };
    $ref = is_string($o['referrer'] ?? null) ? trim($o['referrer']) : '';
    if ($ref !== '' && (mb_strlen($ref) > 300 || !preg_match('#^https?://#i', $ref)
                        || !filter_var($ref, FILTER_VALIDATE_URL) || !api_una_linea($ref))) {
        $ref = '';
    }
    $visitas = $o['visitas_landing'] ?? null;
    $visitas = (is_int($visitas) || (is_string($visitas) && ctype_digit($visitas)))
             && (int)$visitas >= 0 && (int)$visitas <= 999 ? (int)$visitas : null;

    return [
        'pagina'          => $ruta($o['pagina'] ?? ''),
        'canal'           => $txt($o['canal'] ?? '', 80),
        'utm_source'      => $txt($o['utm_source'] ?? '', 150),
        'utm_medium'      => $txt($o['utm_medium'] ?? '', 150),
        'utm_campaign'    => $txt($o['utm_campaign'] ?? '', 150),
        'utm_term'        => $txt($o['utm_term'] ?? '', 150),
        'utm_content'     => $txt($o['utm_content'] ?? '', 150),
        'gclid'           => $idClic($o['gclid'] ?? ''),
        'li_fat_id'       => $idClic($o['li_fat_id'] ?? ''),
        'referrer'        => $ref,
        'landing'         => $ruta($o['landing'] ?? ''),
        'visitas_landing' => $visitas,
    ];
}

/**
 * Valida persona + consentimiento + origen (y, en la reserva, tipo, vendedor
 * e inicio).
 *
 * Validación estricta: una clave desconocida en el pedido, en `persona` o en
 * `consentimiento` es un error. Si la web empieza a mandar un campo nuevo, se
 * entera en la primera prueba en vez de perderlo en silencio durante meses.
 * El origen es la excepción (ver api_limpiar_origen()).
 *
 * @return array{0:array,1:array<string,string>} [datos limpios, errores por campo]
 */
function api_validar_entrada(array $j, bool $esReserva): array
{
    $err = [];
    $permitidas = ['persona', 'consentimiento', 'novedades', 'origen'];
    if ($esReserva) { array_push($permitidas, 'tipo', 'vendedor', 'inicio'); }
    foreach (array_keys($j) as $k) {
        if (!in_array($k, $permitidas, true)) { $err[(string)$k] = 'Campo desconocido.'; }
    }

    // ---- Persona ----
    $p = $j['persona'] ?? null;
    if (!is_array($p) || ($p !== [] && array_is_list($p))) {
        $err['persona'] = 'Faltan los datos de la persona.';
        $p = [];
    }
    foreach (array_keys($p) as $k) {
        if (!in_array($k, ['nombre', 'empresa', 'email', 'telefono', 'servicio', 'mensaje'], true)) {
            $err['persona.' . $k] = 'Campo desconocido.';
        }
    }
    $str = static fn($v): ?string => is_string($v) ? trim($v) : ($v === null ? '' : null);

    // Nombre y empresa vuelven en el correo de confirmación, que sale con la
    // marca de la empresa a la dirección que escribió el visitante: no pueden
    // llevar direcciones web (texto_sin_enlaces()).
    $nombre = $str($p['nombre'] ?? null);
    if ($nombre === null || mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120
        || !api_una_linea($nombre) || !preg_match('/\p{L}/u', $nombre) || !texto_sin_enlaces($nombre)) {
        $err['nombre'] = 'Escribí tu nombre.';
    }

    $empresa = $str($p['empresa'] ?? null);
    if ($empresa === null || $empresa === '' || mb_strlen($empresa) > 200 || !api_una_linea($empresa)
        || !texto_sin_enlaces($empresa)) {
        $err['empresa'] = 'Escribí el nombre de tu empresa.';
    }

    $email = $str($p['email'] ?? null);
    $email = $email === null ? null : normalizar_email($email);
    if ($email === null || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err['email'] = 'El correo no es válido.';
    }

    $telefono = $str($p['telefono'] ?? null);
    if ($telefono === null || mb_strlen($telefono) > 60 || !telefono_valido($telefono)) {
        $err['telefono'] = 'El teléfono no parece un número.';
    }

    $servicio = $str($p['servicio'] ?? null);
    if ($servicio === null || mb_strlen($servicio) > 120 || !api_una_linea($servicio)) {
        $err['servicio'] = 'El servicio elegido no es válido.';
    }

    $mensaje = $str($p['mensaje'] ?? null);
    $mensaje = $mensaje === null ? null : trim(api_limpiar_texto($mensaje));
    if ($mensaje === null || mb_strlen($mensaje) > 3000) {
        $err['mensaje'] = 'El mensaje es demasiado largo.';
    }

    // ---- Consentimiento (Ley 25.326, art. 5) ----
    $c = $j['consentimiento'] ?? null;
    if (!is_array($c) || ($c !== [] && array_is_list($c))) { $c = []; }
    foreach (array_keys($c) as $k) {
        if (!in_array($k, ['aceptado', 'fecha', 'version', 'texto'], true)) {
            $err['consentimiento.' . $k] = 'Campo desconocido.';
        }
    }
    if (($c['aceptado'] ?? null) !== true) {
        $err['consentimiento'] = 'Falta aceptar el uso de los datos.';
    }
    $version = is_string($c['version'] ?? null) ? trim($c['version']) : '';
    if (!preg_match(API_PATRON_VERSION, $version)) {
        $err['consentimiento.version'] = 'Falta la versión del texto aceptado.';
    }
    $fechaTs = api_leer_instante($c['fecha'] ?? null);
    if ($fechaTs === null || abs(time() - $fechaTs) > 86400) {
        $err['consentimiento.fecha'] = 'La fecha del consentimiento no es válida.';
    }
    $texto = $c['texto'] ?? '';
    if (!is_string($texto) || mb_strlen($texto) > 2000) {
        $err['consentimiento.texto'] = 'El texto aceptado no es válido.';
        $texto = '';
    }

    $novedades = $j['novedades'] ?? false;
    if (!is_bool($novedades)) {
        $err['novedades'] = 'Tiene que ser verdadero o falso.';
        $novedades = false;
    }

    $limpio = [
        'nombre'                 => (string)$nombre,
        'empresa'                => (string)$empresa,
        'email'                  => (string)$email,
        'telefono'               => (string)$telefono,
        'servicio'               => (string)$servicio,
        'mensaje'                => (string)$mensaje,
        'consentimiento_en'      => $fechaTs !== null ? date('Y-m-d H:i:s', $fechaTs) : '',
        'consentimiento_version' => $version,
        // Se guarda la huella del texto, no el texto: alcanza para probar
        // qué versión exacta aceptó, sin repetir el mismo párrafo mil veces.
        'consentimiento_hash'    => trim($texto) !== '' ? hash('sha256', $texto) : null,
        'novedades'              => $novedades,
        'origen'                 => api_limpiar_origen($j['origen'] ?? []),
    ];

    // ---- Reserva ----
    if ($esReserva) {
        $tipo = is_string($j['tipo'] ?? null) ? $j['tipo'] : '';
        if (!preg_match('/^[a-z0-9-]{1,60}$/', $tipo)) { $err['tipo'] = 'Falta el tipo de reunión.'; }
        $vendedor = $j['vendedor'] ?? '';
        if (!is_string($vendedor) || ($vendedor !== '' && !preg_match('/^[a-z0-9._-]{1,64}$/', $vendedor))) {
            $err['vendedor'] = 'El vendedor no es válido.';
            $vendedor = '';
        }
        $inicio = api_leer_instante($j['inicio'] ?? null);
        if ($inicio === null) { $err['inicio'] = 'Elegí un horario.'; }
        $limpio += ['tipo' => $tipo, 'vendedor' => $vendedor, 'inicio' => (int)$inicio];
    }

    return [$limpio, $err];
}

/**
 * Si hubo errores de validación responde 422 y termina. La falta de
 * consentimiento tiene su propio código: la web la muestra junto a la casilla,
 * no como un error genérico del formulario.
 */
function api_exigir_validos(array $errores): void
{
    if (!$errores) { return; }
    $soloConsentimiento = isset($errores['consentimiento'])
        && !array_filter(array_keys($errores), static fn($k) => !str_starts_with((string)$k, 'consentimiento'));
    if ($soloConsentimiento) {
        api_error(422, 'falta_consentimiento', 'Para seguir hace falta aceptar el uso de los datos.',
                  ['campos' => $errores]);
    }
    api_error(422, 'datos_invalidos', 'Revisá los datos marcados.', ['campos' => $errores]);
}

// ------------------------------------------------------------
//  Idempotencia
// ------------------------------------------------------------

/** Lo que se guarda en `entradas_web.respuesta` para contestar los reintentos. */
function api_respuesta_guardable(int $codigo, array $datos): string
{
    return (string) json_encode(['codigo' => $codigo, 'datos' => $datos], JSON_UNESCAPED_UNICODE);
}

/**
 * Contesta un pedido cuya clave de idempotencia ya se usó.
 *
 *  · mismo pedido y ya resuelto → la respuesta original, con `repetida`;
 *  · mismo pedido todavía en curso → 409 en_proceso (la web reintenta);
 *  · otro pedido con la misma clave → 409 idempotencia_reusada.
 *
 * Las que terminaron con error no llegan acá: se retoman (api_entrada_abrir()).
 */
function api_responder_previa(array $previa, string $cuerpoHash): never
{
    api_contexto(['entrada_id' => (int)$previa['id']]);
    [$codigo, $datos] = api_previa_respuesta($previa, $cuerpoHash);
    api_responder($codigo, $datos);
}

/**
 * Qué se contesta a un pedido cuya clave de idempotencia ya se usó: [código,
 * cuerpo]. Separada de api_responder_previa() para poder probarla sin base.
 */
function api_previa_respuesta(array $previa, string $cuerpoHash): array
{
    $error = static fn(string $e, string $m): array => ['ok' => false, 'error' => $e, 'mensaje' => $m];

    if (!hash_equals((string)($previa['cuerpo_hash'] ?? ''), $cuerpoHash)) {
        return [409, $error('idempotencia_reusada', 'Esa clave de idempotencia ya se usó con otro pedido.')];
    }
    $guardada = json_decode((string)($previa['respuesta'] ?? ''), true);
    $conDatos = is_array($guardada) && isset($guardada['datos']) && is_array($guardada['datos']);
    if (($previa['estado'] ?? '') === 'ok' && !$conDatos) {
        // Quedó resuelta pero el proceso se cortó antes de guardar la
        // respuesta: lo que importa es que entró, y eso se puede contestar.
        return [200, ['ok' => true, 'repetida' => true, 'entrada_id' => (int)$previa['id']]];
    }
    if (($previa['estado'] ?? '') === 'procesando' || !$conDatos) {
        return [409, $error('en_proceso', 'Ese pedido se está procesando. Probá de nuevo en unos segundos.')];
    }
    $codigo = (int)($guardada['codigo'] ?? 200);
    $datos  = $guardada['datos'];
    $datos['repetida'] = true;
    return [$codigo === 201 ? 200 : $codigo, $datos];
}

/**
 * Busca un pedido anterior con la misma clave de idempotencia y, si ya se
 * resolvió o se está resolviendo, lo contesta y termina. Si terminó con error
 * deja seguir: se va a retomar.
 *
 * Se llama ANTES de los límites por visitante: la web reintenta justamente
 * cuando no recibió la respuesta, y un reintento no puede terminar en
 * «demasiados pedidos».
 */
function api_atender_repetido(int $tokenId, string $idem, string $cuerpoHash): ?array
{
    $previa = q1('SELECT * FROM entradas_web WHERE token_id = ? AND idempotencia = ?', [$tokenId, $idem]);
    if ($previa && $previa['estado'] !== 'error') { api_responder_previa($previa, $cuerpoHash); }
    return $previa;
}

/**
 * Deja abierta la entrada del pedido y devuelve su id: una fila nueva, o la
 * que había quedado con error, retomada. Si en el medio apareció otro pedido
 * con la misma clave, lo contesta y termina.
 */
function api_entrada_abrir(array $fila, ?array $previa, string $cuerpoHash): int
{
    if ($previa === null) {
        $id = entrada_web_guardar($fila, $previa);
        if ($id > 0) { return $id; }
    }
    if ($previa['estado'] === 'error' && entrada_web_retomar((int)$previa['id'], $fila)) {
        return (int)$previa['id'];
    }
    $actual = q1('SELECT * FROM entradas_web WHERE id = ?', [(int)$previa['id']]) ?? $previa;
    api_responder_previa($actual, $cuerpoHash);
}

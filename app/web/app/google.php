<?php
declare(strict_types=1);

// ============================================================
//  Google Workspace — OAuth 2.0 (Authorization Code, server-side)
//
//  Un solo consentimiento cubre las dos cosas que necesitamos:
//    · identidad  → login de la app (openid/email/profile)
//    · calendario → crear reuniones con Google Meet y leer la ocupación
//
//  Por eso pedimos acceso OFFLINE: guardamos el refresh_token del usuario
//  (cifrado) para poder agendar reuniones aunque no tenga sesión abierta —
//  la agenda pública la usa un cliente de afuera, no el vendedor.
//
//  Flujo: pages/google-login.php genera el `state` (en la sesión) y redirige a
//  google_auth_url(); Google vuelve a pages/google-callback.php, que valida el
//  state, cambia el código por tokens (google_exchange_code()), lee la
//  identidad del id_token y guarda los tokens (google_guardar_tokens()).
//  Después, gcal.php pide un access_token vigente con google_access_token(),
//  que lo renueva solo con el refresh_token.
//
//  Tabla: google_tokens (una fila por usuario; refresh y access token
//  cifrados con AES-256-GCM, ver cifrar()). Configuración: $CONFIG['google']
//  (client_id, client_secret, allowed_domain, auto_provision) y
//  $CONFIG['app']['secret'] (la clave de cifrado). El login es del núcleo; el
//  calendario lo usa el módulo Agenda.
//
//  Trampa: si cambia `app.secret`, todo lo cifrado deja de poder leerse
//  (descifrar() devuelve ''): cada vendedor tiene que volver a entrar con
//  Google, y las claves de escritura de la API hay que generarlas de nuevo.
// ============================================================

/** Permisos que pide la aplicación. */
const GOOGLE_SCOPES = [
    'openid',
    'email',
    'profile',
    'https://www.googleapis.com/auth/calendar.events',
    'https://www.googleapis.com/auth/calendar.events.freebusy',
];

/**
 * Permisos del calendario que pidió la aplicación y que Google NO concedió.
 *
 * `$concedidos` es el campo `scope` de la respuesta de tokens (separado por
 * espacios). Vacío = Google no lo informó: no se puede saber, y no se da por
 * faltante nada.
 *
 * @return string[] los permisos de calendario que faltan ([] si están todos)
 */
function google_permisos_faltantes(string $concedidos): array
{
    $tiene = preg_split('/\s+/', trim($concedidos), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    // El permiso completo del calendario incluye los dos que se piden.
    if (!$tiene || in_array('https://www.googleapis.com/auth/calendar', $tiene, true)) { return []; }
    $calendario = array_filter(GOOGLE_SCOPES, static fn(string $s): bool => str_contains($s, '/auth/calendar'));
    return array_values(array_diff($calendario, $tiene));
}

/** La sección `google` de la configuración del servidor ([] si no está). */
function google_cfg(): array
{
    return $GLOBALS['CONFIG']['google'] ?? [];
}

/** ¿Está configurado el cliente OAuth (client_id y client_secret)? */
function google_enabled(): bool
{
    $g = google_cfg();
    return !empty($g['client_id']) && !empty($g['client_secret']);
}

/**
 * URL absoluta de callback. Debe coincidir EXACTA con la registrada en la
 * consola de Google. Se toma de la URL canónica para que no cambie según el
 * host por el que entró el usuario (subdominio o subcarpeta).
 */
function google_redirect_uri(): string
{
    $uri = url_abs('google-callback');
    // El sitio se sirve siempre por HTTPS; si el origen detrás del CDN reporta
    // http, Google rechazaría con redirect_uri_mismatch.
    return preg_replace('#^http://#', 'https://', $uri);
}

/**
 * URL a la que enviamos al usuario para autenticarse.
 *
 * @param string $state                valor al azar guardado en la sesión; vuelve
 *                                     en el callback y se compara (anti-CSRF)
 * @param bool   $forzarConsentimiento pide la pantalla de permisos (prompt=consent),
 *                                     única forma de que Google mande el refresh_token
 */
function google_auth_url(string $state, bool $forzarConsentimiento = false): string
{
    $g = google_cfg();
    $params = [
        'client_id'             => $g['client_id'],
        'redirect_uri'          => google_redirect_uri(),
        'response_type'         => 'code',
        'state'                 => $state,
        'scope'                 => implode(' ', GOOGLE_SCOPES),
        'access_type'           => 'offline',
        'include_granted_scopes' => 'true',
        // Sin 'consent' Google no vuelve a mandar el refresh_token en los
        // logins siguientes. Hoy pages/google-login.php lo pide SIEMPRE (pasa
        // true): ver ahí por qué no alcanza con pedirlo «la primera vez».
        'prompt'                => $forzarConsentimiento ? 'consent select_account' : 'select_account',
    ];
    // `hd` sólo filtra el selector de cuentas de Google: se puede saltear
    // editando la URL. El control real del dominio está en el callback, sobre
    // el claim `hd` del id_token.
    if (!empty($g['allowed_domain'])) {
        $params['hd'] = $g['allowed_domain'];
    }
    // Con ITHub, quien conecta Calendar ya está adentro: se le propone su
    // propia cuenta (el callback igual exige que sea esa).
    if (!empty($_SESSION['uid']) && function_exists('ithub_habilitado') && ithub_habilitado() && ($yo = auth_user())) {
        $params['login_hint'] = $yo['email'];
    }
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/** Después de un invalid_grant, cuánto se espera antes de volver a probar la renovación. */
const GOOGLE_PAUSA_INVALIDO_SEG = 900;

/**
 * POST a un endpoint de Google y devuelve el JSON decodificado.
 *
 * No mira el código HTTP: el error, si lo hay, viene en el cuerpo
 * ('error', 'error_description'). Sin conexión devuelve [].
 *
 * @param int $timeout segundos de espera en total (la conexión, como mucho 5)
 */
function google_post(string $url, array $campos, int $timeout = 20): array
{
    $timeout = max(2, $timeout);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($campos),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return is_string($res) ? (json_decode($res, true) ?: []) : [];
}

/**
 * Intercambia el "code" por tokens: access_token, id_token, expires_in, scope
 * y, sólo si hubo consentimiento explícito, refresh_token. El redirect_uri
 * tiene que ser el mismo que se mandó en google_auth_url().
 */
function google_exchange_code(string $code): array
{
    $g = google_cfg();
    return google_post('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
}

/**
 * Renueva el access_token a partir del refresh_token. Si el permiso fue
 * revocado, Google responde {'error': 'invalid_grant'} y no hay access_token.
 *
 * @param int $timeout segundos de espera (ver google_post())
 */
function google_refresh(string $refreshToken, int $timeout = 20): array
{
    $g = google_cfg();
    return google_post('https://oauth2.googleapis.com/token', [
        'refresh_token' => $refreshToken,
        'client_id'     => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'grant_type'    => 'refresh_token',
    ], $timeout);
}

/**
 * Decodifica el payload de un id_token (JWT) recibido directo de Google por TLS.
 *
 * No verifica la firma, ni `aud`, ni `exp`: OpenID Connect lo permite cuando
 * el token llega en la respuesta del endpoint de tokens por TLS, como acá.
 * Nunca usar esta función con un id_token que venga del navegador.
 *
 * @return array los claims (email, email_verified, hd, name, picture…), o [] si no se entiende
 */
function google_decode_id_token(string $idToken): array
{
    $partes = explode('.', $idToken);
    if (count($partes) < 2) {
        return [];
    }
    $payload = strtr($partes[1], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $json = base64_decode($payload, true);
    return $json ? (json_decode($json, true) ?: []) : [];
}

// ============================================================
//  Guardado de tokens por usuario (cifrado con el secreto de la app)
// ============================================================

/**
 * El secreto de la instalación: `app.secret` de la configuración.
 *
 * Lo usan cifrar()/descifrar() y, fuera de este archivo, los tokens firmados
 * de la agenda pública, el hash de las IP y el id de los eventos de Google.
 * La API de escritura exige además un `app.secret` configurado de al menos 32
 * caracteres (api_secreto_app_ok()).
 *
 * Sin `app.secret` (una instalación a medio configurar; producción lo tiene)
 * se usa un secreto al azar guardado en el almacenamiento, fuera del webroot
 * (crm_secret_guardado()). Antes se derivaba del nombre y el usuario de la
 * base, que se adivinan; ese derivado queda sólo como último respaldo si no
 * hay dónde guardar el archivo, y descifrar() lo sigue probando para que lo
 * ya cifrado con él se pueda leer.
 */
function crm_secret(): string
{
    $s = (string)($GLOBALS['CONFIG']['app']['secret'] ?? '');
    if ($s !== '') { return $s; }
    static $guardado = null;
    $guardado ??= (crm_secret_guardado() ?? '');
    return $guardado !== '' ? $guardado : crm_secret_derivado();
}

/**
 * El secreto viejo de las instalaciones sin `app.secret`, derivado de la
 * configuración de la base. Se puede adivinar: sólo sirve para leer lo que se
 * cifró con él y como respaldo si no hay dónde guardar uno al azar.
 */
function crm_secret_derivado(): string
{
    return 'crm:' . ($GLOBALS['CONFIG']['db']['name'] ?? '') . ':' . ($GLOBALS['CONFIG']['db']['user'] ?? '');
}

/**
 * Secreto al azar de la instalación, en STORAGE/.secreto-app (permisos 600).
 * Lo crea la primera vez; si dos procesos llegan juntos, el segundo lee el del
 * primero (fopen 'x' no pisa un archivo existente). Null si no hay STORAGE o
 * no se puede escribir.
 *
 * @param string|null $dir otra carpeta en vez de STORAGE (para las pruebas)
 */
function crm_secret_guardado(?string $dir = null): ?string
{
    if ($dir === null && !defined('STORAGE')) { return null; }
    $dir     = rtrim($dir ?? (string) STORAGE, '/\\');
    $archivo = $dir . '/.secreto-app';
    for ($i = 0; $i < 3; $i++) {
        $leido = @is_file($archivo) ? trim((string) @file_get_contents($archivo)) : '';
        if (strlen($leido) >= 32) { return $leido; }
        if ($leido === '' && !@is_file($archivo)) {
            if (!@is_dir($dir) && !@mkdir($dir, 0700, true) && !@is_dir($dir)) { return null; }
            $f = @fopen($archivo, 'x');
            if ($f !== false) {
                $nuevo = bin2hex(random_bytes(32));
                fwrite($f, $nuevo);
                fclose($f);
                @chmod($archivo, 0600);
                return $nuevo;
            }
        }
        // Otro proceso lo está escribiendo: se espera un momento y se relee.
        usleep(50000);
    }
    return null;
}

/**
 * Cifra con AES-256-GCM. La clave es el SHA-256 de crm_secret().
 *
 * @return string base64(iv de 12 bytes + tag de 16 bytes + texto cifrado);
 *                cada llamada usa un iv nuevo, así que el mismo texto da
 *                resultados distintos
 */
function cifrar(string $texto): string
{
    $clave = hash('sha256', crm_secret(), true);
    $iv    = random_bytes(12);
    $tag   = '';
    $ct    = openssl_encrypt($texto, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

/**
 * Descifra lo que produjo cifrar(). Devuelve '' si el formato no es válido,
 * si el dato fue alterado (el tag de GCM no coincide) o si se cifró con otro
 * secreto: quien llama trata '' como «no hay dato».
 *
 * Sin `app.secret`, si no sale con el secreto actual prueba con el derivado
 * viejo (crm_secret_derivado()): lo cifrado antes de pasar al secreto al azar
 * sigue legible.
 */
function descifrar(string $blob): string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $secretos = [crm_secret()];
    if ((string)($GLOBALS['CONFIG']['app']['secret'] ?? '') === '' && $secretos[0] !== crm_secret_derivado()) {
        $secretos[] = crm_secret_derivado();
    }
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    foreach ($secretos as $secreto) {
        $out = openssl_decrypt($ct, 'aes-256-gcm', hash('sha256', $secreto, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($out !== false) { return $out; }
    }
    return '';
}

/**
 * Guarda los tokens del usuario. El refresh_token sólo se pisa si viene uno nuevo.
 *
 * Se llama desde el callback (login) y desde google_access_token() en cada
 * renovación. Cada escritura actualiza `google_tokens.actualizado_en`, que
 * gcal_falla_pendiente() usa como «se reconectó después de la falla».
 *
 * @param array $tok respuesta del endpoint de tokens de Google
 */
function google_guardar_tokens(int $usuarioId, array $tok): void
{
    // Vence un minuto antes de lo que dice Google, para no usar un token que
    // caduca en medio de la llamada.
    $expira = date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600) - 60);
    $access = !empty($tok['access_token']) ? cifrar((string)$tok['access_token']) : null;
    if (!empty($tok['refresh_token'])) {
        q('INSERT INTO google_tokens (usuario_id, refresh_token, access_token, expira_en, scopes)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE refresh_token = VALUES(refresh_token),
                                   access_token  = VALUES(access_token),
                                   expira_en     = VALUES(expira_en),
                                   scopes        = VALUES(scopes)',
          [$usuarioId, cifrar((string)$tok['refresh_token']), $access, $expira, $tok['scope'] ?? null]);
    } else {
        q('INSERT INTO google_tokens (usuario_id, access_token, expira_en, scopes)
           VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE access_token = VALUES(access_token),
                                   expira_en    = VALUES(expira_en),
                                   scopes       = COALESCE(VALUES(scopes), scopes)',
          [$usuarioId, $access, $expira, $tok['scope'] ?? null]);
    }
    // Hay un token que Google aceptó: si estaba marcado como rechazado
    // (invalid_grant), ya no lo está.
    google_marcar_invalido($usuarioId, false);
}

/** Ajuste que marca que Google rechazó el permiso de un usuario: `google_invalido_u<id>`. */
function google_invalido_clave(int $usuarioId): string
{
    return 'google_invalido_u' . $usuarioId;
}

/**
 * Marca (o desmarca) que Google rechazó la renovación con invalid_grant: el
 * permiso fue revocado, venció o cambió la contraseña. Guarda cuándo.
 * Desmarcar sólo escribe si había marca. Nunca lanza.
 */
function google_marcar_invalido(int $usuarioId, bool $invalido): void
{
    try {
        $clave = google_invalido_clave($usuarioId);
        if ($invalido) {
            setting_set($clave, date('Y-m-d H:i:s'));
        } elseif ((string) setting_get($clave, '') !== '') {
            setting_set($clave, '');
        }
    } catch (\Throwable $e) {}
}

/**
 * ¿Hay que esperar antes de volver a pedirle a Google que renueve? Sí durante
 * GOOGLE_PAUSA_INVALIDO_SEG después de un invalid_grant: insistir en cada
 * consulta no lo arregla (hace falta que el vendedor vuelva a conectar) y
 * cada intento era una espera más para el visitante de la web.
 *
 * @param string $marca lo guardado en google_invalido_clave() ('' = sin marca)
 */
function google_en_pausa(string $marca, ?int $ahora = null): bool
{
    if ($marca === '') { return false; }
    $t = strtotime($marca);
    return $t !== false && ($ahora ?? time()) - $t < GOOGLE_PAUSA_INVALIDO_SEG;
}

/**
 * ¿El usuario ya nos dio acceso offline al calendario y sigue valiendo?
 *
 * False si nunca lo dio y también si Google rechazó la última renovación con
 * invalid_grant: el permiso guardado ya no sirve, y la pantalla tiene que
 * pedirle que vuelva a conectar en vez de decirle que está conectado.
 */
function google_conectado(int $usuarioId): bool
{
    $r = q1('SELECT refresh_token FROM google_tokens WHERE usuario_id = ?', [$usuarioId]);
    return $r && !empty($r['refresh_token'])
        && (string) setting_get(google_invalido_clave($usuarioId), '') === '';
}

/**
 * Devuelve un access_token válido del usuario, renovándolo si venció.
 * Null si el usuario nunca conectó su cuenta o si Google revocó el permiso.
 *
 * Efectos: puede llamar a Google (hasta `$timeout` segundos) y guardar el
 * token nuevo. Si Google rechaza la renovación con invalid_grant, se marca
 * (google_marcar_invalido()) y durante GOOGLE_PAUSA_INVALIDO_SEG no se vuelve
 * a intentar: devuelve null en el acto. El refresh_token queda guardado igual
 * (pasada la pausa se prueba de nuevo, por si fue algo pasajero). Otras
 * fallas (sin conexión) no ponen pausa.
 *
 * @param int $timeout segundos de espera de la renovación; quien tiene a la
 *                     web esperando pasa poco (gcal_api() usa la mitad de su tiempo)
 */
function google_access_token(int $usuarioId, int $timeout = 20): ?string
{
    $r = q1('SELECT refresh_token, access_token, expira_en FROM google_tokens WHERE usuario_id = ?', [$usuarioId]);
    if (!$r) {
        return null;
    }
    if (!empty($r['access_token']) && !empty($r['expira_en']) && strtotime($r['expira_en']) > time()) {
        $tok = descifrar((string)$r['access_token']);
        if ($tok !== '') { return $tok; }
    }
    if (empty($r['refresh_token'])) {
        return null;
    }
    if (google_en_pausa((string) setting_get(google_invalido_clave($usuarioId), ''))) {
        return null;
    }
    $tok = google_refresh(descifrar((string)$r['refresh_token']), $timeout);
    if (empty($tok['access_token'])) {
        if (($tok['error'] ?? '') === 'invalid_grant') { google_marcar_invalido($usuarioId, true); }
        log_app('google.token_error', ['tipo' => 'error', 'usuario_id' => $usuarioId,
            'detalle' => 'No se pudo renovar el acceso a Google: ' . ($tok['error'] ?? 'sin respuesta')]);
        return null;
    }
    google_guardar_tokens($usuarioId, $tok);
    return (string)$tok['access_token'];
}

/**
 * Desconecta la cuenta de Google de un usuario (revoca y borra).
 *
 * La revocación es de buena fe: si Google no responde, la fila se borra igual
 * y el permiso queda vivo del lado de Google hasta que el usuario lo quite
 * desde su cuenta. Deja la línea 'google.desconectar' en la auditoría (la
 * única: la página que la llama no agrega otra).
 */
function google_desconectar(int $usuarioId): void
{
    $r = q1('SELECT refresh_token FROM google_tokens WHERE usuario_id = ?', [$usuarioId]);
    if ($r && !empty($r['refresh_token'])) {
        $ch = curl_init('https://oauth2.googleapis.com/revoke');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['token' => descifrar((string)$r['refresh_token'])]),
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    q('DELETE FROM google_tokens WHERE usuario_id = ?', [$usuarioId]);
    google_marcar_invalido($usuarioId, false);
    log_app('google.desconectar', ['tipo' => 'auth', 'usuario_id' => $usuarioId,
        'detalle' => 'Cuenta de Google desconectada']);
}

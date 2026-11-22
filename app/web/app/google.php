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
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * POST a un endpoint de Google y devuelve el JSON decodificado.
 *
 * No mira el código HTTP: el error, si lo hay, viene en el cuerpo
 * ('error', 'error_description'). Sin conexión devuelve []. 20 s de espera
 * en total, sin un tiempo de conexión aparte.
 */
function google_post(string $url, array $campos): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($campos),
        CURLOPT_TIMEOUT        => 20,
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
 */
function google_refresh(string $refreshToken): array
{
    $g = google_cfg();
    return google_post('https://oauth2.googleapis.com/token', [
        'refresh_token' => $refreshToken,
        'client_id'     => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'grant_type'    => 'refresh_token',
    ]);
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
 * de la agenda pública. El respaldo derivado del nombre y el usuario de la
 * base se puede adivinar: por eso la API de escritura exige un secreto
 * configurado de al menos 32 caracteres (api_secreto_app_ok()).
 */
function crm_secret(): string
{
    $s = (string)($GLOBALS['CONFIG']['app']['secret'] ?? '');
    // Si no hay secreto configurado, derivamos uno de la config de base para
    // no guardar el token en claro. No es ideal, pero es mejor que texto plano.
    return $s !== '' ? $s : ('crm:' . ($GLOBALS['CONFIG']['db']['name'] ?? '') . ':' . ($GLOBALS['CONFIG']['db']['user'] ?? ''));
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
 */
function descifrar(string $blob): string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $clave = hash('sha256', crm_secret(), true);
    $iv    = substr($raw, 0, 12);
    $tag   = substr($raw, 12, 16);
    $ct    = substr($raw, 28);
    $out   = openssl_decrypt($ct, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag);
    return $out === false ? '' : $out;
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
}

/** ¿El usuario ya nos dio acceso offline al calendario? */
function google_conectado(int $usuarioId): bool
{
    $r = q1('SELECT refresh_token FROM google_tokens WHERE usuario_id = ?', [$usuarioId]);
    return $r && !empty($r['refresh_token']);
}

/**
 * Devuelve un access_token válido del usuario, renovándolo si venció.
 * Null si el usuario nunca conectó su cuenta o si Google revocó el permiso.
 *
 * Efectos: puede llamar a Google (hasta 20 s) y guardar el token nuevo. Si la
 * renovación falla, el refresh_token queda guardado igual y no hay pausa entre
 * intentos: cada llamada siguiente vuelve a probar la renovación.
 */
function google_access_token(int $usuarioId): ?string
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
    $tok = google_refresh(descifrar((string)$r['refresh_token']));
    if (empty($tok['access_token'])) {
        log_app('google.token_error', ['tipo' => 'error', 'usuario_id' => $usuarioId,
            'detalle' => 'No se pudo renovar el acceso a Google: ' . ($tok['error'] ?? 'sin detalle')]);
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
 * desde su cuenta. Deja la línea 'google.desconectar' en la auditoría.
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
    log_app('google.desconectar', ['tipo' => 'auth', 'usuario_id' => $usuarioId,
        'detalle' => 'Cuenta de Google desconectada']);
}

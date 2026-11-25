<?php
declare(strict_types=1);

// ============================================================
//  Casos sin base del ingreso con ITHub (ithub.php): el PEM armado desde el
//  JWK y la validación del id_token y del logout_token. Se firma con una
//  clave RSA generada en el momento; no se llama a ITHub (las claves se le
//  pasan a ithub_validar_jwt directamente).
//  Los carga run_tests.php; usan ok(), eq(), contiene() y no_contiene().
// ============================================================

require_once $SRC . '/ithub.php';

$GLOBALS['CONFIG']['ithub'] = [
    'issuer'        => 'https://ithub.intellihelp.tech',
    'client_id'     => 'crm',
    'client_secret' => 'ith_prueba',
];
ok('con client_id y secreto, el ingreso es por ITHub', ithub_habilitado());

// En Windows openssl_pkey_new necesita el openssl.cnf que trae PHP.
$__cnf = [];
$__ruta = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
if (is_file($__ruta)) {
    $__cnf['config'] = $__ruta;
}
$__clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $__cnf);
$__det = openssl_pkey_get_details($__clave);
$__jwk = ['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig',
          'n' => ithub_b64url($__det['rsa']['n']), 'e' => ithub_b64url($__det['rsa']['e'])];
$__claves = ['k1' => $__jwk];

eq('el PEM armado desde el JWK es la misma clave pública',
   str_replace(["\r", "\n"], '', ithub_jwk_a_pem($__jwk)), str_replace(["\r", "\n"], '', $__det['key']));

$__firmar = static function (array $claims, string $kid = 'k1', string $alg = 'RS256') use ($__clave): string {
    $h = ithub_b64url(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid]));
    $p = ithub_b64url(json_encode($claims));
    openssl_sign("$h.$p", $firma, $__clave, OPENSSL_ALGO_SHA256);
    return "$h.$p." . ithub_b64url($firma);
};
$__base = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'crm', 'sub' => '7', 'sid' => str_repeat('a', 32),
           'email' => 'lzaltron@intellihelp.tech', 'rol' => 'comercial', 'nonce' => 'n1',
           'iat' => time(), 'exp' => time() + 300];
$__falla = static function (callable $fn): string {
    try { $fn(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); }
};

$__c = ithub_validar_jwt($__firmar($__base), 'n1', false, $__claves);
eq('un id_token bien firmado pasa y trae el rol', $__c['rol'] ?? '', 'comercial');

$__tok = $__firmar($__base);
$__partes = explode('.', $__tok);
$__partes[1] = ithub_b64url(json_encode(['rol' => 'admin'] + $__base));
eq('cambiar el contenido rompe la firma', $__falla(fn() => ithub_validar_jwt(implode('.', $__partes), 'n1', false, $__claves)), 'firma');
eq('otro destinatario (aud) no sirve', $__falla(fn() => ithub_validar_jwt($__firmar(['aud' => 'remitos'] + $__base), 'n1', false, $__claves)), 'iss/aud');
eq('otro emisor (iss) no sirve', $__falla(fn() => ithub_validar_jwt($__firmar(['iss' => 'https://evil.tld'] + $__base), 'n1', false, $__claves)), 'iss/aud');
eq('vencido no sirve', $__falla(fn() => ithub_validar_jwt($__firmar(['exp' => time() - 120] + $__base), 'n1', false, $__claves)), 'vencido');
eq('con otro nonce no sirve (repetición)', $__falla(fn() => ithub_validar_jwt($__firmar($__base), 'otro', false, $__claves)), 'nonce');
eq('sólo RS256', $__falla(fn() => ithub_validar_jwt($__firmar($__base, 'k1', 'none'), 'n1', false, $__claves)), 'alg');
eq('un kid desconocido no sirve', $__falla(fn() => ithub_validar_jwt($__firmar($__base, 'k9'), 'n1', false, $__claves)), 'kid');
eq('basura no es un token', $__falla(fn() => ithub_validar_jwt('no.es-un.token.jwt', null, false, $__claves)), 'formato');

$__logout = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'crm', 'sub' => '7', 'sid' => str_repeat('b', 32),
             'iat' => time(), 'exp' => time() + 120, 'jti' => 'x',
             'events' => [ITHUB_EVENTO_LOGOUT => new stdClass()]];
eq('el aviso de cierre válido trae el sid', ithub_validar_jwt($__firmar($__logout), null, true, $__claves)['sid'] ?? '', str_repeat('b', 32));
eq('un id_token no sirve como aviso de cierre', $__falla(fn() => ithub_validar_jwt($__firmar($__base), null, true, $__claves)), 'logout_token');
eq('un aviso de cierre con nonce se rechaza', $__falla(fn() => ithub_validar_jwt($__firmar(['nonce' => 'x'] + $__logout), null, true, $__claves)), 'logout_token');

// ---- Salida ----
$__salida = ithub_url_salida('tok.en.x');
contiene('la salida va a /oauth/logout de ITHub', $__salida, 'https://ithub.intellihelp.tech/oauth/logout?');
contiene('con el id_token como pista (no pregunta)', $__salida, 'id_token_hint=tok.en.x');
contiene('y vuelve al login del CRM', $__salida, 'post_logout_redirect_uri=' . rawurlencode(url_abs('login')));

unset($GLOBALS['CONFIG']['ithub']);
ok('sin la sección ithub, el CRM sigue con Google', !ithub_habilitado());

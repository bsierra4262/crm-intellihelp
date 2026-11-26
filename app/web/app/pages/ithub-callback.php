<?php
declare(strict_types=1);

// ============================================================
//  Vuelta de ITHub después del ingreso (OpenID Connect, código + PKCE).
//
//  Ruta: GET ithub-callback (pública). Valida el state, canjea el código por
//  los tokens (servidor a servidor, con el secreto del CRM), valida la firma
//  y los claims del id_token, busca o crea el usuario con el rol que manda
//  ITHub e inicia la sesión. Guarda el `sid` (para el aviso de cierre) y el
//  id_token (para salir sin que ITHub pregunte).
//
//  Si ITHub contesta access_denied es que la persona no tiene el CRM
//  habilitado: ITHub ya le mostró su pantalla de «No tenés acceso», así que
//  acá sólo se explica en el login.
// ============================================================

if (!ithub_habilitado()) {
    redirect('login');
}

$state = (string)($_GET['state'] ?? '');
$esperado = (string)($_SESSION['ithub_state'] ?? '');
$nonce = (string)($_SESSION['ithub_nonce'] ?? '');
$verificador = (string)($_SESSION['ithub_ver'] ?? '');
unset($_SESSION['ithub_state'], $_SESSION['ithub_nonce'], $_SESSION['ithub_ver']);

if ($state === '' || $esperado === '' || !hash_equals($esperado, $state)) {
    flash('La validación de seguridad con ITHub falló. Volvé a intentar.', 'error');
    redirect('login?manual=1');
}
if (isset($_GET['error'])) {
    $sinPermiso = ($_GET['error'] ?? '') === 'access_denied';
    flash($sinPermiso
        ? 'Tu usuario de ITHub no tiene acceso al CRM. Si lo necesitás, pedíselo a un administrador de ITHub.'
        : 'No se pudo completar el ingreso con ITHub.', 'error');
    redirect('login?manual=1');
}

$tok = ithub_canjear_codigo((string)($_GET['code'] ?? ''), $verificador);
try {
    $claims = ithub_validar_jwt((string)($tok['id_token'] ?? ''), $nonce);
} catch (Throwable $e) {
    log_app('ithub.login_error', ['tipo' => 'error',
        'detalle' => 'id_token de ITHub inválido: ' . $e->getMessage()
            . (isset($tok['error']) ? ' (' . $tok['error'] . ')' : '')]);
    flash('No se pudo validar el ingreso con ITHub. Volvé a intentar.', 'error');
    redirect('login?manual=1');
}

$usuario = ithub_usuario_local($claims);
if ($usuario === null) {
    log_app('sesion.rechazo', ['tipo' => 'auth', 'actor' => (string)($claims['email'] ?? ''),
        'detalle' => 'ITHub mandó un rol que el CRM no conoce: ' . (string)($claims['rol'] ?? '')]);
    flash('Tu rol en ITHub no corresponde a ninguno del CRM. Avisale al administrador.', 'error');
    redirect('login?manual=1');
}

auth_login_user((int)$usuario['id'], 'Ingreso con ITHub');
$_SESSION['ithub_sid'] = (string)($claims['sid'] ?? '');
$_SESSION['ithub_sub'] = (string)($claims['sub'] ?? '');
$_SESSION['ithub_id_token'] = (string)$tok['id_token'];
redirect('dashboard');

<?php
declare(strict_types=1);

// 1) Validar el state (anti-CSRF)
$state    = $_GET['state'] ?? '';
$esperado = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);
if ($state === '' || !hash_equals($esperado, (string)$state)) {
    flash('La validación de seguridad con Google falló. Reintentá.', 'error');
    redirect('login');
}

if (isset($_GET['error'])) {
    flash('Se canceló el acceso con Google.', 'error');
    redirect('login');
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    redirect('login');
}

// 2) Intercambiar el código por tokens
$tok = google_exchange_code((string)$code);
if (empty($tok['id_token']) && empty($tok['access_token'])) {
    log_app('google.login_error', ['tipo' => 'error',
        'detalle' => 'Google no devolvió tokens: ' . ($tok['error_description'] ?? $tok['error'] ?? 'sin detalle')]);
    flash('No se pudo validar la cuenta de Google.', 'error');
    redirect('login');
}

// 3) Identidad (del id_token, recibido directo de Google por TLS)
$claims   = !empty($tok['id_token']) ? google_decode_id_token((string)$tok['id_token']) : [];
$email    = normalizar_email((string)($claims['email'] ?? ''));
$verificado = (bool)($claims['email_verified'] ?? false);
$hd       = $claims['hd'] ?? null;
$nombre   = (string)($claims['name'] ?? $email);
$foto     = (string)($claims['picture'] ?? '');

if ($email === '' || !$verificado) {
    flash('La cuenta de Google no tiene un correo verificado.', 'error');
    redirect('login');
}

// 4) Restringir al dominio de la organización
$g = google_cfg();
if (!empty($g['allowed_domain']) && $hd !== $g['allowed_domain']) {
    log_app('sesion.rechazo', ['tipo' => 'auth', 'actor' => $email,
        'detalle' => 'Intento de ingreso con una cuenta fuera del dominio autorizado']);
    flash('Tu cuenta no pertenece al dominio autorizado de la organización.', 'error');
    redirect('login');
}

// 5) Buscar o crear el usuario
$usuario = find_user_by_email($email);
if (!$usuario) {
    if (!empty($g['auto_provision'])) {
        $id = provision_user($email, $nombre);
        $usuario = ['id' => $id, 'activo' => 1];
    } else {
        flash('Tu usuario no está habilitado. Pedile al administrador que te dé de alta.', 'error');
        redirect('login');
    }
}
if ((int)$usuario['activo'] !== 1) {
    flash('Tu usuario está inactivo. Contactá al administrador.', 'error');
    redirect('login');
}

// Refrescar nombre y foto en cada ingreso, así el CRM sigue al directorio.
q('UPDATE usuarios SET nombre = ?, avatar_url = ? WHERE id = ?',
  [$nombre !== '' ? $nombre : $email, $foto !== '' ? $foto : null, (int)$usuario['id']]);

// 6) Guardar los tokens: el refresh_token es lo que después permite crear
//    reuniones en Google Calendar aunque el vendedor no tenga sesión abierta.
google_guardar_tokens((int)$usuario['id'], $tok);

// 7) Se acaba de reconectar, así que un aviso de «esta cuenta perdió la
//    conexión» ya no corresponde. Antes ese cartel sólo se borraba cuando
//    alguna llamada a Google salía bien, y después de reconectar no hay
//    ninguna hasta que alguien reserve una reunión o pase el cron: reconectabas
//    y el cartel rojo seguía ahí, sin forma de sacarlo.
$fallaPrevia = gcal_falla_pendiente();
if ($fallaPrevia && (int)($fallaPrevia['usuario_id'] ?? 0) === (int)$usuario['id']) {
    gcal_recordar_falla((int)$usuario['id'], 0, '');
}

auth_login_user((int)$usuario['id']);
redirect('dashboard');

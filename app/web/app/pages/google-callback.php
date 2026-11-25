<?php
declare(strict_types=1);

// ============================================================
//  Vuelta de Google después del login (OAuth 2.0, código de autorización).
//
//  Ruta: GET google-callback (pública en index.php: todavía no hay sesión de
//  usuario, sólo la de PHP con el `oauth_state` que dejó google-login.php).
//  Tiene que coincidir exacta con la registrada en la consola de Google
//  (google_redirect_uri()).
//
//  Con ITHub configurado, el login lo hace ITHub y esto queda sólo para
//  conectar Google Calendar de quien ya está adentro (paso 5'). Sin ITHub,
//  hace el login completo: valida el state, cambia el código por tokens, lee
//  la identidad del id_token, controla el dominio, busca o crea el usuario,
//  guarda los tokens de Google (google_tokens) e inicia la sesión
//  (auth_login_user(), que regenera el id de sesión). Cualquier falla vuelve
//  a 'login' con un mensaje; nunca muestra el error crudo de Google.
//
//  Tablas: usuarios (busca, crea con auto_provision, actualiza nombre y foto)
//  y google_tokens. Núcleo: el login no depende de ningún módulo.
// ============================================================

// 1) Validar el state (anti-CSRF)
// Se borra de la sesión antes de comparar: un state sirve una sola vez.
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
// Se compara el claim `hd` (lo pone Google sólo para cuentas de Workspace) y
// no el final del correo: una cuenta personal no puede fingirlo.
$g = google_cfg();
if (!empty($g['allowed_domain']) && $hd !== $g['allowed_domain']) {
    log_app('sesion.rechazo', ['tipo' => 'auth', 'actor' => $email,
        'detalle' => 'Intento de ingreso con una cuenta fuera del dominio autorizado']);
    flash('Tu cuenta no pertenece al dominio autorizado de la organización.', 'error');
    redirect('login');
}

// 5') Con ITHub, esto no es un login: es «Conectar Google Calendar» de
//     alguien que ya está adentro. Se exige que la cuenta de Google sea la
//     misma de la sesión (si no, el calendario de otra persona quedaría
//     colgado de este usuario) y no se toca la sesión.
if (ithub_habilitado()) {
    $yo = auth_user();
    if ($yo === null) {
        redirect('login');
    }
    if (normalizar_email((string)$yo['email']) !== $email) {
        flash('Elegiste otra cuenta de Google (' . $email . '). Conectá la tuya: ' . $yo['email'] . '.', 'error');
        redirect('agenda');
    }
    google_guardar_tokens((int)$yo['id'], $tok);
    if (google_permisos_faltantes((string)($tok['scope'] ?? ''))) {
        gcal_recordar_falla((int)$yo['id'], 403,
            'Falta el permiso de Google Calendar: al conectar se destildó el acceso al calendario.');
        flash('Google quedó conectado, pero sin permiso para tu calendario: la agenda no puede ver tus '
            . 'horarios ni crear reuniones. Volvé a conectar y dejá tildado el acceso al calendario.', 'warn');
    } else {
        gcal_limpiar_falla((int)$yo['id']);
        log_app('google.conectado', ['tipo' => 'auth', 'usuario_id' => (int)$yo['id'],
            'detalle' => 'Conectó Google Calendar']);
        flash('Google Calendar conectado.', 'success');
    }
    redirect('agenda');
}

// 5) Buscar o crear el usuario
// Con auto_provision, cualquiera del dominio entra como 'comercial' en su
// primer ingreso (el primer usuario de una base vacía queda admin:
// provision_user()). Sin dominio configurado, eso abriría la puerta a
// cualquier cuenta de Google.
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

// 7) ¿Concedió los permisos del calendario? En la pantalla de Google se
//    pueden destildar: el login anda igual, pero sin ellos no se ven los
//    horarios ocupados ni se crean reuniones. Se avisa ahora (y queda el
//    aviso rojo de la agenda) en vez de enterarse en la primera reserva.
//    Si Google no informó los permisos (`scope` vacío) no se puede saber y
//    no se avisa nada.
if (google_permisos_faltantes((string)($tok['scope'] ?? ''))) {
    gcal_recordar_falla((int)$usuario['id'], 403,
        'Falta el permiso de Google Calendar: al conectar se destildó el acceso al calendario.');
    flash('Entraste, pero no diste permiso para usar tu Google Calendar: la agenda no puede ver tus '
        . 'horarios ni crear reuniones. Volvé a conectar y dejá tildado el acceso al calendario.', 'warn');
} else {
    // Recién reconectada la cuenta, se borra el aviso de «esta cuenta perdió
    // la conexión»: si no, seguiría visible hasta la próxima llamada exitosa
    // a Google (una reserva o el cron).
    gcal_limpiar_falla((int)$usuario['id']);
}

auth_login_user((int)$usuario['id']);
redirect('dashboard');

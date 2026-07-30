<?php
declare(strict_types=1);

if (!google_enabled()) {
    flash('El acceso con Google no está configurado. Avisale al administrador.', 'error');
    redirect('login');
}

$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

// Pedimos el consentimiento explícito la primera vez (o cuando el usuario lo
// fuerza desde Ajustes): sin él, Google no vuelve a mandar el refresh_token y
// nos quedaríamos sin poder agendar reuniones fuera de sesión.
$forzar = ($_GET['conectar'] ?? '') === '1'
    || (auth_user() && !google_conectado((int)auth_user()['id']));

header('Location: ' . google_auth_url($_SESSION['oauth_state'], $forzar));
exit;

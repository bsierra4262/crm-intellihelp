<?php
declare(strict_types=1);

// ============================================================
//  Arranque del login con Google.
//
//  Ruta: GET google-login (pública). Genera el `state` anti-CSRF, lo deja en
//  la sesión de PHP y manda al navegador a la pantalla de Google; la vuelta
//  la atiende google-callback.php. El botón «Reconectar con Google» de
//  views/_aviso_gcal.php llega acá con ?conectar=1, que no cambia nada: el
//  flujo es siempre el mismo.
// ============================================================

if (!google_enabled()) {
    flash('El acceso con Google no está configurado. Avisale al administrador.', 'error');
    redirect('login');
}

// 128 bits al azar. Un solo state por sesión: si se abren dos pestañas de
// login, sólo la última puede terminar.
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

// SIEMPRE se piden todos los permisos, en cada ingreso.
//
// Google manda el refresh_token —el que deja agendar reuniones y leer los
// huecos del calendario sin que haya nadie con la sesión abierta— únicamente
// cuando se le pide el consentimiento de forma explícita. No sirve pedirlo
// sólo «la primera vez»: esa condición depende de la sesión, y al entrar de
// cero (cerrar sesión, borrar la caché, otra computadora) no hay ninguna, así
// que Google no lo manda y el CRM queda sin poder tocar el calendario.
//
// El costo es que se ve la pantalla de permisos en cada ingreso. Vale la pena:
// la alternativa es un calendario que deja de funcionar en silencio y un aviso
// rojo que nadie sabe cómo sacar.
header('Location: ' . google_auth_url($_SESSION['oauth_state'], true));
exit;

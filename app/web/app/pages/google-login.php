<?php
declare(strict_types=1);

if (!google_enabled()) {
    flash('El acceso con Google no está configurado. Avisale al administrador.', 'error');
    redirect('login');
}

$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

// SIEMPRE se piden todos los permisos, en cada ingreso.
//
// Google manda el refresh_token —el que deja agendar reuniones y leer los
// huecos del calendario sin que haya nadie con la sesión abierta— únicamente
// cuando se le pide el consentimiento de forma explícita. Antes se pedía sólo
// «la primera vez», y esa condición miraba si había una sesión iniciada: al
// entrar de cero no hay ninguna, así que no se pedía, Google no lo mandaba, y
// el CRM quedaba sin poder tocar el calendario. Eso es justo lo que pasa cuando
// alguien cierra sesión, borra la caché o entra desde otra computadora.
//
// El costo es que se ve la pantalla de permisos en cada ingreso. Vale la pena:
// la alternativa es un calendario que deja de funcionar en silencio y un aviso
// rojo que nadie sabe cómo sacar.
header('Location: ' . google_auth_url($_SESSION['oauth_state'], true));
exit;

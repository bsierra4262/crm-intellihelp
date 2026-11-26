<?php
declare(strict_types=1);

// ============================================================
//  Ruta: /logout (requiere sesión). Cierra la sesión y vuelve al login.
//
//  Cómo se cierra, según cómo se llega:
//    · POST + CSRF: el botón «salir» de la barra lateral (un formulario en
//      layout.php). Cierra siempre.
//    · GET desde una página del propio CRM: es idle.js cuando vence el
//      tiempo, con `?motivo=inactividad|expiro` (o el viejo `?expiro=`).
//      Una navegación no puede llevar el token CSRF, así que se confirma que
//      el pedido salió del mismo sitio con pedido_mismo_origen() (helpers.php:
//      la cabecera Sec-Fetch-Site que pone el navegador, o el Referer). Cierra
//      igual que antes, motivo incluido. También cierra un GET escrito a mano
//      o desde un favorito (Sec-Fetch-Site: none).
//    · GET desde otro sitio, o sin forma de saber de dónde vino: no cierra
//      nada; muestra una pantalla para confirmar con un botón (POST + CSRF).
//      Así un sitio externo ya no puede desloguear a nadie con un enlace o
//      una imagen apuntando acá.
//  Deja la salida en la auditoría (tipo 'auth') y pasa el motivo al login
//  para que muestre el aviso. Si se entró con ITHub, la salida manual sigue
//  a /oauth/logout de ITHub (cierra la sesión única en todas las apps).
// ============================================================

// El cierre puede ser automático: idle.js manda el motivo cuando se acabó el
// tiempo. Se distingue en la auditoría porque no significa lo mismo que una
// decisión de la persona. Sólo se aceptan los dos motivos que existen.
$motivo = preg_replace('/[^a-z]/', '', (string)($_POST['motivo'] ?? $_GET['motivo'] ?? $_GET['expiro'] ?? ''));
$motivo = in_array($motivo, ['inactividad', 'expiro'], true) ? $motivo : '';

if (is_post()) {
    verify_csrf();
} elseif (pedido_mismo_origen($_SERVER) !== true) {
    render('logout', ['motivo' => $motivo], 'Cerrar sesión');
    exit;
}

$uid = current_user_id();
if ($uid) {
    $detalle = $motivo === 'inactividad' ? 'Cierre por inactividad'
             : ($motivo === 'expiro'     ? 'Cierre por sesión máxima'
                                         : 'Cierre de sesión manual');
    log_app('sesion.salida', ['tipo' => 'auth', 'usuario_id' => $uid, 'detalle' => $detalle]);
}

// Con ITHub, una salida pedida por la persona cierra la sesión única (y con
// ella todas las apps). El cierre automático por inactividad del CRM, no: se
// cae la sesión del CRM y el resto sigue abierto.
$idToken = (string)($_SESSION['ithub_id_token'] ?? '');
$porIthub = ithub_habilitado() && (!empty($_SESSION['ithub_sid']) || !empty($_SESSION['ithub_sub']));
auth_logout();
if ($porIthub && $motivo === '') {
    header('Location: ' . ithub_url_salida($idToken !== '' ? $idToken : null));
    exit;
}
redirect($motivo !== '' ? 'login?expiro=' . $motivo : 'login');

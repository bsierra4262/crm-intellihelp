<?php
declare(strict_types=1);

// ============================================================
//  Ruta: GET /login (pública). Pantalla de ingreso.
//
//  No hay formulario de usuario y contraseña: la vista ofrece sólo el botón
//  «Ingresar con Google Workspace», que lleva a google-login. Si ya hay
//  sesión, va directo al panel.
//
//  `?expiro=inactividad|expiro` lo agregan el cierre automático de
//  bootstrap.php y logout.php, para explicar por qué se volvió al login.
//  Vista: views/login.php (sin el marco del sistema: body class 'auth').
// ============================================================

if (auth_user()) {
    redirect('dashboard');
}

// Aviso si la sesión se cerró sola (inactividad / sesión máxima).
// Se limpia a letras minúsculas; sólo se usan los dos valores conocidos.
$expiro = preg_replace('/[^a-z]/', '', (string)($_GET['expiro'] ?? ''));
if ($expiro === 'inactividad') {
    flash('Cerramos tu sesión por inactividad. Volvé a ingresar.', 'info');
} elseif ($expiro === 'expiro') {
    flash('Tu sesión alcanzó el tiempo máximo permitido. Volvé a ingresar.', 'info');
}

// Acceso 100 % Google Workspace: no hay contraseñas en el sistema.
render('login', [], 'Ingresar');

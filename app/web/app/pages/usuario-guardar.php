<?php
declare(strict_types=1);

// ============================================================
//  Ruta: POST /usuario-guardar (sólo administrador + CSRF).
//
//  Cambia el rol ('admin' | 'comercial') y el estado activo de un usuario,
//  desde la tabla de views/usuarios.php. Un usuario desactivado pierde la
//  sesión en su pedido siguiente (auth_user lo detecta). No hay alta ni baja
//  de usuarios: se crean solos al ingresar con Google.
// ============================================================
require_admin();

if (!is_post()) { redirect('usuarios'); }
verify_csrf();

$id     = (int)($_POST['id'] ?? 0);
$rol    = (string)($_POST['rol'] ?? 'comercial');
$activo = !empty($_POST['activo']);

$u = q1('SELECT id, rol, activo FROM usuarios WHERE id = ?', [$id]);
if (!$u) {
    flash('El usuario no existe.', 'error');
    redirect('usuarios');
}

// Red de seguridad: nunca quedarse sin ningún administrador activo, porque
// después nadie puede volver a entrar a la configuración.
$dejaDeSerAdmin = $u['rol'] === 'admin' && ((int)$u['activo'] === 1) && ($rol !== 'admin' || !$activo);
if ($dejaDeSerAdmin && admins_activos() <= 1) {
    flash('No se puede: quedaría el sistema sin ningún administrador activo.', 'error');
    redirect('usuarios');
}

user_update($id, $rol, $activo);
flash('Usuario actualizado.', 'success');
redirect('usuarios');

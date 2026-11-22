<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: POST /nota-eliminar (requiere sesión + CSRF).
//
//  Borra una nota desde la ficha del registro (usa la función de
//  registros.php). Es un borrado lógico (eliminado_en), aunque la interfaz
//  avisa que es definitivo: las notas no figuran en la papelera.
//  Sólo el autor o un administrador pueden borrarla.
//  Vuelve a la página anterior según la cabecera Referer.
// ============================================================

if (!is_post()) { redirect('dashboard'); }
verify_csrf();

$id = (int)($_POST['id'] ?? 0);
$n  = $id ? q1('SELECT * FROM notas WHERE id = ?', [$id]) : null;

if (!$n) {
    flash('Esa nota ya no existe.', 'info');
} elseif ((int)($n['creado_por'] ?? 0) !== current_user_id() && !auth_is_admin()) {
    // La nota es de quien la escribió. El administrador puede sacar cualquiera.
    flash('Esa nota la escribió otra persona: sólo ella o un administrador la pueden borrar.', 'error');
} else {
    nota_eliminar($id);
    flash('Nota borrada.', 'success');
}

$volver = (string)($_SERVER['HTTP_REFERER'] ?? '');
header('Location: ' . ($volver !== '' ? $volver : url('dashboard')));
exit;

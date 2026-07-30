<?php
declare(strict_types=1);

$uid = current_user_id();
if ($uid) {
    log_app('sesion.salida', ['tipo' => 'auth', 'usuario_id' => $uid, 'detalle' => 'Cierre de sesión manual']);
}
auth_logout();
redirect('login');

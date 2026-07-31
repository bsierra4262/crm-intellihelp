<?php
declare(strict_types=1);
require_login();

// Todo lo que pasó y hay que mirar: respuestas, reuniones, rebotes, campañas.

$uid = (int) current_user_id();

if (is_post()) {
    verify_csrf();
    notif_marcar_leidas($uid, (int)($_POST['id'] ?? 0) ?: null);
    redirect('alertas');
}

render('alertas', [
    'rows'     => notif_de($uid, false, 80),
    'sinLeer'  => notif_sin_leer($uid),
], 'Alertas');

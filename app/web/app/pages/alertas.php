<?php
declare(strict_types=1);

// ============================================================
//  Ruta: /alertas (requiere sesión). Las alertas de la persona y del equipo.
//
//  GET: lista las últimas 80 (views/alertas.php).
//  POST (+ CSRF): con `id` marca esa alerta como leída; sin `id`, todas.
//  La ruta se habilita sólo si Campañas o Agenda están encendidos
//  (ruta_habilitada en modulos.php). Funciones en notificaciones.php.
// ============================================================
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

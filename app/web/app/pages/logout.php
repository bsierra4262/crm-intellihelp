<?php
declare(strict_types=1);

// El cierre puede ser manual (el botón de la barra) o automático: idle.js manda
// «?expiro=» cuando se acabó el tiempo. Se distinguen en la auditoría porque no
// significan lo mismo: uno es una decisión, el otro es que la sesión se venció.
$motivo = preg_replace('/[^a-z]/', '', (string)($_GET['expiro'] ?? ''));

$uid = current_user_id();
if ($uid) {
    $detalle = $motivo === 'inactividad' ? 'Cierre por inactividad'
             : ($motivo === 'expiro'     ? 'Cierre por sesión máxima'
                                         : 'Cierre de sesión manual');
    log_app('sesion.salida', ['tipo' => 'auth', 'usuario_id' => $uid, 'detalle' => $detalle]);
}

auth_logout();
redirect($motivo !== '' ? 'login?expiro=' . $motivo : 'login');

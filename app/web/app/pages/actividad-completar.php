<?php
declare(strict_types=1);
require_login();

if (!is_post()) { redirect('actividades'); }
verify_csrf();

$id        = (int)($_POST['id'] ?? 0);
$reabrir   = ($_POST['reabrir'] ?? '') === '1';
$resultado = trim((string)($_POST['resultado'] ?? '')) ?: null;

if ($id) {
    actividad_completar($id, !$reabrir, $resultado);
    flash($reabrir ? 'Actividad reabierta.' : 'Actividad completada.', 'success');
}

$volver = (string)($_SERVER['HTTP_REFERER'] ?? '');
header('Location: ' . ($volver !== '' ? $volver : url('actividades')));
exit;

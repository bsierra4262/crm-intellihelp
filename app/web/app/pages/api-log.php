<?php
declare(strict_types=1);
require_login();

// Registro de eventos del cliente (JS) en la auditoría. Se llama con fetch
// desde el layout; nunca debe devolver nada que el navegador tenga que pintar.
if (!is_post()) {
    json_out(['ok' => false], 405);
}
verify_csrf();

$accion  = trim((string)($_POST['accion'] ?? ''));
$detalle = trim((string)($_POST['detalle'] ?? ''));
$tipo    = (string)($_POST['tipo'] ?? 'accion');
if (!in_array($tipo, ['accion', 'nav', 'error', 'sistema'], true)) {
    $tipo = 'accion';
}
if ($accion !== '') {
    log_app($accion, ['tipo' => $tipo, 'detalle' => $detalle]);
}
json_out(['ok' => true]);

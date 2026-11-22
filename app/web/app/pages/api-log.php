<?php
declare(strict_types=1);
require_login();

// Registro de eventos del cliente (JS) en la auditoría. Se llama con fetch
// desde el layout; nunca debe devolver nada que el navegador tenga que pintar.
//
// Ruta: POST api-log, con sesión y token CSRF. Pese al nombre, no tiene que
// ver con la API para otros sistemas: escribe en la auditoría general
// (log_app), a nombre del usuario de la sesión. Núcleo.
// La acción y el detalle llegan tal cual del navegador: cualquier usuario con
// sesión puede dejar una línea con el texto que quiera. El tipo sí se limita
// a una lista para que no se pueda hacer pasar por 'auth'.
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

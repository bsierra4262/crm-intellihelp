<?php
declare(strict_types=1);
require_login();

// Registro de eventos del cliente (JS) en la auditoría. Se llama con fetch
// desde el layout; nunca debe devolver nada que el navegador tenga que pintar.
//
// Ruta: POST api-log, con sesión y token CSRF. Pese al nombre, no tiene que
// ver con la API para otros sistemas: escribe en la auditoría general
// (log_app), a nombre del usuario de la sesión. Núcleo.
// Lo que llega del navegador se acota con cliente_log_limpio() (api.php):
// sólo acciones `js.*`, detalle de una línea y corto, y un tipo de una lista
// cerrada (nunca 'auth' ni 'sistema'). Además, no más de 30 líneas por minuto
// por usuario: un error de JavaScript en un bucle no llena la auditoría.
if (!is_post()) {
    json_out(['ok' => false], 405);
}
verify_csrf();

$linea = cliente_log_limpio($_POST);
if ($linea !== null && !limite_superado('jslog:u' . (int) current_user_id() . ':60', 30, 60)) {
    log_app($linea['accion'], ['tipo' => $linea['tipo'], 'detalle' => $linea['detalle']]);
}
json_out(['ok' => true]);

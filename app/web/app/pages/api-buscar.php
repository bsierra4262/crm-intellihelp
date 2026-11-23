<?php
declare(strict_types=1);

// ============================================================
//  Ruta: GET /api-buscar?tipo=cuenta|contacto|negocio|campana&q=texto
//  (requiere sesión). JSON de sólo lectura para assets/js/buscador.js.
//
//  Responde {ok:true, resultados:[{id, nombre, detalle}]} con hasta 20
//  registros vivos que coinciden (buscar_registros() de crm.php). Con menos
//  de 2 caracteres, o un tipo desconocido, la lista viene vacía. Negocios y
//  campañas sólo se buscan si su módulo está encendido.
//
//  No escribe nada, así que no lleva CSRF. Sin caché: los resultados cambian
//  a medida que se carga la base.
// ============================================================
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$tipo  = (string)($_GET['tipo'] ?? '');
$texto = mb_substr((string)($_GET['q'] ?? ''), 0, 100);

$modulo = ['negocio' => 'negocios', 'campana' => 'campanas'][$tipo] ?? null;
if ($modulo !== null && !ruta_habilitada($modulo)) {
    json_out(['ok' => true, 'resultados' => []]);
}

try {
    json_out(['ok' => true, 'resultados' => buscar_registros($tipo, $texto, 20)]);
} catch (\Throwable $e) {
    log_error('buscar.registros', $e->getMessage(), $e->getFile(), $e->getLine());
    json_out(['ok' => false, 'error' => 'No se pudo buscar. Probá de nuevo.'], 500);
}

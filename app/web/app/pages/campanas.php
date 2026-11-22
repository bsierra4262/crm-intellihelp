<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: /campanas  (con sesión; módulo «campanas»).
//  Listado de campañas con filtros por segmento, estado y nombre. Los datos
//  salen de campanas_listar() y la vista (views/campanas.php) se refresca
//  sola consultando api-campana. Avisa si falta la cuenta de salida y si la
//  instalación está en modo prueba.
// ============================================================

$f = [
    'q'      => trim((string)($_GET['q'] ?? '')),
    'estado' => $_GET['estado'] ?? '',
    'categoria_id' => $_GET['categoria_id'] ?? '',
    'page'   => $_GET['page'] ?? 1,
];

render('campanas', [
    'f'       => $f,
    'data'    => campanas_listar($f),
    'testing' => es_testing(),
    'smtp'    => smtp_configurado(),
], 'Campañas');

<?php
declare(strict_types=1);
require_login();

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

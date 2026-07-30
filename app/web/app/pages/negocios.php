<?php
declare(strict_types=1);
require_login();

$f = [
    'q'              => trim((string)($_GET['q'] ?? '')),
    'estado'         => $_GET['estado'] ?? 'abiertos',
    'embudo_id'      => $_GET['embudo_id'] ?? '',
    'etapa_id'       => $_GET['etapa_id'] ?? '',
    'propietario_id' => $_GET['propietario_id'] ?? '',
    'desde'          => $_GET['desde'] ?? '',
    'hasta'          => $_GET['hasta'] ?? '',
    'page'           => $_GET['page'] ?? 1,
];

$emb = embudo_predeterminado();

render('negocios', [
    'f'        => $f,
    'data'     => negocios_listar($f),
    'usuarios' => users_activos(),
    'etapas'   => $emb ? etapas_de((int)$emb['id']) : [],
], 'Negocios');

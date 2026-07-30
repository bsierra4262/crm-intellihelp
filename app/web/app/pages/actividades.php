<?php
declare(strict_types=1);
require_login();

$f = [
    'q'              => trim((string)($_GET['q'] ?? '')),
    'tipo'           => $_GET['tipo'] ?? '',
    'estado'         => $_GET['estado'] ?? '',
    'propietario_id' => $_GET['propietario_id'] ?? (string)current_user_id(),
    'vista'          => $_GET['vista'] ?? '',
    'vencidas'       => $_GET['vencidas'] ?? '',
    'page'           => $_GET['page'] ?? 1,
];

render('actividades', [
    'f'        => $f,
    'data'     => actividades_listar($f),
    'usuarios' => users_activos(),
], 'Actividades');

<?php
declare(strict_types=1);
// ============================================================
//  Ruta `negocios`: el listado de negocios con filtros y totales.
//
//  Filtros por GET: q, estado (abiertos por defecto, ganados, perdidos,
//  todos), embudo_id, etapa_id, propietario_id, desde/hasta (fecha estimada
//  de cierre) y page. Los valores van a negocios_where() como parámetros.
//  El desplegable de etapas ofrece las del embudo filtrado o, si no hay
//  filtro de embudo, las del predeterminado.
//
//  Módulo «negocios». Sólo lectura (el botón de papelera postea a
//  registro-eliminar). Vista: views/negocios.php.
// ============================================================
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

$emb = !empty($f['embudo_id']) ? ['id' => (int)$f['embudo_id']] : embudo_predeterminado();

render('negocios', [
    'f'        => $f,
    'data'     => negocios_listar($f),
    'usuarios' => users_activos(),
    'etapas'   => $emb ? etapas_de((int)$emb['id']) : [],
], 'Negocios');

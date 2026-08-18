<?php
declare(strict_types=1);
require_login();

$f = [
    'q'              => trim((string)($_GET['q'] ?? '')),
    'propietario_id' => $_GET['propietario_id'] ?? '',
    'provincia'      => $_GET['provincia'] ?? '',
    'tipo'           => $_GET['tipo'] ?? '',
    'sector'         => $_GET['sector'] ?? '',
    'pendiente'      => $_GET['pendiente'] ?? '',
    'page'           => $_GET['page'] ?? 1,
];

if (($_GET['exportar'] ?? '') === '1') {
    [$where, $p] = cuentas_where($f);
    $rows = q("SELECT c.*, u.nombre AS propietario FROM cuentas c
               LEFT JOIN usuarios u ON u.id = c.propietario_id $where ORDER BY c.nombre", $p)->fetchAll();
    log_app('cuenta.exportar', ['detalle' => count($rows) . ' empresas exportadas a CSV']);
    exportar_csv('empresas-' . date('Ymd-His') . '.csv', $rows, [
        'nombre' => 'Empresa', 'tipo' => 'Tipo', 'sector' => 'Sector', 'cuit' => 'CUIT',
        'telefono' => 'Teléfono', 'email' => 'Correo', 'sitio_web' => 'Sitio web',
        'calle' => 'Domicilio', 'localidad' => 'Localidad', 'provincia' => 'Provincia',
        'codigo_postal' => 'CP', 'pais' => 'País', 'propietario' => 'Propietario',
    ]);
}

render('cuentas', [
    'f'        => $f,
    'data'     => cuentas_listar($f),
    'usuarios' => users_activos(),
], 'Empresas');

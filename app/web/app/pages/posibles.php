<?php
declare(strict_types=1);
require_login();

$f = [
    'q'              => trim((string)($_GET['q'] ?? '')),
    'estado'         => $_GET['estado'] ?? '',
    'fuente'         => $_GET['fuente'] ?? '',
    'calificacion'   => $_GET['calificacion'] ?? '',
    'propietario_id' => $_GET['propietario_id'] ?? '',
    'convertidos'    => $_GET['convertidos'] ?? '',
    'page'           => $_GET['page'] ?? 1,
];

if (($_GET['exportar'] ?? '') === '1') {
    [$where, $p] = posibles_where($f);
    $rows = q("SELECT pc.*, u.nombre AS propietario FROM posibles_clientes pc
               LEFT JOIN usuarios u ON u.id = pc.propietario_id
               $where ORDER BY pc.apellido, pc.nombre", $p)->fetchAll();
    log_app('posible.exportar', ['detalle' => count($rows) . ' posibles clientes exportados a CSV']);
    exportar_csv('posibles-clientes-' . date('Ymd-His') . '.csv', $rows, [
        'nombre' => 'Nombre', 'apellido' => 'Apellido', 'empresa' => 'Empresa', 'cargo' => 'Cargo',
        'email' => 'Correo', 'telefono' => 'Teléfono', 'movil' => 'Móvil', 'conmutador' => 'Conmutador',
        'estado' => 'Estado', 'calificacion' => 'Calificación', 'fuente' => 'Fuente',
        'localidad' => 'Localidad', 'provincia' => 'Provincia', 'propietario' => 'Propietario',
    ]);
}

render('posibles', [
    'f'        => $f,
    'data'     => posibles_listar($f),
    'usuarios' => users_activos(),
], 'Posibles clientes');

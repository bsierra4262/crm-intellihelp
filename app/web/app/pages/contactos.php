<?php
declare(strict_types=1);
require_login();

// Aprobar varias de una. Es lo que vuelve manejable normalizar una base
// importada: se filtra por pendientes, se repasa la pantalla y se aprueba lo
// que está bien así como está, en vez de abrir ficha por ficha.
if (is_post()) {
    verify_csrf();
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    if ($ids) {
        $aprobar = ($_POST['accion'] ?? 'aprobar') === 'aprobar';
        $n = aprobar_registros('contactos', $ids, $aprobar);
        flash($n > 0
            ? ($aprobar ? "Se aprobaron $n fichas." : "Se devolvieron $n fichas a pendientes.")
            : 'No hubo cambios: ya estaban así.', $n > 0 ? 'success' : 'info');
    } else {
        flash('No marcaste ninguna ficha.', 'info');
    }
    redirect('contactos' . query_con([]));
}

$f = [
    'q'              => trim((string)($_GET['q'] ?? '')),
    'cuenta_id'      => $_GET['cuenta_id'] ?? '',
    'propietario_id' => $_GET['propietario_id'] ?? '',
    'provincia'      => $_GET['provincia'] ?? '',
    'fuente'         => $_GET['fuente'] ?? '',
    'con_email'      => $_GET['con_email'] ?? '',
    'no_email'       => $_GET['no_email'] ?? '',
    'rebotado'       => $_GET['rebotado'] ?? '',
    'pendiente'      => $_GET['pendiente'] ?? '',
    'orden'          => $_GET['orden'] ?? '',
    'page'           => $_GET['page'] ?? 1,
];

// Exportación: la misma consulta, sin paginar, servida como CSV.
if (($_GET['exportar'] ?? '') === '1') {
    [$where, $p] = contactos_where($f);
    $rows = q("SELECT ct.*, cu.nombre AS cuenta, u.nombre AS propietario
               FROM contactos ct
               LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
               LEFT JOIN usuarios u ON u.id = ct.propietario_id
               $where ORDER BY ct.apellido, ct.nombre", $p)->fetchAll();
    log_app('contacto.exportar', ['detalle' => count($rows) . ' contactos exportados a CSV']);
    exportar_csv('contactos-' . date('Ymd-His') . '.csv', $rows, [
        'nombre' => 'Nombre', 'apellido' => 'Apellido', 'cuenta' => 'Empresa', 'cargo' => 'Cargo',
        'email' => 'Correo', 'email2' => 'Correo secundario', 'telefono' => 'Teléfono',
        'movil' => 'Móvil', 'conmutador' => 'Conmutador', 'localidad' => 'Localidad',
        'provincia' => 'Provincia', 'pais' => 'País', 'linkedin' => 'LinkedIn',
        'fuente' => 'Fuente', 'propietario' => 'Propietario', 'creado_en' => 'Alta',
    ]);
}

render('contactos', [
    'f'        => $f,
    'data'     => contactos_listar($f),
    'usuarios' => users_activos(),
], 'Contactos');

<?php
declare(strict_types=1);
require_login();

$q = trim((string)($_GET['q'] ?? ''));
$r = ['contactos' => [], 'cuentas' => [], 'posibles' => [], 'negocios' => []];

// Búsqueda global: la misma caja encuentra una persona, una empresa, un
// posible cliente o una oportunidad, sin tener que elegir el módulo antes.
if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';
    $r['contactos'] = q('SELECT ct.id, ct.nombre_completo, ct.email, ct.cargo, cu.nombre AS cuenta
                         FROM contactos ct LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
                         WHERE ct.eliminado_en IS NULL
                           AND (ct.nombre_completo LIKE ? OR ct.email LIKE ? OR ct.telefono LIKE ?
                                OR ct.movil LIKE ? OR ct.conmutador LIKE ?)
                         ORDER BY ct.apellido LIMIT 15', [$like, $like, $like, $like, $like])->fetchAll();

    $r['cuentas'] = q('SELECT id, nombre, sitio_web, localidad, provincia FROM cuentas
                       WHERE eliminado_en IS NULL AND (nombre LIKE ? OR sitio_web LIKE ? OR cuit LIKE ?)
                       ORDER BY nombre LIMIT 15', [$like, $like, $like])->fetchAll();

    $r['posibles'] = q("SELECT id, TRIM(CONCAT(COALESCE(nombre,''), ' ', apellido)) AS nombre_completo,
                               empresa, email, estado
                        FROM posibles_clientes
                        WHERE eliminado_en IS NULL AND convertido_en IS NULL
                          AND (nombre LIKE ? OR apellido LIKE ? OR empresa LIKE ? OR email LIKE ?)
                        ORDER BY apellido LIMIT 15", [$like, $like, $like, $like])->fetchAll();

    $r['negocios'] = q('SELECT n.id, n.nombre, n.importe, n.moneda, e.nombre AS etapa, cu.nombre AS cuenta
                        FROM negocios n JOIN etapas e ON e.id = n.etapa_id
                        LEFT JOIN cuentas cu ON cu.id = n.cuenta_id
                        WHERE n.eliminado_en IS NULL AND (n.nombre LIKE ? OR cu.nombre LIKE ?)
                        ORDER BY n.creado_en DESC LIMIT 15', [$like, $like])->fetchAll();

    log_app('buscar', ['tipo' => 'nav', 'detalle' => 'Buscó «' . recortar($q, 60) . '»']);
}

render('buscar', ['q' => $q, 'r' => $r], 'Búsqueda');

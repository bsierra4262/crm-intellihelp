<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: /campana-ver?id=N  (con sesión; módulo «campanas»).
//  La pantalla de una campaña: pasos pendientes, cifras, motivos de
//  omisión, vista previa y la cola de destinatarios paginada de a 50 con
//  filtro por estado y búsqueda. Pinta views/campana-ver.php.
//  Lee campanas y envios; no escribe nada. Las acciones van a
//  campana-accion y las cifras se refrescan solas desde api-campana.
// ============================================================

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? campana_get($id) : null;
if (!$c) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Campaña inexistente', 'mensaje' => ''], 'No encontrado');
    exit;
}

$fEnvios = [
    'estado' => $_GET['estado'] ?? '',
    'q'      => trim((string)($_GET['q'] ?? '')),
    'page'   => $_GET['page'] ?? 1,
];

$w = ['campana_id = ?'];
$p = [$id];
if ($fEnvios['estado']) { $w[] = 'estado = ?'; $p[] = $fEnvios['estado']; }
if ($fEnvios['q']) {
    $w[] = '(email LIKE ? OR nombre LIKE ? OR empresa LIKE ?)';
    $like = '%' . $fEnvios['q'] . '%';
    array_push($p, $like, $like, $like);
}
$where  = 'WHERE ' . implode(' AND ', $w);
$total  = (int) qv("SELECT COUNT(*) FROM envios $where", $p);
$pg     = paginar($total, $fEnvios['page'], 50);
$envios = q("SELECT * FROM envios $where ORDER BY FIELD(estado,'error','rebotado','rechazado','pendiente','enviando','enviado','omitido'), id
             LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();

// Vista previa real: se arma el mensaje con el primer destinatario de la cola.
// Se arma sin cuenta, así que remitente y Reply-To pueden diferir de los del
// envío real. Los datos del destinatario van escapados y la vista lo muestra
// en un iframe aislado (sandbox), no dentro de la página del CRM.
$muestra = q('SELECT * FROM envios WHERE campana_id = ? ORDER BY id LIMIT 1', [$id])->fetch() ?: null;
// Sin el píxel de apertura: mirar la campaña no es que el destinatario la abrió.
$vista   = $muestra ? campana_armar_mensaje($c, $muestra, null, false) : null;

render('campana-ver', [
    'c'       => $c,
    'envios'  => $envios,
    'data'    => $pg + ['total' => $total],
    'f'       => $fEnvios,
    'vista'   => $vista,
    'testing' => es_testing(),
    // Iniciar y cancelar: el creador, el responsable o un admin. Los demás
    // no ven esos botones (y campana-accion igual lo controla).
    'puedeGestionar' => campana_puede_gestionar($c, auth_user()),
    'motivos' => q("SELECT motivo, COUNT(*) n FROM envios WHERE campana_id = ? AND estado = 'omitido'
                    GROUP BY motivo ORDER BY n DESC", [$id])->fetchAll(),
    // Aperturas: el dato se venía guardando y no se mostraba en ningún lado.
    'aperturas' => (int) qv('SELECT COUNT(*) FROM envios WHERE campana_id = ? AND abierto_en IS NOT NULL', [$id]),
], $c['nombre']);

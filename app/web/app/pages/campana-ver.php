<?php
declare(strict_types=1);
require_login();

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
$muestra = q('SELECT * FROM envios WHERE campana_id = ? ORDER BY id LIMIT 1', [$id])->fetch() ?: null;
$vista   = $muestra ? campana_armar_mensaje($c, $muestra) : null;

render('campana-ver', [
    'c'       => $c,
    'envios'  => $envios,
    'data'    => $pg + ['total' => $total],
    'f'       => $fEnvios,
    'vista'   => $vista,
    'testing' => es_testing(),
    'motivos' => q("SELECT motivo, COUNT(*) n FROM envios WHERE campana_id = ? AND estado = 'omitido'
                    GROUP BY motivo ORDER BY n DESC", [$id])->fetchAll(),
    // Aperturas: el dato se venía guardando y no se mostraba en ningún lado.
    'aperturas' => (int) qv('SELECT COUNT(*) FROM envios WHERE campana_id = ? AND abierto_en IS NOT NULL', [$id]),
], $c['nombre']);

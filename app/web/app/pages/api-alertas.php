<?php
declare(strict_types=1);
require_login();

// Cuántas alertas sin leer, para la campana de la barra. Lo consulta el mismo
// script que refresca las campañas, así que no agrega una segunda ronda de
// consultas al servidor.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) current_user_id();
$ultimas = [];
foreach (notif_de($uid, true, 6) as $n) {
    $ultimas[] = [
        'id'      => (int)$n['id'],
        'titulo'  => (string)$n['titulo'],
        'detalle' => (string)($n['detalle'] ?? ''),
        'tipo'    => (string)$n['tipo'],
        'cuando'  => tiempo_relativo((string)$n['creada_en']),
        'url'     => $n['url'] ? url((string)$n['url']) : url('alertas'),
    ];
}
json_out(['ok' => true, 'sin_leer' => notif_sin_leer($uid), 'ultimas' => $ultimas]);

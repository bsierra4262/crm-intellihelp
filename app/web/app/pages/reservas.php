<?php
declare(strict_types=1);
require_login();

$f = [
    'estado' => $_GET['estado'] ?? 'confirmada',
    'q'      => trim((string)($_GET['q'] ?? '')),
    'todos'  => $_GET['todos'] ?? '',
    'page'   => $_GET['page'] ?? 1,
];

$w = ['1 = 1'];
$p = [];
if ($f['todos'] !== '1') { $w[] = 'r.usuario_id = ?'; $p[] = current_user_id(); }
if ($f['estado'])        { $w[] = 'r.estado = ?';     $p[] = $f['estado']; }
if ($f['q']) {
    $w[] = '(r.invitado_nombre LIKE ? OR r.invitado_email LIKE ? OR r.invitado_empresa LIKE ?)';
    $like = '%' . $f['q'] . '%';
    array_push($p, $like, $like, $like);
}
$where = 'WHERE ' . implode(' AND ', $w);

$total = (int) qv("SELECT COUNT(*) FROM agenda_reservas r $where", $p);
$pg    = paginar($total, $f['page'], 25);
$rows  = q("SELECT r.*, t.nombre AS tipo, u.nombre AS usuario
            FROM agenda_reservas r
            JOIN agenda_tipos t ON t.id = r.tipo_id
            JOIN usuarios u     ON u.id = r.usuario_id
            $where ORDER BY r.inicia_el DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();

// Por dónde llegó cada reunión: la entrada de la web apunta a la primera
// reserva, así que para las que se movieron se sigue la cadena hacia atrás
// hasta esa. Si la tabla todavía no existe (falta migrar), la columna queda
// con lo que dice la propia reserva.
$llego = [];
try {
    $raiz = [];
    foreach ($rows as $r) {
        $id   = (int)$r['id'];
        $prev = (int)($r['reprogramada_de'] ?? 0);
        for ($i = 0; $prev > 0 && $i < 20; $i++) {
            $id   = $prev;
            $prev = (int) qv('SELECT reprogramada_de FROM agenda_reservas WHERE id = ?', [$prev]);
        }
        $raiz[(int)$r['id']] = $id;
    }
    if ($raiz) {
        $ids = array_values(array_unique($raiz));
        $ph  = implode(', ', array_fill(0, count($ids), '?'));
        $porRaiz = [];
        foreach (q("SELECT reserva_id, canal, origen_pagina FROM entradas_web WHERE reserva_id IN ($ph)", $ids)
                 ->fetchAll() as $ew) {
            $porRaiz[(int)$ew['reserva_id']] = $ew;
        }
        foreach ($raiz as $rid => $root) {
            if (isset($porRaiz[$root])) { $llego[$rid] = $porRaiz[$root]; }
        }
    }
} catch (\Throwable $e) {
    $llego = [];
}

render('reservas', ['f' => $f, 'rows' => $rows, 'llego' => $llego, 'data' => $pg + ['total' => $total]],
       'Reuniones reservadas');

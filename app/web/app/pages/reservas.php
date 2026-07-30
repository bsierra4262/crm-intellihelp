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

render('reservas', ['f' => $f, 'rows' => $rows, 'data' => $pg + ['total' => $total]], 'Reuniones reservadas');

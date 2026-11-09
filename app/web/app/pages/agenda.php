<?php
declare(strict_types=1);
require_login();

$uid = current_user_id();

render('agenda', [
    'tipos'     => agenda_tipos_de((int)$uid, false),
    'conectado' => google_conectado((int)$uid),
    'proximas'  => q("SELECT r.*, t.nombre AS tipo
                      FROM agenda_reservas r JOIN agenda_tipos t ON t.id = r.tipo_id
                      WHERE r.usuario_id = ? AND r.estado = 'confirmada' AND r.inicia_el >= NOW()
                      ORDER BY r.inicia_el LIMIT 10", [$uid])->fetchAll(),
    // Los feriados de los próximos dos meses: esos días la agenda no ofrece horarios.
    'feriados'  => feriados_del_rango(date('Y-m-d'), date('Y-m-d', strtotime('+60 days'))),
], 'Agenda');

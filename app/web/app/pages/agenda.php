<?php
declare(strict_types=1);

// ============================================================
//  Pantalla «Agenda» (ruta `agenda`, módulo 'agenda', con login).
//
//  Muestra los tipos de reunión de quien entró (activos y ocultos) con su
//  enlace público, si su Google Calendar está conectado, sus próximas diez
//  reuniones confirmadas y los feriados de los próximos 60 días.
//
//  Lee agenda_tipos, agenda_reservas y feriados (agenda.php, feriados.php).
//  No escribe nada. Vista: views/agenda.php.
//
//  Cada quien ve sólo lo suyo, también un administrador: las reuniones de
//  todo el equipo están en `reservas`.
// ============================================================

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
    // Las fechas son las de la instalación (date()), no las de la zona de cada tipo.
    'feriados'  => feriados_del_rango(date('Y-m-d'), date('Y-m-d', strtotime('+60 days'))),
], 'Agenda');

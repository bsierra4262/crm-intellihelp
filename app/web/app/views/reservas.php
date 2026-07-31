<?php /** @var array $f @var array $rows @var array $data */ ?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Reuniones reservadas</h1>
        <p class="muted"><?= number_format($data['total'], 0, ',', '.') ?> reservas desde la agenda pública</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('agenda')) ?>"><?= icon('agenda', 16) ?>Mis tipos de reunión</a>
    </div>
</div>

<form class="filters card" method="get" action="<?= e(url('reservas')) ?>">
    <div class="filter-grid">
        <label><span>Buscar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nombre, correo, empresa…"></label>
        <label><span>Estado</span>
            <select name="estado" data-auto>
                <option value="">Todos</option>
                <?php foreach (['confirmada' => 'Confirmadas', 'cancelada' => 'Canceladas', 'reprogramada' => 'Reprogramadas'] as $k => $et): ?>
                    <option value="<?= e($k) ?>" <?= $f['estado'] === $k ? 'selected' : '' ?>><?= e($et) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('filtro', 15) ?>Filtrar</button>
        <button type="button" class="btn btn-ghost btn-sm" data-limpiar>Limpiar</button>
        <label class="switch push">
            <input type="checkbox" name="todos" value="1" <?= $f['todos'] === '1' ? 'checked' : '' ?>>
            <span class="track"></span><span class="small">Ver las de todo el equipo</span>
        </label>
    </div>
</form>

<div class="card table-card" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr><th>Cuándo</th><th>Invitado</th><th class="prio-2">Empresa</th><th>Tipo</th>
                    <th class="prio-3">Con</th><th>Estado</th><th class="acts">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="empty">Todavía nadie reservó. Las reuniones aparecen acá solas cuando
                    alguien entra por el enlace público de un <a href="<?= e(url('agenda')) ?>">tipo de reunión</a>
                    y elige un horario.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td data-label="Cuándo">
                        <div class="cell-main">
                            <strong><?= fecha_hora($r['inicia_el']) ?></strong>
                            <small><?= e(tiempo_relativo($r['inicia_el'])) ?></small>
                        </div>
                    </td>
                    <td data-label="Invitado">
                        <div class="cell-main">
                            <?php if ($r['contacto_id']): ?>
                                <a href="<?= e(url('contacto-ver?id=' . (int)$r['contacto_id'])) ?>"><strong><?= e($r['invitado_nombre']) ?></strong></a>
                            <?php else: ?><strong><?= e($r['invitado_nombre']) ?></strong><?php endif; ?>
                            <small><?= e($r['invitado_email']) ?></small>
                        </div>
                    </td>
                    <td data-label="Empresa" class="prio-2"><?= e(recortar($r['invitado_empresa'], 28) ?: '—') ?></td>
                    <td data-label="Tipo"><?= e($r['tipo']) ?></td>
                    <td data-label="Con" class="prio-3"><?= e($r['usuario']) ?></td>
                    <td data-label="Estado">
                        <span class="badge <?= $r['estado'] === 'confirmada' ? 'badge-ok' : 'badge-neutral' ?>"><?= e(ucfirst($r['estado'])) ?></span>
                        <?php if ($r['estado'] === 'confirmada' && empty($r['gcal_event_id'])): ?>
                            <?php /* La reserva se guarda aunque Google falle, pero eso no puede
                                     quedar invisible: la reunión no está en el calendario y se
                                     puede pisar sin que nadie se entere. */ ?>
                            <br><span class="badge badge-pend"
                                      title="La reserva quedó tomada pero no se creó el evento en Google Calendar. Revisá la conexión y agendala a mano.">Sin evento en el calendario</span>
                        <?php endif; ?>
                    </td>
                    <td class="acts" data-label="Acciones">
                        <?php if ($r['meet_url']): ?>
                            <a class="act" href="<?= e($r['meet_url']) ?>" target="_blank" rel="noopener" title="Entrar por Meet"><?= icon('video', 16) ?></a>
                        <?php endif; ?>
                        <?php if ($r['actividad_id']): ?>
                            <a class="act" href="<?= e(url('actividad-form?id=' . (int)$r['actividad_id'])) ?>" title="Ver la actividad"><?= icon('tareas', 16) ?></a>
                        <?php endif; ?>
                        <?php if ($r['estado'] === 'confirmada'): ?>
                            <?php /* Mover y cancelar sólo se podían hacer desde el enlace que
                                     recibía el invitado: si llamaba para correr la reunión,
                                     había que entrar al calendario de Google a mano. */ ?>
                            <a class="act" title="Mover a otro horario"
                               href="<?= e(url('reserva-mover?id=' . (int)$r['id'])) ?>"><?= icon('agenda', 16) ?></a>
                            <form class="inline-form" method="post" action="<?= e(url('reserva-accion')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="cancelar">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="act danger" title="Cancelar la reunión" data-peligro
                                        data-confirmar-titulo="Cancelar la reunión"
                                        data-confirmar="Se cancela la reunión con <?= e($r['invitado_nombre']) ?>. Le avisamos por correo y la sacamos del calendario."
                                        data-confirmar-ok="Cancelar"><?= icon('x', 16) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP . '/views/_pager.php'; ?>

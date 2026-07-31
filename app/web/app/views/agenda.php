<?php
/** @var array $tipos @var bool $conectado @var array $proximas */
$u = auth_user();
$usuarioSlug = explode('@', (string)$u['email'])[0];
?>
<div class="page-head">
    <div>
        <h1>Agenda</h1>
        <p class="muted">Tu página pública de reservas, con Google Calendar y Meet</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('reservas')) ?>"><?= icon('agenda', 16) ?>Reuniones reservadas</a>
        <a class="btn btn-primary" href="<?= e(url('agenda-form')) ?>"><?= icon('mas', 16) ?>Nuevo tipo de reunión</a>
    </div>
</div>
<?php include APP . '/views/_aviso_gcal.php'; ?>


<?php if (!$conectado): ?>
    <div class="flash flash-warn">
        <strong>Falta conectar tu Google Calendar.</strong>
        Sin eso no podemos ver tus horarios ocupados ni crear el Meet automáticamente.
        <a href="<?= e(url('google-login?conectar=1')) ?>">Conectar ahora</a>.
    </div>
<?php else: ?>
    <div class="flash flash-success">
        Tu Google Calendar está conectado: los horarios ocupados no se ofrecen y cada reserva
        crea el evento con su Google Meet.
    </div>
<?php endif; ?>

<div class="card table-card mb-4">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Tipo de reunión</th><th>Duración</th><th>Enlace público</th><th>Estado</th><th class="acts">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$tipos): ?>
                <tr><td colspan="5" class="empty">Todavía no creaste ningún tipo de reunión. Creá uno y compartí el enlace en tus correos.</td></tr>
            <?php else: foreach ($tipos as $t): $enlace = url_abs('r/' . $usuarioSlug . '/' . $t['slug']); ?>
                <tr>
                    <td data-label="Tipo">
                        <div class="cell-main">
                            <a href="<?= e(url('agenda-form?id=' . (int)$t['id'])) ?>"><strong><?= e($t['nombre']) ?></strong></a>
                            <?php if ($t['con_meet']): ?><small>Crea Google Meet</small><?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Duración"><?= (int)$t['duracion_min'] ?> min</td>
                    <td data-label="Enlace">
                        <a href="<?= e($enlace) ?>" target="_blank" rel="noopener" class="mono"><?= e(recortar($enlace, 46)) ?></a>
                    </td>
                    <td data-label="Estado">
                        <span class="badge <?= $t['activo'] ? 'badge-ok' : 'badge-neutral' ?>"><?= $t['activo'] ? 'Publicado' : 'Oculto' ?></span>
                    </td>
                    <td class="acts" data-label="Acciones">
                        <a class="act" href="<?= e($enlace) ?>" target="_blank" rel="noopener" title="Abrir la página pública"><?= icon('enlace', 16) ?></a>
                        <a class="act" href="<?= e(url('agenda-form?id=' . (int)$t['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Próximas reuniones</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('reservas')) ?>">Ver todas</a>
    </div>
    <?php if (!$proximas): ?>
        <p class="muted small">No tenés reuniones agendadas.</p>
    <?php else: ?>
        <div class="timeline">
            <?php foreach ($proximas as $r): ?>
                <div class="tl-item">
                    <div class="tl-when"><?= fecha_hora($r['inicia_el']) ?> · <?= e($r['tipo']) ?></div>
                    <div class="tl-title"><?= e($r['invitado_nombre']) ?><?= $r['invitado_empresa'] ? ' · ' . e($r['invitado_empresa']) : '' ?></div>
                    <div class="tl-detail">
                        <?= e($r['invitado_email']) ?>
                        <?php if ($r['meet_url']): ?> · <a href="<?= e($r['meet_url']) ?>" target="_blank" rel="noopener">Entrar por Meet</a><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

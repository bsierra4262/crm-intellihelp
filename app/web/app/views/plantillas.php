<?php /** @var array $rows */ ?>
<div class="page-head">
    <div>
        <h1>Plantillas de correo</h1>
        <p class="muted">El texto que se manda en las campañas, con variables por destinatario</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('campanas')) ?>"><?= icon('campanas', 16) ?>Campañas</a>
        <a class="btn btn-primary" href="<?= e(url('plantilla-form')) ?>"><?= icon('mas', 16) ?>Nueva plantilla</a>
    </div>
</div>

<div class="card table-card">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr><th>Plantilla</th><th>Asunto</th><th class="prio-2">Carpeta</th><th>Estado</th><th class="prio-3">Actualizada</th><th class="acts">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="empty">Todavía no hay plantillas. Creá la primera para poder armar una campaña.</td></tr>
            <?php else: foreach ($rows as $p): ?>
                <tr>
                    <td data-label="Plantilla"><a href="<?= e(url('plantilla-form?id=' . (int)$p['id'])) ?>"><strong><?= e($p['nombre']) ?></strong></a></td>
                    <td data-label="Asunto"><?= e(recortar($p['asunto'], 50)) ?></td>
                    <td data-label="Carpeta" class="prio-2"><?= e($p['carpeta'] ?: '—') ?></td>
                    <td data-label="Estado">
                        <span class="badge <?= $p['activo'] ? 'badge-ok' : 'badge-neutral' ?>"><?= $p['activo'] ? 'Activa' : 'Inactiva' ?></span>
                    </td>
                    <td data-label="Actualizada" class="prio-3"><?= fecha_hora($p['actualizado_en']) ?></td>
                    <td class="acts" data-label="Acciones">
                        <a class="act" href="<?= e(url('plantilla-form?id=' . (int)$p['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

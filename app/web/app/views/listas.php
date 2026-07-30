<?php /** @var array $rows */ ?>
<div class="page-head">
    <div>
        <h1>Listas de destinatarios</h1>
        <p class="muted">A quién se le manda cada campaña</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('campanas')) ?>"><?= icon('campanas', 16) ?>Campañas</a>
        <a class="btn btn-primary" href="<?= e(url('lista-form')) ?>"><?= icon('mas', 16) ?>Nueva lista</a>
    </div>
</div>

<div class="card table-card">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Lista</th><th class="num">Miembros</th><th class="prio-2">Descripción</th><th class="prio-3">Creada</th><th class="acts">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="empty">Todavía no hay listas. Creá una y cargala con contactos del CRM.</td></tr>
            <?php else: foreach ($rows as $l): ?>
                <tr>
                    <td data-label="Lista"><a href="<?= e(url('lista-ver?id=' . (int)$l['id'])) ?>"><strong><?= e($l['nombre']) ?></strong></a></td>
                    <td data-label="Miembros" class="num strong"><?= number_format((int)$l['miembros'], 0, ',', '.') ?></td>
                    <td data-label="Descripción" class="prio-2"><?= e(recortar($l['descripcion'], 60) ?: '—') ?></td>
                    <td data-label="Creada" class="prio-3"><?= fecha_corta($l['creado_en']) ?></td>
                    <td class="acts" data-label="Acciones">
                        <a class="act" href="<?= e(url('lista-ver?id=' . (int)$l['id'])) ?>" title="Ver miembros"><?= icon('contactos', 16) ?></a>
                        <a class="act" href="<?= e(url('lista-form?id=' . (int)$l['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

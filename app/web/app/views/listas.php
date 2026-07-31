<?php /** @var array $rows */ ?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Listas de destinatarios</h1>
        <p class="muted">A quién se le manda cada campaña</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('campanas')) ?>"><?= icon('campanas', 16) ?>Campañas</a>
        <a class="btn btn-primary" href="<?= e(url('lista-form')) ?>"><?= icon('mas', 16) ?>Nueva lista</a>
    </div>
</div>

<div class="card table-card" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Lista</th><th>Segmento</th><th class="num">Miembros</th><th class="prio-2">Descripción</th><th class="prio-3">Creada</th><th class="acts">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="empty">Todavía no hay listas. Creá una y cargala con contactos del CRM.</td></tr>
            <?php else: foreach ($rows as $l): ?>
                <tr>
                    <td data-label="Lista"><a href="<?= e(url('lista-ver?id=' . (int)$l['id'])) ?>"><strong><?= e($l['nombre']) ?></strong></a></td>
                    <td data-label="Segmento">
                        <?php if ($l['categoria']): ?>
                            <span class="chip" style="background:<?= e($l['categoria_color']) ?>1f;color:<?= e($l['categoria_color']) ?>"><?= e($l['categoria']) ?></span>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td data-label="Miembros" class="num strong"><?= number_format((int)$l['miembros'], 0, ',', '.') ?></td>
                    <td data-label="Descripción" class="prio-2"><?= e(recortar($l['descripcion'], 60) ?: '—') ?></td>
                    <td data-label="Creada" class="prio-3"><?= fecha_corta($l['creado_en']) ?></td>
                    <td class="acts" data-label="Acciones">
                        <form class="inline-form" method="post" action="<?= e(url('registro-eliminar')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entidad" value="lista">
                            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                            <input type="hidden" name="volver" value="listas">
                            <button class="act danger" title="Enviar a la papelera" data-peligro
                                    data-confirmar-titulo="Enviar a la papelera"
                                    data-confirmar="La lista &laquo;<?= e($l['nombre']) ?>&raquo; deja de estar a la vista. Los contactos no se borran. Se puede restaurar durante 60 días."
                                    data-confirmar-ok="Enviar a la papelera"><?= icon('papelera', 16) ?></button>
                        </form>

                        <a class="act" href="<?= e(url('lista-ver?id=' . (int)$l['id'])) ?>" title="Ver miembros"><?= icon('contactos', 16) ?></a>
                        <a class="act" href="<?= e(url('lista-form?id=' . (int)$l['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

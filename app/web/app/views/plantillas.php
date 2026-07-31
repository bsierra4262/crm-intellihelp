<?php /** @var array $rows @var array $preajustes */ ?>
<div class="page-head">
    <div>
        <h1>Plantillas de correo</h1>
        <p class="muted">El diseño y el texto que se manda en las campañas</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('campanas')) ?>"><?= icon('campanas', 16) ?>Campañas</a>
        <a class="btn btn-ghost" href="<?= e(url('imagenes')) ?>"><?= icon('subir', 16) ?>Imágenes</a>
        <a class="btn btn-primary" href="<?= e(url('plantilla-form')) ?>"><?= icon('mas', 16) ?>Empezar de cero</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-head">
        <h2>Empezar desde un diseño armado</h2>
        <span class="small muted">Se copia y después lo editás como quieras</span>
    </div>
    <div class="preajustes">
        <?php foreach ($preajustes as $clave => $pre): ?>
            <a class="preajuste" href="<?= e(url('plantilla-form?desde=' . $clave)) ?>">
                <span class="preajuste-vista preajuste-<?= e($clave) ?>" aria-hidden="true">
                    <span class="pv-barra"></span><span class="pv-logo"></span>
                    <span class="pv-hero"></span><span class="pv-linea"></span>
                    <span class="pv-linea corta"></span><span class="pv-boton"></span>
                </span>
                <strong><?= e($pre['nombre']) ?></strong>
                <small><?= e($pre['descripcion']) ?></small>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card table-card">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr><th>Plantilla</th><th>Asunto</th><th class="prio-2">Carpeta</th><th>Estado</th>
                    <th class="prio-3">Actualizada</th><th class="acts">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="empty">Todavía no hay plantillas. Elegí uno de los diseños de arriba para arrancar.</td></tr>
            <?php else: foreach ($rows as $p): ?>
                <tr>
                    <td data-label="Plantilla">
                        <div class="cell-main">
                            <a href="<?= e(url('plantilla-form?id=' . (int)$p['id'])) ?>"><strong><?= e($p['nombre']) ?></strong></a>
                            <small><?= $p['tipo'] === 'visual' ? 'Editor visual' : 'HTML propio' ?></small>
                        </div>
                    </td>
                    <td data-label="Asunto"><?= e(recortar($p['asunto'], 50)) ?></td>
                    <td data-label="Carpeta" class="prio-2"><?= e($p['carpeta'] ?: '—') ?></td>
                    <td data-label="Estado">
                        <span class="badge <?= $p['activo'] ? 'badge-ok' : 'badge-neutral' ?>"><?= $p['activo'] ? 'Activa' : 'Inactiva' ?></span>
                    </td>
                    <td data-label="Actualizada" class="prio-3"><?= fecha_hora($p['actualizado_en']) ?></td>
                    <td class="acts" data-label="Acciones">
                        <form class="inline-form" method="post" action="<?= e(url('registro-eliminar')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entidad" value="plantilla">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="volver" value="plantillas">
                            <button class="act danger" title="Enviar a la papelera" data-peligro
                                    data-confirmar-titulo="Enviar a la papelera"
                                    data-confirmar="La plantilla &laquo;<?= e($p['nombre']) ?>&raquo; deja de estar a la vista. Las campañas que ya la usaron no se tocan. Se puede restaurar durante 60 días."
                                    data-confirmar-ok="Enviar a la papelera"><?= icon('papelera', 16) ?></button>
                        </form>

                        <a class="act" href="<?= e(url('plantilla-form?id=' . (int)$p['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                        <a class="act" href="<?= e(url('plantilla-vista?id=' . (int)$p['id'])) ?>" target="_blank" title="Ver como lo recibe el cliente"><?= icon('enlace', 16) ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

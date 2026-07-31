<?php /** @var ?array $aviso @var array $rows */ ?>
<div class="page-head">
    <div>
        <h1>Imágenes de las campañas</h1>
        <p class="muted">Se suben una vez y se usan en cualquier plantilla</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('plantillas')) ?>"><?= icon('plantillas', 16) ?>Plantillas</a>
    </div>
</div>

<?php if ($aviso): ?><div class="flash flash-<?= e($aviso[0]) ?>"><?= e($aviso[1]) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1rem">
    <div class="card-head"><h2>Subir una imagen</h2></div>
    <p class="muted small" style="margin:-.3rem 0 .9rem">
        Ideal por debajo de <strong>100 KB</strong> y no más ancha de <strong>600 px</strong>: es el ancho del correo.
        Las imágenes pesadas tardan en cargar y perjudican la entrega. Acordate de que muchos
        clientes bloquean las imágenes por defecto, así que el mensaje nunca puede depender sólo de ellas.
    </p>
    <form method="post" enctype="multipart/form-data" class="urow">
        <?= csrf_field() ?>
        <label class="filefield" style="flex:1 1 320px">
            <input type="file" name="archivo" accept="image/png,image/jpeg,image/gif,image/webp" required>
            <span class="btn btn-ghost btn-sm"><?= icon('subir', 15) ?>Elegir imagen</span>
            <span class="nombre">Ningún archivo seleccionado</span>
        </label>
        <button class="btn btn-primary"><?= icon('subir', 16) ?>Subir</button>
    </form>
</div>

<?php if (!$rows): ?>
    <div class="empty-state">
        <?= icon('subir', 40) ?>
        <h2>Todavía no hay imágenes</h2>
        <p>Subí la primera y va a aparecer para elegir dentro del editor de plantillas.</p>
    </div>
<?php else: ?>
<div class="galeria">
    <?php foreach ($rows as $im): $url = url_abs('img?a=' . $im['archivo']); ?>
        <div class="gal-item card">
            <img src="<?= e($url) ?>" alt="<?= e($im['nombre']) ?>" loading="lazy">
            <div class="gal-info">
                <strong><?= e(recortar($im['nombre'], 28)) ?></strong>
                <small>
                    <?= round($im['bytes'] / 1024) ?> KB
                    <?php if ($im['ancho']): ?> · <?= (int)$im['ancho'] ?>×<?= (int)$im['alto'] ?><?php endif; ?>
                    <?php if ($im['bytes'] > 100 * 1024): ?>
                        <span class="badge badge-pend">pesada</span>
                    <?php endif; ?>
                </small>
            </div>
            <div class="gal-ops">
                <input class="gal-url" value="<?= e($url) ?>" readonly onclick="this.select()" aria-label="URL de la imagen">
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="borrar">
                    <input type="hidden" name="id" value="<?= (int)$im['id'] ?>">
                    <button class="act danger" title="Eliminar" data-peligro
                            data-confirmar-titulo="Eliminar la imagen"
                            data-confirmar="Si alguna plantilla la está usando, ahí va a quedar un hueco."
                            data-confirmar-ok="Eliminar"><?= icon('papelera', 16) ?></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

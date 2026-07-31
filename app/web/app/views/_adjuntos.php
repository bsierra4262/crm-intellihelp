<?php
/**
 * Archivos adjuntos de un registro.
 *
 * La tabla y el comentario que la menciona existían desde el principio, pero no
 * había ninguna pantalla para subir nada: era un cajón vacío al que nadie podía
 * llegar. Los archivos van fuera del web root y se descargan por una ruta que
 * comprueba la sesión, así un presupuesto o un contrato no queda expuesto por
 * adivinar la dirección.
 *
 * @var string $adjEntidad  contacto | cuenta | posible_cliente | negocio
 * @var int    $adjId
 */
$adjLista = adjuntos_de($adjEntidad, $adjId);
?>
<div class="card">
    <div class="card-head"><h2>Archivos</h2>
        <span class="small muted"><?= count($adjLista) ?: 'ninguno' ?></span>
    </div>
    <p class="muted small card-intro">
        Presupuestos, pliegos, órdenes de compra. Hasta 10 MB por archivo.
        No se publican: sólo los ve quien entra al sistema.
    </p>

    <?php if ($adjLista): ?>
        <?php foreach ($adjLista as $a): ?>
            <div class="row-sep urow">
                <a class="grow" href="<?= e(url('adjunto?id=' . (int)$a['id'])) ?>">
                    <?= icon('nota', 15) ?> <?= e($a['nombre']) ?>
                </a>
                <span class="small muted nowrap"><?= e(peso_legible((int)$a['bytes'])) ?></span>
                <form class="inline-form" method="post" action="<?= e(url('adjunto-subir')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="borrar">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="act danger" title="Borrar el archivo" data-peligro
                            data-confirmar-titulo="Borrar el archivo"
                            data-confirmar="Se borra «<?= e($a['nombre']) ?>» definitivamente. Esto no va a la papelera."
                            data-confirmar-ok="Borrar"><?= icon('papelera', 15) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="post" action="<?= e(url('adjunto-subir')) ?>" enctype="multipart/form-data" class="urow mt-3">
        <?= csrf_field() ?>
        <input type="hidden" name="entidad" value="<?= e($adjEntidad) ?>">
        <input type="hidden" name="entidad_id" value="<?= (int)$adjId ?>">
        <label class="filefield grow">
            <input type="file" name="archivo" required>
            <span class="btn btn-ghost btn-sm"><?= icon('subir', 15) ?>Elegir archivo</span>
            <span class="nombre">Ningún archivo seleccionado</span>
        </label>
        <button class="btn btn-ghost btn-sm"><?= icon('subir', 15) ?>Subir</button>
    </form>
</div>

<?php
/**
 * Archivos adjuntos de un registro.
 *
 * Se incluye desde las fichas, que definen $adjEntidad y $adjId; lee la
 * lista con adjuntos_de(). Subir y borrar van los dos a adjunto-subir
 * (accion=borrar para borrar). La descarga va por la ruta adjunto?id=.
 * Borrar es lógico: el archivo va a la papelera (solapa Archivos) y el cron
 * lo purga pasado el plazo. El botón sólo aparece para el autor del archivo
 * o un administrador (el servidor igual lo controla).
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
$adjUid   = current_user_id();
$adjAdmin = auth_is_admin();
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
                <?php if (puede_borrar_adjunto($a, $adjUid, $adjAdmin)): ?>
                <form class="inline-form" method="post" action="<?= e(url('adjunto-subir')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="borrar">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="act danger" title="Borrar el archivo" data-peligro
                            data-confirmar-titulo="Borrar el archivo"
                            data-confirmar="«<?= e($a['nombre']) ?>» va a la papelera. Un administrador puede restaurarlo durante <?= (int) dias_papelera() ?> días."
                            data-confirmar-ok="Borrar"><?= icon('papelera', 15) ?></button>
                </form>
                <?php endif; ?>
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

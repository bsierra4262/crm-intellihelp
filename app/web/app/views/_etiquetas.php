<?php
/**
 * Etiquetas de un registro: se ven y se editan en la misma ficha.
 *
 * El subsistema estaba construido entero —tablas, funciones para asignar y
 * leer— y no había una sola pantalla que lo usara: no se podía etiquetar nada.
 *
 * @var string $etqEntidad  contacto | cuenta | posible_cliente | negocio
 * @var int    $etqId
 */
$etqActuales = etiquetas_de($etqEntidad, $etqId);
?>
<div class="card">
    <div class="card-head"><h2>Etiquetas</h2></div>
    <p class="muted small card-intro">
        Sirven para agrupar por lo que a vos te importa y que los filtros no dependan sólo de
        los campos fijos. Separalas con coma; las que no existan se crean solas.
    </p>
    <?php if ($etqActuales): ?>
        <div class="tag-row mb-3">
            <?php foreach ($etqActuales as $et): ?>
                <span class="chip" style="background:<?= e($et['color'] ?: '#663399') ?>1f;color:<?= e($et['color'] ?: '#663399') ?>"><?= e($et['nombre']) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('etiquetas-guardar')) ?>" class="urow">
        <?= csrf_field() ?>
        <input type="hidden" name="entidad" value="<?= e($etqEntidad) ?>">
        <input type="hidden" name="id" value="<?= (int)$etqId ?>">
        <input name="etiquetas" class="grow" maxlength="400"
               list="etiquetas-conocidas"
               value="<?= e(implode(', ', array_column($etqActuales, 'nombre'))) ?>"
               placeholder="petroleras, decisor, retomar en marzo…">
        <datalist id="etiquetas-conocidas">
            <?php foreach (etiquetas_todas() as $et): ?>
                <option value="<?= e($et['nombre']) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <button class="btn btn-ghost btn-sm"><?= icon('ok', 15) ?>Guardar</button>
    </form>
</div>

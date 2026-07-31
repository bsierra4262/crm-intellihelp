<?php
/**
 * Panel de notas de un registro. Espera $notas y, en el scope, la entidad y el
 * id (se deducen de la ruta actual para no repetirlos en cada vista).
 */
/** @var array $notas */
$ent = match (current_route()) {
    'contacto-ver' => 'contacto',
    'cuenta-ver'   => 'cuenta',
    'posible-ver'  => 'posible_cliente',
    'negocio-ver'  => 'negocio',
    default        => '',
};
$entId = (int)($_GET['id'] ?? 0);
?>
<div class="card">
    <div class="card-head"><h2>Notas</h2></div>
    <?php if ($ent): ?>
    <form method="post" action="<?= e(url('nota-guardar')) ?>" class="mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="entidad" value="<?= e($ent) ?>">
        <input type="hidden" name="entidad_id" value="<?= $entId ?>">
        <label class="fld">
            <textarea name="cuerpo" rows="3" placeholder="Escribí lo que pasó en la reunión, la llamada o el correo…" required></textarea>
        </label>
        <div class="mt-3"><button class="btn btn-primary btn-sm"><?= icon('nota', 14) ?>Agregar nota</button></div>
    </form>
    <?php endif; ?>

    <?php if (!$notas): ?>
        <p class="muted small">Todavía no hay notas.</p>
    <?php else: ?>
        <div class="timeline">
            <?php foreach ($notas as $n): ?>
                <div class="tl-item">
                    <div class="tl-when"><?= fecha_hora($n['creado_en']) ?> · <?= e($n['autor'] ?: 'sistema') ?></div>
                    <?php if ($n['titulo']): ?><div class="tl-title"><?= e($n['titulo']) ?></div><?php endif; ?>
                    <div class="tl-detail cuerpo"><?= e($n['cuerpo']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

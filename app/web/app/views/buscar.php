<?php /** @var string $q @var array $r */
$total = count($r['contactos']) + count($r['cuentas']) + count($r['posibles']) + count($r['negocios']);
?>
<div class="page-head">
    <div>
        <h1>Búsqueda</h1>
        <p class="muted"><?= $q === '' ? 'Escribí algo en el buscador de arriba.' : $total . ' resultados para «' . e($q) . '»' ?></p>
    </div>
</div>

<?php if ($q !== '' && $total === 0): ?>
    <div class="empty-state">
        <?= icon('buscar', 40) ?>
        <h2>No encontramos nada</h2>
        <p>Probá con parte del nombre, del correo, del teléfono o de la empresa.</p>
    </div>
<?php endif; ?>

<?php if ($r['contactos']): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-head"><h2><?= icon('contactos', 16) ?> Contactos</h2></div>
    <?php foreach ($r['contactos'] as $x): ?>
        <div style="padding:.5rem 0;border-bottom:1px solid var(--line-soft)">
            <a href="<?= e(url('contacto-ver?id=' . (int)$x['id'])) ?>" class="strong"><?= e($x['nombre_completo']) ?></a>
            <div class="small muted"><?= e(implode(' · ', array_filter([$x['cargo'], $x['cuenta'], $x['email']]))) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($r['cuentas']): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-head"><h2><?= icon('cuentas', 16) ?> Empresas</h2></div>
    <?php foreach ($r['cuentas'] as $x): ?>
        <div style="padding:.5rem 0;border-bottom:1px solid var(--line-soft)">
            <a href="<?= e(url('cuenta-ver?id=' . (int)$x['id'])) ?>" class="strong"><?= e($x['nombre']) ?></a>
            <div class="small muted"><?= e(implode(' · ', array_filter([$x['localidad'], $x['provincia'], $x['sitio_web']]))) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($r['posibles']): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-head"><h2><?= icon('posibles', 16) ?> Posibles clientes</h2></div>
    <?php foreach ($r['posibles'] as $x): ?>
        <div style="padding:.5rem 0;border-bottom:1px solid var(--line-soft)">
            <a href="<?= e(url('posible-ver?id=' . (int)$x['id'])) ?>" class="strong"><?= e($x['nombre_completo']) ?></a>
            <span class="badge badge-neutral"><?= e($x['estado']) ?></span>
            <div class="small muted"><?= e(implode(' · ', array_filter([$x['empresa'], $x['email']]))) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($r['negocios']): ?>
<div class="card">
    <div class="card-head"><h2><?= icon('negocios', 16) ?> Negocios</h2></div>
    <?php foreach ($r['negocios'] as $x): ?>
        <div style="padding:.5rem 0;border-bottom:1px solid var(--line-soft)">
            <a href="<?= e(url('negocio-ver?id=' . (int)$x['id'])) ?>" class="strong"><?= e($x['nombre']) ?></a>
            <span class="badge badge-info"><?= e($x['etapa']) ?></span>
            <div class="small muted"><?= e($x['cuenta'] ?: 'sin empresa') ?> · <?= money((float)$x['importe'], $x['moneda']) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

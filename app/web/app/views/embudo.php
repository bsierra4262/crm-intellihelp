<?php
/** @var array $embudos @var int $embudoId @var array $cols @var array $f @var array $usuarios */
$totalAbierto = 0.0; $totalNegocios = 0;
foreach ($cols as $c) {
    if ($c['etapa']['tipo'] === 'abierta') { $totalAbierto += $c['total']; }
    $totalNegocios += count($c['negocios']);
}
?>
<div class="page-head">
    <div>
        <h1>Embudo de ventas</h1>
        <p class="muted"><?= $totalNegocios ?> negocios · <?= money($totalAbierto) ?> abiertos</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('negocios')) ?>"><?= icon('listas', 16) ?>Ver como lista</a>
        <a class="btn btn-primary" href="<?= e(url('negocio-form')) ?>"><?= icon('mas', 16) ?>Nuevo negocio</a>
    </div>
</div>

<form class="filters card" method="get" action="<?= e(url('embudo')) ?>">
    <div class="filter-grid">
        <?php if (count($embudos) > 1): ?>
        <label><span>Embudo</span>
            <select name="embudo_id" data-auto>
                <?php foreach ($embudos as $em): ?>
                    <option value="<?= (int)$em['id'] ?>" <?= $embudoId === (int)$em['id'] ? 'selected' : '' ?>><?= e($em['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php else: ?><input type="hidden" name="embudo_id" value="<?= $embudoId ?>"><?php endif; ?>
        <label><span>Buscar</span>
            <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Negocio o empresa…">
        </label>
        <label><span>Propietario</span>
            <select name="propietario_id" data-auto>
                <option value="">Todos</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (string)$f['propietario_id'] === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('filtro', 15) ?>Filtrar</button>
        <button type="button" class="btn btn-ghost btn-sm" data-limpiar>Limpiar</button>
    </div>
</form>

<div class="kanban">
    <?php foreach ($cols as $col): $et = $col['etapa']; ?>
        <div class="kcol">
            <div class="kcol-head">
                <strong><span class="kcol-dot" style="background:<?= e($et['color']) ?>"></span><?= e($et['nombre']) ?></strong>
                <span class="kcol-sum"><?= count($col['negocios']) ?> · <?= money($col['total']) ?></span>
            </div>
            <div class="kcol-body">
                <?php if (!$col['negocios']): ?>
                    <div class="kcol-empty">Sin negocios</div>
                <?php else: foreach ($col['negocios'] as $n): ?>
                    <a class="kcard" href="<?= e(url('negocio-ver?id=' . (int)$n['id'])) ?>">
                        <strong><?= e(recortar($n['nombre'], 46)) ?></strong>
                        <?php if ($n['cuenta']): ?><div class="small muted"><?= e(recortar($n['cuenta'], 34)) ?></div><?php endif; ?>
                        <div class="kmeta">
                            <span class="kimp"><?= money((float)$n['importe'], $n['moneda']) ?></span>
                            <span><?= $n['fecha_cierre'] ? fecha_corta($n['fecha_cierre']) : 'sin fecha' ?></span>
                        </div>
                        <?php if ($n['propietario']): ?>
                            <div class="small muted" style="margin-top:.25rem"><?= e($n['propietario']) ?></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

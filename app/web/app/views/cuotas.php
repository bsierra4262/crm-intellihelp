<?php /** @var array $usuarios @var array $cargadas @var array $ganado @var string $periodo @var ?array $aviso */ ?>
<div class="page-head">
    <div>
        <h1>Objetivos de venta</h1>
        <p class="muted">Cuánto se espera cerrar cada mes, por persona</p>
    </div>
    <form class="head-actions" method="get" action="<?= e(url('cuotas')) ?>">
        <input type="month" name="periodo" value="<?= e($periodo) ?>" data-auto>
        <button class="btn btn-primary btn-sm">Ver</button>
    </form>
</div>

<?php if ($aviso): ?><div class="flash flash-<?= e($aviso[0]) ?>"><?= e($aviso[1]) ?></div><?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Período <?= e($periodo) ?></h2></div>
    <p class="muted small card-intro">
        Es el número contra el que se mide el pronóstico en
        <a href="<?= e(url('reportes')) ?>">Reportes</a>. Hasta ahora esa columna mostraba
        siempre un guión porque no había forma de cargarlo. Dejalo vacío si esa persona no
        tiene objetivo este mes: mejor sin dato que con un cero que se lee como
        «cumplió el 0 %».
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="periodo" value="<?= e($periodo) ?>">
        <div class="table-scroll">
            <table class="grid">
                <thead><tr><th>Vendedor</th><th class="num">Objetivo</th>
                           <th class="num">Ganado en el mes</th><th class="num">Cumplimiento</th></tr></thead>
                <tbody>
                <?php foreach ($usuarios as $u):
                    $uid = (int)$u['id'];
                    $obj = $cargadas[$uid] ?? 0.0;
                    $gan = $ganado[$uid] ?? 0.0;
                    $pct = $obj > 0 ? (int) round(100 * $gan / $obj) : null;
                ?>
                    <tr>
                        <td data-label="Vendedor"><strong><?= e($u['nombre']) ?></strong></td>
                        <td data-label="Objetivo" class="num">
                            <input type="number" name="objetivo[<?= $uid ?>]" class="w-md"
                                   value="<?= $obj > 0 ? e((string)$obj) : '' ?>"
                                   min="0" step="0.01" inputmode="decimal" placeholder="sin objetivo">
                        </td>
                        <td data-label="Ganado en el mes" class="num strong"><?= money($gan) ?></td>
                        <td data-label="Cumplimiento" class="num">
                            <?= $pct === null ? '—'
                                : '<span class="badge ' . ($pct >= 100 ? 'badge-ok' : ($pct >= 60 ? 'badge-pend' : 'badge-rej')) . '">' . $pct . ' %</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary"><?= icon('ok', 16) ?>Guardar objetivos</button>
        </div>
    </form>
</div>

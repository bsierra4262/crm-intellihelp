<?php
/** @var string $periodo @var array $pronostico @var array $etapas @var array $fuentes
 *  @var array $campanas @var array $actividad */
$maxEtapa = 0.0;
foreach ($etapas as $e) { $maxEtapa = max($maxEtapa, (float)$e['total']); }
$maxFuente = 0.0;
foreach ($fuentes as $f) { $maxFuente = max($maxFuente, (float)$f['total']); }
$maxAct = 0;
foreach ($actividad as $a) { $maxAct = max($maxAct, (int)$a['n']); }
$pct = fn($v, $max) => $max > 0 ? max(1, round(100 * $v / $max)) : 0;
?>
<div class="page-head">
    <div>
        <h1>Reportes</h1>
        <p class="muted">Embudo, pronóstico y rendimiento de las campañas</p>
    </div>
    <form class="head-actions" method="get" action="<?= e(url('reportes')) ?>">
        <input type="month" name="periodo" value="<?= e($periodo) ?>" data-auto>
        <button class="btn btn-primary btn-sm">Ver</button>
    </form>
</div>

<div class="card mb-4">
    <div class="card-head"><h2>Embudo por etapa</h2><span class="small muted">Todos los negocios abiertos e históricos</span></div>
    <?php foreach ($etapas as $e): ?>
        <div class="bar-row">
            <span class="bar-label"><?= e($e['nombre']) ?> <span class="muted">(<?= (int)$e['n'] ?>)</span></span>
            <span class="bar-track"><i class="bar-fill" style="width:<?= $pct((float)$e['total'], $maxEtapa) ?>%;background:<?= e($e['color']) ?>"></i></span>
            <span class="bar-val"><?= money((float)$e['total']) ?></span>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-head"><h2>Pronóstico de <?= e($periodo) ?></h2></div>
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Vendedor</th><th class="num">Objetivo</th><th class="num">Ganado</th>
                       <th class="num">Cumplimiento</th><th class="num prio-2">Abierto</th>
                       <th class="num prio-2">Ponderado</th><th class="num prio-3">Perdido</th></tr></thead>
            <tbody>
            <?php foreach ($pronostico as $p):
                $cumple = (float)$p['objetivo'] > 0 ? round(100 * (float)$p['ganado'] / (float)$p['objetivo']) : null;
            ?>
                <tr>
                    <td data-label="Vendedor"><strong><?= e($p['nombre']) ?></strong></td>
                    <td data-label="Objetivo" class="num"><?= (float)$p['objetivo'] > 0 ? money((float)$p['objetivo']) : '—' ?></td>
                    <td data-label="Ganado" class="num strong"><?= money((float)$p['ganado']) ?></td>
                    <td data-label="Cumplimiento" class="num">
                        <?= $cumple === null ? '—' : '<span class="badge ' . ($cumple >= 100 ? 'badge-ok' : ($cumple >= 60 ? 'badge-pend' : 'badge-rej')) . '">' . $cumple . ' %</span>' ?>
                    </td>
                    <td data-label="Abierto" class="num prio-2"><?= money((float)$p['abierto']) ?></td>
                    <td data-label="Ponderado" class="num prio-2"><?= money((float)$p['ponderado']) ?></td>
                    <td data-label="Perdido" class="num prio-3"><?= money((float)$p['perdido']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small card-intro mt-3 mb-0">
        El objetivo sale de la cuota del período. El «ponderado» es el importe de cada negocio
        multiplicado por la probabilidad de su etapa.
    </p>
</div>

<div class="rep-grid">
    <div class="card">
        <div class="card-head"><h2>De dónde vienen los negocios ganados</h2></div>
        <?php if (!$fuentes): ?>
            <p class="muted small">Todavía no hay negocios ganados.</p>
        <?php else: foreach ($fuentes as $f): ?>
            <div class="bar-row">
                <span class="bar-label"><?= e($f['fuente']) ?> <span class="muted">(<?= (int)$f['n'] ?>)</span></span>
                <span class="bar-track"><i class="bar-fill alt" style="width:<?= $pct((float)$f['total'], $maxFuente) ?>%"></i></span>
                <span class="bar-val"><?= money((float)$f['total']) ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Actividades completadas en <?= e($periodo) ?></h2></div>
        <?php if (!$actividad): ?>
            <p class="muted small">Sin datos.</p>
        <?php else: foreach ($actividad as $a): ?>
            <div class="bar-row">
                <span class="bar-label"><?= e($a['nombre']) ?></span>
                <span class="bar-track"><i class="bar-fill" style="width:<?= $pct((int)$a['n'], $maxAct) ?>%"></i></span>
                <span class="bar-val"><?= (int)$a['n'] ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Rendimiento de las campañas</h2></div>
    <?php if (!$campanas): ?>
        <p class="muted small">Todavía no se envió ninguna campaña.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="grid">
                <thead><tr><th>Campaña</th><th class="num">Enviados</th><th class="num">Respuestas</th>
                           <th class="num">% respuesta</th><th class="num prio-2">Rebotes</th>
                           <th class="num prio-2">% rebote</th><th class="num prio-3">Reuniones</th></tr></thead>
                <tbody>
                <?php foreach ($campanas as $c):
                    $env = max(1, (int)$c['enviados']);
                ?>
                    <tr>
                        <td data-label="Campaña"><strong><?= e($c['nombre']) ?></strong></td>
                        <td data-label="Enviados" class="num"><?= (int)$c['enviados'] ?></td>
                        <td data-label="Respuestas" class="num strong"><?= (int)$c['respondidos'] ?></td>
                        <td data-label="% respuesta" class="num"><?= round(100 * (int)$c['respondidos'] / $env, 1) ?> %</td>
                        <td data-label="Rebotes" class="num prio-2"><?= (int)$c['rebotados'] ?></td>
                        <td data-label="% rebote" class="num prio-2">
                            <?php $r = round(100 * (int)$c['rebotados'] / $env, 1); ?>
                            <span class="badge <?= $r > 5 ? 'badge-rej' : 'badge-ok' ?>"><?= $r ?> %</span>
                        </td>
                        <td data-label="Reuniones" class="num prio-3"><?= (int)$c['reuniones'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small card-intro mt-3 mb-0">
            Un porcentaje de rebote por encima del 5 % es señal de que la base necesita limpieza:
            los proveedores penalizan al remitente.
        </p>
    <?php endif; ?>
</div>

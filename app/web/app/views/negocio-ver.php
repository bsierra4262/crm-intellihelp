<?php
/** @var array $n @var array $etapas @var array $historial @var array $notas
 *  @var array $actividades @var array $linea */
?>
<div class="detail-head">
    <span class="avatar-fallback" style="background:<?= e($n['etapa_color']) ?>"><?= icon('negocios', 22) ?></span>
    <div style="flex:1;min-width:0">
        <h1><?= e($n['nombre']) ?></h1>
        <div class="sub">
            <?php if ($n['cuenta_id']): ?>
                <a href="<?= e(url('cuenta-ver?id=' . (int)$n['cuenta_id'])) ?>"><?= e($n['cuenta']) ?></a>
            <?php else: ?>Sin empresa<?php endif; ?>
            <?php if ($n['contacto_id']): ?>
                · <a href="<?= e(url('contacto-ver?id=' . (int)$n['contacto_id'])) ?>"><?= e($n['contacto']) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('actividad-form?negocio_id=' . (int)$n['id'])) ?>"><?= icon('tareas', 16) ?>Nueva actividad</a>
        <a class="btn btn-primary" href="<?= e(url('negocio-form?id=' . (int)$n['id'])) ?>"><?= icon('editar', 16) ?>Editar</a>
    </div>
</div>

<div class="cards">
    <div class="card stat">
        <span class="stat-label">Importe</span>
        <span class="stat-value"><?= money((float)$n['importe'], $n['moneda']) ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Ponderado (<?= (int)$n['probabilidad'] ?> %)</span>
        <span class="stat-value accent"><?= money((float)$n['ingreso_esperado'], $n['moneda']) ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Cierre estimado</span>
        <span class="stat-value" style="font-size:19px"><?= fecha_corta($n['fecha_cierre']) ?></span>
        <span class="stat-foot"><?= $n['fecha_cierre'] ? tiempo_relativo($n['fecha_cierre']) : '' ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Etapa</span>
        <span class="stat-value" style="font-size:19px;color:<?= e($n['etapa_color']) ?>"><?= e($n['etapa']) ?></span>
        <span class="stat-foot"><?= e($n['embudo']) ?></span>
    </div>
</div>

<div class="card" style="margin-bottom:1rem">
    <div class="card-head"><h2>Mover de etapa</h2></div>
    <form method="post" action="<?= e(url('negocio-etapa')) ?>" class="urow">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
        <select name="etapa_id" class="w-lg">
            <?php foreach ($etapas as $et): ?>
                <option value="<?= (int)$et['id'] ?>" <?= (int)$n['etapa_id'] === (int)$et['id'] ? 'selected' : '' ?>>
                    <?= e($et['nombre']) ?> (<?= (int)$et['probabilidad'] ?> %)
                </option>
            <?php endforeach; ?>
        </select>
        <input name="motivo_perdida" placeholder="Motivo (si se pierde)" maxlength="200" class="w-lg">
        <button class="btn btn-primary btn-sm"><?= icon('convertir', 15) ?>Mover</button>
    </form>
</div>

<div class="detail">
    <div style="display:flex;flex-direction:column;gap:1rem;min-width:0">
        <div class="card">
            <div class="card-head"><h2>Detalle</h2></div>
            <div class="kv">
                <div><div class="k">Propietario</div><div class="v"><?= e($n['propietario'] ?: '—') ?></div></div>
                <div><div class="k">Tipo</div><div class="v"><?= e($n['tipo'] ?: '—') ?></div></div>
                <div><div class="k">Fuente</div><div class="v"><?= e($n['fuente'] ?: '—') ?></div></div>
                <div><div class="k">Campaña de origen</div><div class="v">
                    <?php if ($n['campana_id']): ?>
                        <a href="<?= e(url('campana-ver?id=' . (int)$n['campana_id'])) ?>"><?= e($n['campana']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </div></div>
                <div><div class="k">Siguiente paso</div><div class="v"><?= e($n['siguiente_paso'] ?: '—') ?></div></div>
                <div><div class="k">Motivo de pérdida</div><div class="v"><?= e($n['motivo_perdida'] ?: '—') ?></div></div>
                <div><div class="k">Alta</div><div class="v"><?= fecha_hora($n['creado_en']) ?></div></div>
                <div><div class="k">Cerrado</div><div class="v"><?= $n['cerrado_en'] ? fecha_hora($n['cerrado_en']) : '—' ?></div></div>
            </div>
            <?php if (trim((string)$n['descripcion']) !== ''): ?>
                <p style="white-space:pre-wrap;margin:1rem 0 0;padding-top:1rem;border-top:1px solid var(--line-soft)"><?= e($n['descripcion']) ?></p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><h2>Recorrido por el embudo</h2></div>
            <?php if (!$historial): ?>
                <p class="muted small">Sin movimientos de etapa.</p>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach (array_reverse($historial) as $h):
                        $dias = $h['hasta']
                            ? round((strtotime((string)$h['hasta']) - strtotime((string)$h['desde'])) / 86400, 1)
                            : round((time() - strtotime((string)$h['desde'])) / 86400, 1);
                    ?>
                        <div class="tl-item">
                            <div class="tl-when"><?= fecha_hora($h['desde']) ?> · <?= e($h['usuario'] ?: 'sistema') ?></div>
                            <div class="tl-title"><span class="chip" style="background:<?= e($h['color']) ?>1f;color:<?= e($h['color']) ?>"><?= e($h['etapa']) ?></span></div>
                            <div class="tl-detail"><?= $h['hasta'] ? 'Estuvo ' . $dias . ' días' : 'Etapa actual · hace ' . $dias . ' días' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:1rem;min-width:0">
        <?php include APP . '/views/_notas.php'; ?>
        <?php include APP . '/views/_actividades_panel.php'; ?>
        <div class="card">
            <div class="card-head"><h2>Historial</h2></div>
            <?php if (!$linea): ?>
                <p class="muted small">Sin movimientos registrados.</p>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($linea as $x): ?>
                        <div class="tl-item <?= e($x['tipo']) ?>">
                            <div class="tl-when"><?= fecha_hora($x['cuando']) ?> · <?= e($x['actor'] ?: 'sistema') ?></div>
                            <div class="tl-title"><?= e($x['titulo']) ?></div>
                            <?php if ($x['detalle']): ?><div class="tl-detail"><?= e($x['detalle']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

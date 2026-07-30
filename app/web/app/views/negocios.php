<?php /** @var array $f @var array $data @var array $usuarios @var array $etapas */ ?>
<div class="page-head">
    <div>
        <h1>Negocios</h1>
        <p class="muted">
            <?= number_format($data['total'], 0, ',', '.') ?> negocios ·
            <?= money((float)($data['suma']['importe'] ?? 0)) ?> ·
            <?= money((float)($data['suma']['ponderado'] ?? 0)) ?> ponderado
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('embudo')) ?>"><?= icon('embudo', 16) ?>Ver como embudo</a>
        <a class="btn btn-primary" href="<?= e(url('negocio-form')) ?>"><?= icon('mas', 16) ?>Nuevo negocio</a>
    </div>
</div>

<form class="filters card" method="get" action="<?= e(url('negocios')) ?>">
    <div class="filter-grid">
        <label><span>Buscar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Negocio o empresa…"></label>
        <label><span>Estado</span>
            <select name="estado">
                <?php foreach (['abiertos' => 'Abiertos', 'ganados' => 'Ganados', 'perdidos' => 'Perdidos', 'todos' => 'Todos'] as $k => $et): ?>
                    <option value="<?= e($k) ?>" <?= $f['estado'] === $k ? 'selected' : '' ?>><?= e($et) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Etapa</span>
            <select name="etapa_id">
                <option value="">Todas</option>
                <?php foreach ($etapas as $et): ?>
                    <option value="<?= (int)$et['id'] ?>" <?= (string)$f['etapa_id'] === (string)$et['id'] ? 'selected' : '' ?>><?= e($et['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Propietario</span>
            <select name="propietario_id">
                <option value="">Todos</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (string)$f['propietario_id'] === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Cierre desde</span><input type="date" name="desde" value="<?= e($f['desde']) ?>"></label>
        <label><span>Cierre hasta</span><input type="date" name="hasta" value="<?= e($f['hasta']) ?>"></label>
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('filtro', 15) ?>Filtrar</button>
        <button type="button" class="btn btn-ghost btn-sm" data-limpiar>Limpiar</button>
    </div>
</form>

<div class="card table-card">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th>Negocio</th>
                    <th>Empresa</th>
                    <th>Etapa</th>
                    <th class="num">Importe</th>
                    <th class="num prio-2">Ponderado</th>
                    <th class="prio-3">Cierre</th>
                    <th class="prio-4">Propietario</th>
                    <th class="acts">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$data['rows']): ?>
                <tr><td colspan="8" class="empty">No hay negocios que coincidan con el filtro.</td></tr>
            <?php else: foreach ($data['rows'] as $n): ?>
                <tr>
                    <td data-label="Negocio">
                        <a href="<?= e(url('negocio-ver?id=' . (int)$n['id'])) ?>"><strong><?= e($n['nombre']) ?></strong></a>
                    </td>
                    <td data-label="Empresa">
                        <?php if ($n['cuenta_id']): ?>
                            <a href="<?= e(url('cuenta-ver?id=' . (int)$n['cuenta_id'])) ?>"><?= e(recortar($n['cuenta'], 30)) ?></a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td data-label="Etapa">
                        <span class="chip" style="background:<?= e($n['etapa_color']) ?>1f;color:<?= e($n['etapa_color']) ?>"><?= e($n['etapa']) ?></span>
                    </td>
                    <td data-label="Importe" class="num strong"><?= money((float)$n['importe'], $n['moneda']) ?></td>
                    <td data-label="Ponderado" class="num prio-2"><?= money((float)$n['ingreso_esperado'], $n['moneda']) ?></td>
                    <td data-label="Cierre" class="prio-3"><?= fecha_corta($n['fecha_cierre']) ?></td>
                    <td data-label="Propietario" class="prio-4"><?= e($n['propietario'] ?: '—') ?></td>
                    <td class="acts" data-label="Acciones">
                        <a class="act" href="<?= e(url('negocio-form?id=' . (int)$n['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                        <form class="inline-form" method="post" action="<?= e(url('registro-eliminar')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entidad" value="negocio">
                            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                            <input type="hidden" name="volver" value="negocios">
                            <button class="act danger" title="Enviar a la papelera" data-peligro
                                    data-confirmar-titulo="Enviar a la papelera"
                                    data-confirmar="El negocio «<?= e($n['nombre']) ?>» se va a la papelera."
                                    data-confirmar-ok="Enviar a la papelera"><?= icon('papelera', 16) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP . '/views/_pager.php'; ?>

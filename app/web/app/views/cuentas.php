<?php /** @var array $f @var array $data @var array $usuarios */ ?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Empresas</h1>
        <p class="muted"><?= number_format($data['total'], 0, ',', '.') ?> empresas en la base</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('cuentas' . query_con(['exportar' => 1]))) ?>"><?= icon('descargar', 16) ?>Exportar</a>
        <a class="btn btn-primary" href="<?= e(url('cuenta-form')) ?>"><?= icon('mas', 16) ?>Nueva empresa</a>
    </div>
</div>

<form class="filters card" method="get" action="<?= e(url('cuentas')) ?>">
    <div class="filter-grid">
        <label><span>Buscar</span>
            <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nombre, sitio web, CUIT…">
        </label>
        <label><span>Propietario</span>
            <select name="propietario_id">
                <option value="">Todos</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (string)$f['propietario_id'] === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Tipo</span>
            <select name="tipo">
                <option value="">Todos</option>
                <?php foreach (tipos_cuenta() as $t): ?>
                    <option value="<?= e($t) ?>" <?= $f['tipo'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Provincia</span>
            <select name="provincia">
                <option value="">Todas</option>
                <?php foreach (provincias_ar() as $pv): ?>
                    <option value="<?= e($pv) ?>" <?= $f['provincia'] === $pv ? 'selected' : '' ?>><?= e($pv) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('filtro', 15) ?>Filtrar</button>
        <button type="button" class="btn btn-ghost btn-sm" data-limpiar>Limpiar</button>
        <?php /* Las empresas a las que les falta CUIT, tipo o sector. Muchas
                 nacen de un nombre suelto —una importación, la ficha de un
                 contacto, alguien que reservó una reunión— y hay que poder
                 encontrarlas para completarlas. */ ?>
        <label class="switch push">
            <input type="checkbox" name="pendiente" value="1" <?= ($f['pendiente'] ?? '') === '1' ? 'checked' : '' ?>>
            <span class="track"></span><span class="small">Pendientes de aprobar</span>
        </label>
    </div>
</form>

<div class="card table-card" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th>Empresa</th>
                    <th class="num">Contactos</th>
                    <th class="prio-2">Tipo</th>
                    <th class="prio-3">Teléfono</th>
                    <th class="prio-3">Localidad</th>
                    <th class="prio-4">Sitio web</th>
                    <th class="prio-4">Propietario</th>
                    <th class="w-estado">Estado</th>
                    <th class="acts">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$data['rows']): ?>
                <tr><td colspan="8" class="empty">No hay empresas que coincidan con el filtro.</td></tr>
            <?php else: foreach ($data['rows'] as $c): ?>
                <tr>
                    <td data-label="Empresa">
                        <div class="cell-main">
                            <a href="<?= e(url('cuenta-ver?id=' . (int)$c['id'])) ?>"><strong><?= e($c['nombre']) ?></strong></a>
                            <?php if ($c['cuit']): ?><small><?= e(formatear_cuit($c['cuit'])) ?></small><?php endif; ?>
                            <?php if (!empty($c['pendiente_norm'])): ?>
                                <small class="pend">Falta <?= e(implode(', ', cuenta_falta($c))) ?></small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Contactos" class="num"><?= (int)$c['n_contactos'] ?></td>
                    <td data-label="Tipo" class="prio-2">
                        <?= $c['tipo'] ? '<span class="badge badge-info">' . e($c['tipo']) . '</span>' : '<span class="muted">—</span>' ?>
                    </td>
                    <td data-label="Teléfono" class="prio-3"><?= e(telefono_formato($c['telefono']) ?: '—') ?></td>
                    <td data-label="Localidad" class="prio-3"><?= e($c['localidad'] ?: $c['provincia'] ?: '—') ?></td>
                    <td data-label="Sitio web" class="prio-4">
                        <?php if ($c['sitio_web']): ?>
                            <a href="<?= e(preg_match('#^https?://#i', $c['sitio_web']) ? $c['sitio_web'] : 'https://' . $c['sitio_web']) ?>" target="_blank" rel="noopener"><?= e(recortar($c['sitio_web'], 26)) ?></a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td data-label="Propietario" class="prio-4"><?= e($c['propietario'] ?: '—') ?></td>
                    <?php /* Una columna propia, no sólo el texto debajo del nombre:
                             de un vistazo se ve cuáles están listas y cuáles no. */ ?>
                    <td data-label="Estado" class="w-estado">
                        <?php if (!empty($c['pendiente_norm'])): ?>
                            <span class="badge badge-pend" title="Falta <?= e(implode(', ', cuenta_falta($c))) ?>">Pendiente</span>
                        <?php else: ?>
                            <span class="badge badge-ok">Aprobado</span>
                        <?php endif; ?>
                    </td>
                    <td class="acts" data-label="Acciones">
                        <a class="act" href="<?= e(url('cuenta-form?id=' . (int)$c['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                        <form class="inline-form" method="post" action="<?= e(url('registro-eliminar')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entidad" value="cuenta">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="volver" value="cuentas">
                            <button class="act danger" title="Enviar a la papelera" data-peligro
                                    data-confirmar-titulo="Enviar a la papelera"
                                    data-confirmar="La empresa «<?= e($c['nombre']) ?>» se va a la papelera. Sus contactos siguen existiendo."
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

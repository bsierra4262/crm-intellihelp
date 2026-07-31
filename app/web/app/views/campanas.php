<?php /** @var array $f @var array $data @var bool $testing @var bool $smtp */ ?>
<div class="page-head">
    <div>
        <h1>Campañas</h1>
        <p class="muted"><?= number_format($data['total'], 0, ',', '.') ?> campañas de correo</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('plantillas')) ?>"><?= icon('plantillas', 16) ?>Plantillas</a>
        <a class="btn btn-ghost" href="<?= e(url('listas')) ?>"><?= icon('listas', 16) ?>Listas</a>
        <?php if (auth_is_admin()): ?>
            <a class="btn btn-ghost" href="<?= e(url('cuentas-correo')) ?>"><?= icon('campanas', 16) ?>Cuentas</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= e(url('campana-form')) ?>"><?= icon('mas', 16) ?>Nueva campaña</a>
    </div>
</div>

<?php if (!$smtp): ?>
    <div class="flash flash-warn">
        Todavía no está configurada la cuenta desde la que salen los correos.
        <?php if (auth_is_admin()): ?><a href="<?= e(url('ajustes')) ?>">Configurarla ahora</a>.<?php else: ?>Pedísela al administrador.<?php endif; ?>
    </div>
<?php elseif ($testing): ?>
    <div class="flash flash-info">
        <strong>Modo prueba.</strong> Los correos sólo salen a la lista de destinatarios internos
        cargada en Ajustes. Ningún cliente real recibe nada.
    </div>
<?php endif; ?>

<form class="filters card" method="get" action="<?= e(url('campanas')) ?>">
    <div class="filter-grid">
        <label><span>Segmento</span>
            <select name="categoria_id" data-auto>
                <option value="">Todos</option>
                <?php foreach (categorias_listar() as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= (string)($f['categoria_id'] ?? '') === (string)$cat['id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Buscar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nombre de la campaña…"></label>
        <label><span>Estado</span>
            <select name="estado">
                <option value="">Todos</option>
                <?php foreach (['borrador', 'programada', 'enviando', 'pausada', 'completada', 'cancelada'] as $x): ?>
                    <option value="<?= e($x) ?>" <?= $f['estado'] === $x ? 'selected' : '' ?>><?= e(ucfirst($x)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('filtro', 15) ?>Filtrar</button>
        <button type="button" class="btn btn-ghost btn-sm" data-limpiar>Limpiar</button>
    </div>
</form>

<div class="card table-card" data-vivo>
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th>Campaña</th>
                    <th>Estado</th>
                    <th>Avance</th>
                    <th class="num prio-2">Respuestas</th>
                    <th class="num prio-3">Rebotes</th>
                    <th class="num prio-3">Reuniones</th>
                    <th class="prio-4">Responsable</th>
                    <th class="acts">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$data['rows']): ?>
                <tr><td colspan="8" class="empty">Todavía no hay campañas. Se arma en tres pasos:
                    una <a href="<?= e(url('plantillas')) ?>">plantilla</a> con el diseño del correo,
                    una <a href="<?= e(url('listas')) ?>">lista</a> con los destinatarios y
                    la campaña que junta las dos. Después la pantalla de la campaña te va marcando qué falta.</td></tr>
            <?php else: foreach ($data['rows'] as $c):
                $pct = $c['total'] > 0 ? min(100, (int)round(100 * $c['enviados'] / $c['total'])) : 0;
            ?>
                <tr data-vivo-fila="<?= (int)$c['id'] ?>">
                    <td data-label="Campaña">
                        <div class="cell-main">
                            <a href="<?= e(url('campana-ver?id=' . (int)$c['id'])) ?>"><strong><?= e($c['nombre']) ?></strong></a>
                            <small>
                                <?php if (!empty($c['categoria'])): ?>
                                    <?php /* El segmento se pedía al armar la campaña y no se veía en
                                             ningún lado: no se podía comparar por rubro. */ ?>
                                    <span class="chip" style="background:<?= e($c['categoria_color'] ?: '#663399') ?>1f;color:<?= e($c['categoria_color'] ?: '#663399') ?>"><?= e($c['categoria']) ?></span>
                                <?php endif; ?>
                                <?= e($c['lista'] ?: 'sin lista') ?> · <?= e($c['plantilla'] ?: 'sin plantilla') ?>
                            </small>
                        </div>
                    </td>
                    <td data-label="Estado"><span data-vivo-badge><?= badge_campana($c['estado']) ?></span></td>
                    <td data-label="Avance" class="col-avance">
                        <div class="progreso"><i data-vivo-barra class="<?= $pct >= 100 ? 'ok' : '' ?>" style="width:<?= $pct ?>%"></i></div>
                        <span class="small muted" data-vivo-avance><?= (int)$c['enviados'] ?> / <?= (int)$c['total'] ?></span>
                    </td>
                    <td data-label="Respuestas" class="num prio-2 strong" data-vivo-col="respondidos"><?= (int)$c['respondidos'] ?></td>
                    <td data-label="Rebotes" class="num prio-3" data-vivo-col="rebotados"><?= (int)$c['rebotados'] ?></td>
                    <td data-label="Reuniones" class="num prio-3" data-vivo-col="reuniones"><?= (int)$c['reuniones'] ?></td>
                    <td data-label="Responsable" class="prio-4"><?= e($c['propietario'] ?: '—') ?></td>
                    <td class="acts" data-label="Acciones">
                        <form class="inline-form" method="post" action="<?= e(url('registro-eliminar')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entidad" value="campana">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="volver" value="campanas">
                            <button class="act danger" title="Enviar a la papelera" data-peligro
                                    data-confirmar-titulo="Enviar a la papelera"
                                    data-confirmar="&laquo;<?= e($c['nombre']) ?>&raquo; deja de estar a la vista. Lo que ya se envió no se toca. Se puede restaurar durante 60 días."
                                    data-confirmar-ok="Enviar a la papelera"><?= icon('papelera', 16) ?></button>
                        </form>

                        <a class="act" href="<?= e(url('campana-ver?id=' . (int)$c['id'])) ?>" title="Ver"><?= icon('reportes', 16) ?></a>
                        <a class="act" href="<?= e(url('campana-form?id=' . (int)$c['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP . '/views/_pager.php'; ?>

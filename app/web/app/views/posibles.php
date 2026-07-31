<?php /** @var array $f @var array $data @var array $usuarios */
$colorEstado = [
    'Sin contactar'          => 'badge-neutral',
    'Contactado'             => 'badge-info',
    'Intentamos contactar'   => 'badge-pend',
    'Contactar más adelante' => 'badge-cool',
    'Precalificado'          => 'badge-ok',
    'No calificado'          => 'badge-rej',
    'Basura'                 => 'badge-rej',
];
?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Posibles clientes</h1>
        <p class="muted"><?= number_format($data['total'], 0, ',', '.') ?> <?= $f['convertidos'] === '1' ? 'registros (incluye convertidos)' : 'sin convertir' ?></p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('posibles' . query_con(['exportar' => 1]))) ?>"><?= icon('descargar', 16) ?>Exportar</a>
        <a class="btn btn-primary" href="<?= e(url('posible-form')) ?>"><?= icon('mas', 16) ?>Nuevo posible cliente</a>
    </div>
</div>

<form class="filters card" method="get" action="<?= e(url('posibles')) ?>">
    <div class="filter-grid">
        <label><span>Buscar</span>
            <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nombre, empresa, correo…">
        </label>
        <label><span>Estado</span>
            <select name="estado">
                <option value="">Todos</option>
                <?php foreach (estados_posible_cliente() as $x): ?>
                    <option value="<?= e($x) ?>" <?= $f['estado'] === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Calificación</span>
            <select name="calificacion">
                <option value="">Todas</option>
                <?php foreach (calificaciones() as $x): ?>
                    <option value="<?= e($x) ?>" <?= $f['calificacion'] === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Fuente</span>
            <select name="fuente">
                <option value="">Todas</option>
                <?php foreach (fuentes() as $x): ?>
                    <option value="<?= e($x) ?>" <?= $f['fuente'] === $x ? 'selected' : '' ?>><?= e($x) ?></option>
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
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('filtro', 15) ?>Filtrar</button>
        <button type="button" class="btn btn-ghost btn-sm" data-limpiar>Limpiar</button>
        <label class="switch push">
            <input type="checkbox" name="convertidos" value="1" <?= $f['convertidos'] === '1' ? 'checked' : '' ?>>
            <span class="track"></span><span class="small">Mostrar también los convertidos</span>
        </label>
    </div>
</form>

<div class="card table-card" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th>Persona</th>
                    <th>Empresa</th>
                    <th>Estado</th>
                    <th class="prio-2">Correo</th>
                    <th class="prio-3">Teléfono</th>
                    <th class="prio-4">Fuente</th>
                    <th class="prio-4">Propietario</th>
                    <th class="acts">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$data['rows']): ?>
                <tr><td colspan="8" class="empty">No hay posibles clientes que coincidan con el filtro.</td></tr>
            <?php else: foreach ($data['rows'] as $p):
                $nombre = trim(($p['nombre'] ?? '') . ' ' . $p['apellido']);
            ?>
                <tr>
                    <td data-label="Persona">
                        <div class="uinfo">
                            <span class="avatar-fallback avatar-sm"><?= e(iniciales($nombre)) ?></span>
                            <div class="cell-main">
                                <a href="<?= e(url('posible-ver?id=' . (int)$p['id'])) ?>"><strong><?= e($nombre) ?></strong></a>
                                <?php if ($p['cargo']): ?><small><?= e(recortar($p['cargo'], 30)) ?></small><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td data-label="Empresa"><?= e(recortar($p['empresa'], 32)) ?></td>
                    <td data-label="Estado">
                        <span class="badge <?= e($colorEstado[$p['estado']] ?? 'badge-neutral') ?>"><?= e($p['estado']) ?></span>
                        <?php if ($p['convertido_en']): ?><span class="badge badge-ok">Convertido</span><?php endif; ?>
                    </td>
                    <td data-label="Correo" class="prio-2">
                        <?= $p['email'] ? '<a href="mailto:' . e($p['email']) . '">' . e(recortar($p['email'], 28)) . '</a>' : '<span class="muted">—</span>' ?>
                    </td>
                    <td data-label="Teléfono" class="prio-3"><?= e(telefono_formato($p['telefono'] ?: $p['movil'] ?: $p['conmutador']) ?: '—') ?></td>
                    <td data-label="Fuente" class="prio-4"><?= e($p['fuente'] ?: '—') ?></td>
                    <td data-label="Propietario" class="prio-4"><?= e($p['propietario'] ?: '—') ?></td>
                    <td class="acts" data-label="Acciones">
                        <?php if (!$p['convertido_en']): ?>
                            <a class="act" href="<?= e(url('posible-convertir?id=' . (int)$p['id'])) ?>" title="Convertir en cliente"><?= icon('convertir', 16) ?></a>
                        <?php endif; ?>
                        <a class="act" href="<?= e(url('posible-form?id=' . (int)$p['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                        <form class="inline-form" method="post" action="<?= e(url('registro-eliminar')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entidad" value="posible_cliente">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="volver" value="posibles">
                            <button class="act danger" title="Enviar a la papelera" data-peligro
                                    data-confirmar-titulo="Enviar a la papelera"
                                    data-confirmar="«<?= e($nombre) ?>» se va a la papelera."
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

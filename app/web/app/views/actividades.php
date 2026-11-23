<?php /** @var array $f @var array $data @var array $usuarios */
// ============================================================
//  Vista: listado de actividades.
//
//  La dibuja pages/actividades.php. Pestañas de vistas rápidas, filtros
//  (GET), tabla paginada y, por fila, completar o reabrir (POST a
//  `actividad-completar`), editar y mandar a la papelera (POST a
//  `registro-eliminar`).
//
//  Variables que recibe:
//    $f         array  filtros (q, tipo, estado, propietario_id, vista,
//                      vencidas, page)
//    $data      array  actividades_listar(): rows (con propietario, contacto
//                      y cuenta), total y paginación
//    $usuarios  array  usuarios activos, para el filtro de responsable
//
//  «Relacionado con» muestra el contacto o, si no hay, la empresa; una
//  actividad vinculada sólo a un negocio o a un posible cliente sale con «—».
//  El formulario de filtros conserva la vista actual: `vencidas=1` (el enlace
//  del contador) viaja como vista=vencidas.
//  El enlace de Meet sólo sale si es https://.
//  La papelera de cada fila sólo la ve el responsable o un administrador.
// ============================================================

// `vencidas=1` (el enlace del contador) equivale a la pestaña «Vencidas».
$vistaActual = ($f['vencidas'] ?? '') === '1' ? 'vencidas' : ($f['vista'] ?: 'pendientes');
$vistas = ['pendientes' => 'Pendientes', 'hoy' => 'Hoy', 'semana' => 'Esta semana',
           'vencidas' => 'Vencidas', 'completadas' => 'Completadas'];
?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Actividades</h1>
        <p class="muted"><?= number_format($data['total'], 0, ',', '.') ?> tareas, llamadas y reuniones</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= e(url('actividad-form')) ?>"><?= icon('mas', 16) ?>Nueva actividad</a>
    </div>
</div>

<div class="seg mb-4">
    <?php // Cada pestaña conserva los filtros y vuelve a la página 1; «Pendientes» es la vista sin parámetro. ?>
    <?php foreach ($vistas as $k => $etiqueta): ?>
        <a class="seg-item <?= $vistaActual === $k ? 'active' : '' ?>"
           href="<?= e(url('actividades' . query_con(['vista' => $k === 'pendientes' ? null : $k, 'vencidas' => null, 'page' => null]))) ?>"><?= e($etiqueta) ?></a>
    <?php endforeach; ?>
</div>

<form class="filters card" method="get" action="<?= e(url('actividades')) ?>">
    <input type="hidden" name="vista" value="<?= e($vistaActual === 'pendientes' ? '' : $vistaActual) ?>">
    <div class="filter-grid">
        <label><span>Buscar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Asunto o detalle…"></label>
        <label><span>Tipo</span>
            <select name="tipo">
                <option value="">Todos</option>
                <?php foreach (tipos_actividad() as $k => $et): ?>
                    <option value="<?= e($k) ?>" <?= $f['tipo'] === $k ? 'selected' : '' ?>><?= e($et) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Estado</span>
            <select name="estado">
                <option value="">Todos</option>
                <?php foreach (estados_actividad() as $x): ?>
                    <option value="<?= e($x) ?>" <?= $f['estado'] === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Responsable</span>
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
    </div>
</form>

<div class="card table-card" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th>Asunto</th>
                    <th>Tipo</th>
                    <th>Vencimiento</th>
                    <th class="prio-2">Relacionado con</th>
                    <th class="prio-3">Prioridad</th>
                    <th class="prio-4">Responsable</th>
                    <th class="acts">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$data['rows']): ?>
                <tr><td colspan="7" class="empty">No hay actividades en esta vista. Probá cambiando el filtro de arriba,
                    o <a href="<?= e(url('actividad-form')) ?>">creá una tarea, llamada o reunión</a>.</td></tr>
            <?php else: foreach ($data['rows'] as $a): [$txt, $cls] = actividad_vencimiento($a); ?>
                <tr>
                    <td data-label="Asunto">
                        <a href="<?= e(url('actividad-form?id=' . (int)$a['id'])) ?>"><strong><?= e($a['asunto']) ?></strong></a>
                        <?php // `meet_url` lo escribe la integración con Google Calendar; sólo se enlaza si es https://. ?>
                        <?php if ($a['meet_url'] && preg_match('#^https://#i', (string)$a['meet_url'])): ?>
                            <a href="<?= e($a['meet_url']) ?>" target="_blank" rel="noopener" class="chip"><?= icon('video', 12) ?>Meet</a>
                        <?php endif; ?>
                    </td>
                    <td data-label="Tipo"><?= e(tipos_actividad()[$a['tipo']] ?? $a['tipo']) ?></td>
                    <td data-label="Vencimiento"><span class="badge <?= e($cls) ?>"><?= e($txt) ?></span></td>
                    <td data-label="Relacionado con" class="prio-2">
                        <?php if ($a['contacto_id']): ?>
                            <a href="<?= e(url('contacto-ver?id=' . (int)$a['contacto_id'])) ?>"><?= e(recortar($a['contacto'], 26)) ?></a>
                        <?php elseif ($a['cuenta_id']): ?>
                            <a href="<?= e(url('cuenta-ver?id=' . (int)$a['cuenta_id'])) ?>"><?= e(recortar($a['cuenta'], 26)) ?></a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td data-label="Prioridad" class="prio-3"><?= e($a['prioridad']) ?></td>
                    <td data-label="Responsable" class="prio-4"><?= e($a['propietario'] ?: '—') ?></td>
                    <td class="acts" data-label="Acciones">
                        <?php if (empty($a['completada_en'])): ?>
                            <form class="inline-form" method="post" action="<?= e(url('actividad-completar')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="act" title="Marcar como completada"><?= icon('ok', 16) ?></button>
                            </form>
                        <?php else: ?>
                            <?php /* Reabrir: para una actividad marcada como
                                     completada por error. */ ?>
                            <form class="inline-form" method="post" action="<?= e(url('actividad-completar')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="reabrir" value="1">
                                <button class="act" title="Reabrir la actividad"><?= icon('refrescar', 16) ?></button>
                            </form>
                        <?php endif; ?>
                        <a class="act" href="<?= e(url('actividad-form?id=' . (int)$a['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                        <?php // Sólo el propietario o un administrador (puedo_mandar_papelera(), registros.php). ?>
                        <?php if (puedo_mandar_papelera('actividad', $a)): ?>
                        <form class="inline-form" method="post" action="<?= e(url('registro-eliminar')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entidad" value="actividad">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="volver" value="actividades">
                            <button class="act danger" title="Eliminar" data-peligro
                                    data-confirmar-titulo="Eliminar actividad"
                                    data-confirmar="«<?= e($a['asunto']) ?>» se va a la papelera."
                                    data-confirmar-ok="Eliminar"><?= icon('papelera', 16) ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP . '/views/_pager.php'; ?>

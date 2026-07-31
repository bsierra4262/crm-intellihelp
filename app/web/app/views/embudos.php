<?php /** @var array $lista @var array $etapas @var array $uso @var ?array $aviso */ ?>
<div class="page-head">
    <div>
        <h1>Embudos y etapas</h1>
        <p class="muted">Por dónde pasa un negocio desde el primer contacto hasta que se cierra</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('embudo')) ?>"><?= icon('embudo', 16) ?>Ver el embudo</a>
    </div>
</div>

<?php if ($aviso): ?><div class="flash flash-<?= e($aviso[0]) ?>"><?= e($aviso[1]) ?></div><?php endif; ?>

<?php if (!$lista): ?>
    <div class="flash flash-warn">
        Todavía no hay ningún embudo cargado, así que la pantalla del Embudo no puede mostrar nada.
        Creá el primero acá abajo: viene con cinco etapas armadas que después podés cambiar.
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-head"><h2>Nuevo embudo</h2></div>
    <p class="muted small card-intro">
        La mayoría de las empresas trabaja con uno solo. Tener más de uno sirve cuando se venden
        cosas muy distintas y el recorrido no se parece en nada.
    </p>
    <form method="post" class="urow">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="embudo_guardar">
        <input name="nombre" placeholder="Ventas, Licitaciones…" maxlength="80" required class="w-xl">
        <button class="btn btn-primary btn-sm"><?= icon('mas', 15) ?>Crear</button>
    </form>
</div>

<?php foreach ($lista as $emb): $id = (int)$emb['id']; ?>
    <div class="card mb-4">
        <div class="card-head">
            <h2>
                <?= e($emb['nombre']) ?>
                <?php if ($emb['predeterminado']): ?><span class="badge badge-info">Por defecto</span><?php endif; ?>
                <?php if (!$emb['activo']): ?><span class="badge badge-neutral">Inactivo</span><?php endif; ?>
            </h2>
            <?php if (!$emb['predeterminado']): ?>
                <form class="inline-form" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="embudo_predeterminado">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn btn-ghost btn-sm">Usar por defecto</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="table-scroll">
            <table class="grid">
                <thead><tr><th>Etapa</th><th>Probabilidad</th><th>Tipo</th><th>Color</th>
                           <th>Orden</th><th>Negocios</th><th class="acts">Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($etapas[$id] ?? [] as $et): $eid = (int)$et['id']; ?>
                    <tr>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="etapa_guardar">
                            <input type="hidden" name="id" value="<?= $eid ?>">
                            <td data-label="Etapa">
                                <input name="nombre" value="<?= e($et['nombre']) ?>" maxlength="80" required class="w-md">
                            </td>
                            <td data-label="Probabilidad">
                                <input type="number" name="probabilidad" value="<?= (int)$et['probabilidad'] ?>"
                                       min="0" max="100" inputmode="numeric" class="w-sm">
                            </td>
                            <td data-label="Tipo">
                                <select name="tipo" class="w-sm">
                                    <?php foreach (['abierta' => 'En curso', 'ganada' => 'Ganada', 'perdida' => 'Perdida'] as $k => $et2): ?>
                                        <option value="<?= e($k) ?>" <?= $et['tipo'] === $k ? 'selected' : '' ?>><?= e($et2) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td data-label="Color">
                                <input type="color" name="color" value="<?= e($et['color'] ?: '#663399') ?>">
                            </td>
                            <td data-label="Orden">
                                <input type="number" name="orden" value="<?= (int)$et['orden'] ?>" min="0" class="w-sm">
                            </td>
                            <td data-label="Negocios"><?= (int)($uso[$eid] ?? 0) ?></td>
                            <td class="acts" data-label="Acciones">
                                <label class="switch" title="Activa">
                                    <input type="checkbox" name="activo" value="1" <?= $et['activo'] ? 'checked' : '' ?>>
                                    <span class="track"></span>
                                </label>
                                <button class="act" title="Guardar"><?= icon('ok', 16) ?></button>
                            </td>
                        </form>
                        <td class="acts">
                            <form class="inline-form" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="etapa_borrar">
                                <input type="hidden" name="id" value="<?= $eid ?>">
                                <button class="act danger" title="Borrar la etapa" data-peligro
                                        data-confirmar-titulo="Borrar la etapa"
                                        data-confirmar="<?= ($uso[$eid] ?? 0) > 0
                                            ? 'Hay ' . (int)$uso[$eid] . ' negocio(s) en esta etapa: no se va a poder borrar hasta que los muevas.'
                                            : 'La etapa se borra definitivamente.' ?>"
                                        data-confirmar-ok="Borrar"><?= icon('papelera', 16) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="urow mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="etapa_guardar">
            <input type="hidden" name="embudo_id" value="<?= $id ?>">
            <input type="hidden" name="activo" value="1">
            <input name="nombre" placeholder="Nombre de la etapa nueva" maxlength="80" required class="w-lg">
            <input type="number" name="probabilidad" placeholder="%" min="0" max="100" class="w-sm" inputmode="numeric">
            <select name="tipo" class="w-sm">
                <option value="abierta">En curso</option>
                <option value="ganada">Ganada</option>
                <option value="perdida">Perdida</option>
            </select>
            <input type="color" name="color" value="#663399">
            <input type="number" name="orden" placeholder="Orden" min="0" class="w-sm">
            <button class="btn btn-primary btn-sm"><?= icon('mas', 15) ?>Agregar etapa</button>
        </form>
    </div>
<?php endforeach; ?>

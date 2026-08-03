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

        <?php /* El formulario de cada fila va FUERA de la tabla y los campos lo
                 referencian con `form="..."`. Un <form> adentro de un <tr> es HTML
                 invalido: el navegador lo saca de la tabla al interpretarlo, y
                 por eso esta pantalla se desarmaba y parecia sin estilos. */ ?>
        <?php foreach ($etapas[$id] ?? [] as $et): $eid = (int)$et['id']; ?>
            <form method="post" id="et<?= $eid ?>" hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="etapa_guardar">
                <input type="hidden" name="id" value="<?= $eid ?>">
            </form>
            <form method="post" id="etdel<?= $eid ?>" hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="etapa_borrar">
                <input type="hidden" name="id" value="<?= $eid ?>">
            </form>
        <?php endforeach; ?>

        <div class="table-scroll">
            <table class="grid tabla-edit">
                <thead><tr><th>Etapa</th><th>Probabilidad</th><th>Tipo</th><th>Color</th>
                           <th>Orden</th><th class="prio-2">Negocios</th><th class="acts">Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($etapas[$id] ?? [] as $et): $eid = (int)$et['id']; ?>
                    <tr>
                        <td data-label="Etapa">
                            <input form="et<?= $eid ?>" name="nombre" value="<?= e($et['nombre']) ?>"
                                   maxlength="80" required class="w-md" placeholder="Propuesta enviada">
                        </td>
                        <td data-label="Probabilidad">
                            <div class="con-sufijo">
                                <input form="et<?= $eid ?>" type="number" name="probabilidad"
                                       value="<?= (int)$et['probabilidad'] ?>" min="0" max="100"
                                       inputmode="numeric" data-solo="digitos" class="w-xs">
                                <span>%</span>
                            </div>
                        </td>
                        <td data-label="Tipo">
                            <select form="et<?= $eid ?>" name="tipo" class="w-sm">
                                <?php foreach (['abierta' => 'En curso', 'ganada' => 'Ganada', 'perdida' => 'Perdida'] as $k => $et2): ?>
                                    <option value="<?= e($k) ?>" <?= $et['tipo'] === $k ? 'selected' : '' ?>><?= e($et2) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td data-label="Color">
                            <input form="et<?= $eid ?>" type="color" name="color" value="<?= e($et['color'] ?: '#663399') ?>"
                                   title="El color con el que se ve esta etapa en el embudo">
                        </td>
                        <td data-label="Orden">
                            <input form="et<?= $eid ?>" type="number" name="orden" value="<?= (int)$et['orden'] ?>"
                                   min="0" data-solo="digitos" class="w-xs">
                        </td>
                        <td data-label="Negocios" class="prio-2"><?= (int)($uso[$eid] ?? 0) ?></td>
                        <td class="acts" data-label="Acciones">
                            <label class="switch" title="Etapa activa: si la apagas deja de ofrecerse en los negocios nuevos">
                                <input form="et<?= $eid ?>" type="checkbox" name="activo" value="1" <?= $et['activo'] ? 'checked' : '' ?>>
                                <span class="track"></span>
                            </label>
                            <button form="et<?= $eid ?>" class="act" title="Guardar los cambios de esta etapa"><?= icon('ok', 16) ?></button>
                            <button form="etdel<?= $eid ?>" class="act danger" title="Borrar la etapa" data-peligro
                                    data-confirmar-titulo="Borrar la etapa"
                                    data-confirmar="<?= ($uso[$eid] ?? 0) > 0
                                        ? 'Hay ' . (int)$uso[$eid] . ' negocio(s) en esta etapa: no se va a poder borrar hasta que los muevas.'
                                        : 'La etapa se borra definitivamente.' ?>"
                                    data-confirmar-ok="Borrar"><?= icon('papelera', 16) ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="alta-inline mt-4">
            <h3 class="sub">Agregar una etapa</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="etapa_guardar">
                <input type="hidden" name="embudo_id" value="<?= $id ?>">
                <input type="hidden" name="activo" value="1">
                <div class="filter-grid">
                    <label><span>Nombre</span>
                        <input name="nombre" placeholder="Propuesta enviada" maxlength="80" required></label>
                    <label><span>Probabilidad</span>
                        <input type="number" name="probabilidad" placeholder="40" min="0" max="100"
                               inputmode="numeric" data-solo="digitos"></label>
                    <label><span>Tipo</span>
                        <select name="tipo">
                            <option value="abierta">En curso</option>
                            <option value="ganada">Ganada</option>
                            <option value="perdida">Perdida</option>
                        </select></label>
                    <label><span>Color</span>
                        <input type="color" name="color" value="#663399"></label>
                    <label><span>Orden</span>
                        <input type="number" name="orden" placeholder="3" min="0" data-solo="digitos"></label>
                </div>
                <div class="filter-actions">
                    <button class="btn btn-primary btn-sm"><?= icon('mas', 15) ?>Agregar etapa</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php /** @var array $f @var array $data @var array $usuarios */ ?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Contactos</h1>
        <p class="muted"><?= number_format($data['total'], 0, ',', '.') ?> personas en la base</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('contactos' . query_con(['exportar' => 1]))) ?>"><?= icon('descargar', 16) ?>Exportar</a>
        <a class="btn btn-primary" href="<?= e(url('contacto-form')) ?>"><?= icon('mas', 16) ?>Nuevo contacto</a>
    </div>
</div>

<form class="filters card" method="get" action="<?= e(url('contactos')) ?>">
    <div class="filter-grid">
        <label><span>Buscar</span>
            <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nombre, correo, empresa, teléfono…">
        </label>
        <label><span>Propietario</span>
            <select name="propietario_id">
                <option value="">Todos</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (string)$f['propietario_id'] === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
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
        <label><span>Fuente</span>
            <select name="fuente">
                <option value="">Todas</option>
                <?php foreach (fuentes() as $fu): ?>
                    <option value="<?= e($fu) ?>" <?= $f['fuente'] === $fu ? 'selected' : '' ?>><?= e($fu) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Estado del correo</span>
            <select name="con_email">
                <option value="">Cualquiera</option>
                <option value="1" <?= $f['con_email'] === '1' ? 'selected' : '' ?>>Con correo cargado</option>
            </select>
        </label>
        <label><span>Ordenar por</span>
            <select name="orden">
                <option value="">Apellido</option>
                <option value="empresa"  <?= $f['orden'] === 'empresa'  ? 'selected' : '' ?>>Empresa</option>
                <option value="reciente" <?= $f['orden'] === 'reciente' ? 'selected' : '' ?>>Alta más reciente</option>
            </select>
        </label>
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('filtro', 15) ?>Filtrar</button>
        <button type="button" class="btn btn-ghost btn-sm" data-limpiar>Limpiar</button>
        <?php /* «Dado de baja» y «rebotado» son estados que sólo existen si hay
                 campañas: la baja la pide el destinatario desde un correo y el
                 rebote lo devuelve el servidor al enviarlo. Sin campañas, los
                 dos filtros devuelven siempre cero y sólo ocupan lugar. */ ?>
        <?php if (ruta_habilitada('campanas')): ?>
            <label class="switch push">
                <input type="checkbox" name="no_email" value="1" <?= $f['no_email'] === '1' ? 'checked' : '' ?>>
                <span class="track"></span><span class="small">Sólo dados de baja</span>
            </label>
            <label class="switch">
                <input type="checkbox" name="rebotado" value="1" <?= $f['rebotado'] === '1' ? 'checked' : '' ?>>
                <span class="track"></span><span class="small">Sólo rebotados</span>
            </label>
        <?php endif; ?>
        <?php /* Las fichas a las que les falta algo de lo que pedimos siempre.
                 Es el filtro que permite ir completando la base de a poco, en
                 vez de descubrir el agujero el día que hace falta el dato. */ ?>
        <label class="switch">
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
                    <th>Contacto</th>
                    <th>Empresa</th>
                    <th class="prio-2">Cargo</th>
                    <th>Correo</th>
                    <th class="prio-3">Teléfono</th>
                    <th class="prio-4">Localidad</th>
                    <th class="prio-4">Propietario</th>
                    <th class="w-estado">Estado</th>
                    <th class="acts">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$data['rows']): ?>
                <tr><td colspan="8" class="empty">No hay contactos que coincidan con el filtro.</td></tr>
            <?php else: foreach ($data['rows'] as $c): ?>
                <tr>
                    <td data-label="Contacto">
                        <div class="uinfo">
                            <span class="avatar-fallback avatar-sm"><?= e(iniciales($c['nombre_completo'])) ?></span>
                            <div class="cell-main">
                                <a href="<?= e(url('contacto-ver?id=' . (int)$c['id'])) ?>"><strong><?= e($c['nombre_completo']) ?></strong></a>
                                <?php if ($c['no_email']): ?><small>Dado de baja de las campañas</small>
                                <?php elseif ($c['email_rebotado']): ?><small>El correo rebotó</small><?php endif; ?>
                                <?php if (!empty($c['pendiente_norm'])): ?>
                                    <small class="pend">Falta <?= e(implode(', ', contacto_falta($c))) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td data-label="Empresa">
                        <?php if ($c['cuenta_id']): ?>
                            <a href="<?= e(url('cuenta-ver?id=' . (int)$c['cuenta_id'])) ?>"><?= e(recortar($c['cuenta'], 34)) ?></a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td data-label="Cargo" class="prio-2"><?= e(recortar($c['cargo'], 28)) ?: '<span class="muted">—</span>' ?></td>
                    <td data-label="Correo">
                        <?php if ($c['email']): ?><a href="mailto:<?= e($c['email']) ?>"><?= e(recortar($c['email'], 30)) ?></a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td data-label="Teléfono" class="prio-3"><?= e(telefono_formato($c['telefono'] ?: $c['movil'] ?: $c['conmutador']) ?: '—') ?></td>
                    <td data-label="Localidad" class="prio-4"><?= e($c['localidad'] ?: $c['provincia'] ?: '—') ?></td>
                    <td data-label="Propietario" class="prio-4"><?= e($c['propietario'] ?: '—') ?></td>
                    <?php /* Una columna propia, no sólo el texto debajo del nombre:
                             de un vistazo se ve cuáles están listas y cuáles no,
                             y se puede ordenar la cabeza en torno a eso. */ ?>
                    <td data-label="Estado" class="w-estado">
                        <?php if (!empty($c['pendiente_norm'])): ?>
                            <span class="badge badge-pend" title="Falta <?= e(implode(', ', contacto_falta($c))) ?>">Pendiente</span>
                        <?php else: ?>
                            <span class="badge badge-ok">Aprobado</span>
                        <?php endif; ?>
                    </td>
                    <td class="acts" data-label="Acciones">
                        <a class="act" href="<?= e(url('contacto-form?id=' . (int)$c['id'])) ?>" title="Editar"><?= icon('editar', 16) ?></a>
                        <form class="inline-form" method="post" action="<?= e(url('registro-eliminar')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entidad" value="contacto">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="volver" value="contactos">
                            <button class="act danger" title="Enviar a la papelera" data-peligro
                                    data-confirmar-titulo="Enviar a la papelera"
                                    data-confirmar="El contacto «<?= e($c['nombre_completo']) ?>» se va a la papelera. Un administrador puede restaurarlo."
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

<?php /** @var array $l @var array $data @var array $f @var array $usuarios */ ?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1><?= e($l['nombre']) ?></h1>
        <p class="muted"><?= number_format($data['total'], 0, ',', '.') ?> destinatarios<?= $l['descripcion'] ? ' · ' . e($l['descripcion']) : '' ?></p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('listas')) ?>">Volver</a>
        <a class="btn btn-primary" href="<?= e(url('lista-form?id=' . (int)$l['id'])) ?>"><?= icon('editar', 16) ?>Editar</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-head"><h2>Cargar contactos desde el CRM</h2></div>
    <p class="muted small card-intro">
        Se agregan los contactos que cumplan el filtro y tengan correo cargado.
        Los que ya están en la lista, los dados de baja y los que rebotaron se saltean solos.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="cargar">
        <div class="filter-grid">
            <label><span>Texto</span><input name="fq" placeholder="Empresa, cargo, correo…"></label>
            <label><span>Propietario</span>
                <select name="fpropietario_id">
                    <option value="">Todos</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Provincia</span>
                <select name="fprovincia">
                    <option value="">Todas</option>
                    <?php foreach (provincias_ar() as $pv): ?>
                        <option value="<?= e($pv) ?>"><?= e($pv) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Fuente</span>
                <select name="ffuente">
                    <option value="">Todas</option>
                    <?php foreach (fuentes() as $fu): ?>
                        <option value="<?= e($fu) ?>"><?= e($fu) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="filter-actions">
            <button class="btn btn-accent btn-sm" data-confirmar-titulo="Cargar contactos"
                    data-confirmar="Se van a agregar a esta lista todos los contactos que cumplan el filtro y tengan correo."
                    data-confirmar-ok="Cargar"><?= icon('importar', 15) ?>Cargar contactos</button>
        </div>
    </form>
</div>

<div class="card mb-4">
    <div class="card-head"><h2>Agregar correos a mano</h2></div>
    <p class="muted small card-intro">
        Para cuando querés mandarle a unas pocas direcciones puntuales. Pegalas separadas por
        coma, espacio o una por línea. Si alguna ya existe como contacto del CRM, se enlaza sola.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="sueltos">
        <label class="fld">
            <textarea name="correos" rows="3" required
                      placeholder="juan@empresa.com, maria@otra.com&#10;pedro@tercera.com"></textarea>
        </label>
        <div class="mt-3">
            <button class="btn btn-primary btn-sm"><?= icon('mas', 15) ?>Agregar a la lista</button>
        </div>
    </form>
</div>

<form class="filters card" method="get" action="<?= e(url('lista-ver')) ?>">
    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
    <div class="filter-grid">
        <label><span>Buscar en la lista</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Correo, nombre, empresa…"></label>
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('filtro', 15) ?>Filtrar</button>
        <button type="button" class="btn btn-ghost btn-sm" data-limpiar>Limpiar</button>
    </div>
</form>

<div class="card table-card" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Destinatario</th><th>Correo</th><th class="prio-2">Empresa</th><th>Estado</th><th class="acts">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$data['rows']): ?>
                <tr><td colspan="5" class="empty">La lista está vacía. Cargala con el bloque de arriba.</td></tr>
            <?php else: foreach ($data['rows'] as $m): ?>
                <tr>
                    <td data-label="Destinatario">
                        <?php if ($m['contacto_id']): ?>
                            <a href="<?= e(url('contacto-ver?id=' . (int)$m['contacto_id'])) ?>"><strong><?= e($m['nombre'] ?: $m['email']) ?></strong></a>
                        <?php else: ?><strong><?= e($m['nombre'] ?: $m['email']) ?></strong><?php endif; ?>
                    </td>
                    <td data-label="Correo"><?= e($m['email']) ?></td>
                    <td data-label="Empresa" class="prio-2"><?= e(recortar($m['empresa'], 30) ?: '—') ?></td>
                    <td data-label="Estado">
                        <?php
                        // El estado anticipa lo que va a pasar al mandar la
                        // campaña: considera bajas, rebotes y «no incluir».
                        if ($m['dado_de_baja']) {
                            echo '<span class="badge badge-rej" title="Pidió no recibir más correos">Dado de baja</span>';
                        } elseif (!empty($m['email_rebotado'])) {
                            echo '<span class="badge badge-rej" title="Su correo rebotó: no se le va a escribir">Rebotado</span>';
                        } elseif (!empty($m['no_email'])) {
                            echo '<span class="badge badge-warn" title="La ficha está marcada como «no incluir en campañas»">No incluir</span>';
                        } else {
                            echo '<span class="badge badge-ok">Habilitado</span>';
                        }
                        ?>
                    </td>
                    <td class="acts" data-label="Acciones">
                        <form class="inline-form" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="quitar">
                            <input type="hidden" name="miembro_id" value="<?= (int)$m['id'] ?>">
                            <button class="act danger" title="Quitar de la lista" data-peligro
                                    data-confirmar-titulo="Quitar de la lista"
                                    data-confirmar="Se quita a <?= e($m['email']) ?> de esta lista. El contacto no se borra del CRM."
                                    data-confirmar-ok="Quitar"><?= icon('x', 16) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP . '/views/_pager.php'; ?>

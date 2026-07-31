<?php /** @var array $rows @var ?string $nuevaClave */ ?>
<div class="page-head">
    <div>
        <h1>API</h1>
        <p class="muted">Claves para que otros sistemas (ITHOS, Jarvis) consulten el CRM</p>
    </div>
</div>

<?php if ($nuevaClave): ?>
    <div class="flash flash-success">
        <strong>Copiala ahora:</strong> es la única vez que se muestra completa.<br>
        <code class="mono clave"><?= e($nuevaClave) ?></code>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-head"><h2>Cómo se usa</h2></div>
    <p class="muted small card-intro">
        La clave sola no alcanza: hacen falta la dirección y el encabezado. Antes estaban
        únicamente en el código, así que se generaba una clave y no había forma de saber
        adónde mandarla.
    </p>
    <div class="kv">
        <?php
        dato('Dirección', url_abs('api/estado'));
        dato('Encabezado', 'X-API-Key: <tu-clave>');
        dato('Alternativa', 'Authorization: Bearer <tu-clave>');
        dato('Método', 'GET');
        ?>
    </div>
    <p class="muted small mt-3">
        Ejemplo:
        <code class="mono">curl -H "X-API-Key: TU_CLAVE" <?= e(url_abs('api/estado')) ?></code>
    </p>
</div>

<div class="card mb-4">
    <div class="card-head"><h2>Nueva clave</h2></div>
    <form method="post" class="urow">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear">
        <input name="nombre" placeholder="Para qué sistema es" maxlength="120" required class="w-xl">
        <?php /* Por ahora la API es sólo de lectura y el endpoint no distingue
                 permisos: ofrecer «Acceso total» prometía algo que no cambiaba
                 nada. Cuando haya escritura, vuelve el selector. */ ?>
        <input type="hidden" name="scope" value="lectura">
        <span class="chip">Sólo lectura</span>
        <button class="btn btn-primary btn-sm"><?= icon('clave', 15) ?>Generar</button>
    </form>
</div>

<div class="card table-card">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Clave</th><th>Permiso</th><th>Estado</th><th class="num prio-2">Usos</th>
                       <th class="prio-3">Último uso</th><th class="prio-4">Creada por</th><th class="acts">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="empty">Todavía no hay claves. Se generan para que otro sistema
                    pueda leer los datos del CRM sin usar tu usuario. Si no vas a conectar nada, no hace falta ninguna.</td></tr>
            <?php else: foreach ($rows as $t): ?>
                <tr>
                    <td data-label="Clave">
                        <div class="cell-main">
                            <strong><?= e($t['nombre']) ?></strong>
                            <small class="mono"><?= e($t['prefijo']) ?>…</small>
                        </div>
                    </td>
                    <td data-label="Permiso">
                        <span class="badge <?= $t['scope'] === 'root' ? 'badge-rej' : 'badge-info' ?>">
                            <?= $t['scope'] === 'root' ? 'Acceso total' : 'Sólo lectura' ?>
                        </span>
                    </td>
                    <td data-label="Estado">
                        <span class="badge <?= $t['activo'] ? 'badge-ok' : 'badge-neutral' ?>"><?= $t['activo'] ? 'Activa' : 'Dada de baja' ?></span>
                    </td>
                    <td data-label="Usos" class="num prio-2"><?= (int)$t['usos'] ?></td>
                    <td data-label="Último uso" class="prio-3"><?= $t['ultimo_uso'] ? fecha_hora($t['ultimo_uso']) : 'nunca' ?></td>
                    <td data-label="Creada por" class="prio-4"><?= e($t['creador'] ?: '—') ?></td>
                    <td class="acts" data-label="Acciones">
                        <?php if ($t['activo']): ?>
                            <form class="inline-form" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="baja">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="act danger" title="Dar de baja" data-peligro
                                        data-confirmar-titulo="Dar de baja la clave"
                                        data-confirmar="El sistema que la use va a dejar de tener acceso al instante."
                                        data-confirmar-ok="Dar de baja"><?= icon('x', 16) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

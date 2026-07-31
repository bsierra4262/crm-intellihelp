<?php /** @var string $enCurso @var array $reloj @var array $f @var array $data @var array $resumen @var array $conteo @var string $ultima */
$tipos = ['' => 'Todo', 'accion' => 'Acciones', 'nav' => 'Navegación', 'auth' => 'Accesos',
          'error' => 'Errores', 'sistema' => 'Sistema'];
?>
<div class="page-head">
    <div>
        <h1>CRON</h1>
        <p class="muted">Todo lo que pasa en el sistema queda registrado acá</p>
    </div>
    <div class="head-actions">
        <div class="seg">
            <a class="seg-item <?= $f['canal'] === 'app' ? 'active' : '' ?>" href="<?= e(url('cron?canal=app')) ?>">Aplicación</a>
            <a class="seg-item <?= $f['canal'] === 'api' ? 'active' : '' ?>" href="<?= e(url('cron?canal=api')) ?>">API</a>
        </div>
    </div>
</div>

<div class="cards">
    <div class="card stat">
        <span class="stat-label">Eventos registrados</span>
        <span class="stat-value"><?= number_format($resumen['total'], 0, ',', '.') ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Hoy</span>
        <span class="stat-value"><?= number_format($resumen['hoy'], 0, ',', '.') ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Errores</span>
        <span class="stat-value <?= $resumen['errores'] ? 'danger' : '' ?>"><?= number_format($resumen['errores'], 0, ',', '.') ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">Última tarea automática</span>
        <span class="stat-value sm"><?= $ultima ? fecha_hora($ultima) : 'nunca' ?></span>
        <span class="stat-foot">
            <?php if ($enCurso): ?>
                <?php /* Una corrida que manda correos dura varios minutos. Sin esto
                         la pantalla mostraba la hora de la corrida anterior justo
                         cuando el cron estaba trabajando. */ ?>
                <strong class="accent">Corriendo ahora</strong>, desde las <?= e(substr($enCurso, 11, 5)) ?> h
            <?php else: ?>
                <?= $ultima ? tiempo_relativo($ultima) : 'Falta agendarla en el hosting' ?>
            <?php endif; ?>
        </span>
    </div>
    <div class="card stat">
        <span class="stat-label">Hora del sistema</span>
        <span class="stat-value sm <?= $reloj['ok'] ? '' : 'danger' ?>"><?= e(substr($reloj['php'], 11, 5)) ?> h</span>
        <span class="stat-foot">
            <?= e($reloj['zona']) ?><br>
            <?php if ($reloj['ok']): ?>
                La base marca la misma hora: las reuniones y los envíos se agendan bien.
            <?php else: ?>
                <strong>La base va <?= (int)$reloj['desfase'] ?> s desfasada</strong>
                (<?= e($reloj['base']) ?>). Los recordatorios pueden salir a destiempo.
            <?php endif; ?>
        </span>
    </div>
</div>

<div class="seg mb-4">
    <?php foreach ($tipos as $k => $etiqueta): ?>
        <a class="seg-item <?= (string)$f['tipo'] === (string)$k ? 'active' : '' ?>"
           href="<?= e(url('cron' . query_con(['tipo' => $k ?: null, 'page' => null]))) ?>">
            <?= e($etiqueta) ?>
            <?php if ($k !== '' && !empty($conteo[$k])): ?><span class="badge badge-neutral"><?= (int)$conteo[$k] ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<form class="filters card" method="get" action="<?= e(url('cron')) ?>">
    <input type="hidden" name="canal" value="<?= e($f['canal']) ?>">
    <input type="hidden" name="tipo" value="<?= e($f['tipo']) ?>">
    <div class="filter-grid">
        <label><span>Buscar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Usuario, acción, detalle, IP…"></label>
        <label><span>Desde</span><input type="date" name="desde" value="<?= e($f['desde']) ?>"></label>
        <label><span>Hasta</span><input type="date" name="hasta" value="<?= e($f['hasta']) ?>"></label>
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('filtro', 15) ?>Filtrar</button>
        <button type="button" class="btn btn-ghost btn-sm" data-limpiar>Limpiar</button>
    </div>
</form>

<?php if (!empty($data['ventana_default'])): ?>
    <p class="muted small card-intro">
        <?= icon('reloj', 13) ?> Mostrando los últimos <?= (int)$data['ventana_meses'] ?> meses.
        El histórico completo no se borra nunca: elegí una fecha «Desde» anterior para verlo.
    </p>
<?php endif; ?>

<div class="card table-card">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th>Detalle</th><th class="prio-3">Tipo</th><th class="prio-4">IP</th></tr>
            </thead>
            <tbody>
            <?php if (!$data['rows']): ?>
                <tr><td colspan="6" class="empty">No hay eventos que coincidan con el filtro.</td></tr>
            <?php else: foreach ($data['rows'] as $r): ?>
                <tr>
                    <td data-label="Cuándo"><span class="mono"><?= fecha_hora($r['creado_en']) ?></span></td>
                    <td data-label="Quién">
                        <div class="uinfo">
                            <?php if ($r['usuario_avatar']): ?>
                                <img class="avatar avatar-sm" src="<?= e($r['usuario_avatar']) ?>" alt="" referrerpolicy="no-referrer">
                            <?php else: ?>
                                <span class="avatar-fallback avatar-sm"><?= e(iniciales((string)($r['actor'] ?: 'sistema'))) ?></span>
                            <?php endif; ?>
                            <span><?= e($r['actor'] ?: 'sistema') ?></span>
                        </div>
                    </td>
                    <td data-label="Acción"><span class="mono"><?= e($r['accion']) ?></span></td>
                    <td data-label="Detalle" class="wrap"><?= e($r['detalle'] ?: '—') ?></td>
                    <td data-label="Tipo" class="prio-3">
                        <span class="badge <?= $r['tipo'] === 'error' ? 'badge-rej' : ($r['tipo'] === 'auth' ? 'badge-info' : 'badge-neutral') ?>"><?= e($r['tipo']) ?></span>
                    </td>
                    <td data-label="IP" class="prio-4"><span class="mono"><?= e($r['ip'] ?: '—') ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP . '/views/_pager.php'; ?>

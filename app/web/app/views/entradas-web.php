<?php
/** @var array $f @var array $data @var array $canales @var array $opCanal @var array $opPagina
 *  @var array $usuarios @var int $dueno @var int $duenoAuto @var bool $esAdmin */
$tipos   = ['consulta' => 'Consulta', 'reserva' => 'Reunión'];
$estados = [
    'ok'         => ['badge-ok',      'Entró'],
    'procesando' => ['badge-info',    'En proceso'],
    'error'      => ['badge-rej',     'Con error'],
    'descartada' => ['badge-neutral', 'Repetida'],
];
$reunion = [
    'confirmada'   => 'Confirmada',
    'reprogramada' => 'Cambió de horario',
    'cancelada'    => 'Cancelada',
];
$nombreDe = [];
foreach ($usuarios as $u) { $nombreDe[(int)$u['id']] = $u['nombre']; }
?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Entradas desde la web</h1>
        <p class="muted"><?= number_format($data['total'], 0, ',', '.') ?> consultas y reuniones que llegaron de intellihelp.tech</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('entradas-web' . query_con(['exportar' => 1]))) ?>"><?= icon('descargar', 16) ?>Exportar</a>
    </div>
</div>

<?php /* Lo que la consultora mira cada mes, canal por canal. Se calcula con los
         mismos filtros de abajo salvo el canal, que es lo que se compara. Una
         reunión que cambió de horario cuenta una sola vez, por cómo terminó. */ ?>
<div class="cards" data-vivo="numeros">
    <?php if (!$canales): ?>
        <div class="card stat">
            <span class="stat-label">Por canal</span>
            <span class="stat-value sm">Sin entradas todavía</span>
            <span class="stat-foot">Aparecen solas cuando alguien deja una consulta o reserva desde la web.</span>
        </div>
    <?php else: foreach ($canales as $c): ?>
        <a class="card stat stat-link" href="<?= e(url('entradas-web' . query_con(['canal' => $c['canal'] === 'Sin dato' ? '-' : $c['canal'], 'page' => null]))) ?>">
            <span class="stat-label">Llegó por <?= e($c['canal']) ?></span>
            <span class="stat-value"><?= (int)$c['agendadas'] ?> <span class="muted"><?= (int)$c['agendadas'] === 1 ? 'reunión' : 'reuniones' ?></span></span>
            <span class="stat-foot">
                <?= (int)$c['realizadas'] ?> <?= (int)$c['realizadas'] === 1 ? 'realizada' : 'realizadas' ?> ·
                <?= (int)$c['canceladas'] ?> <?= (int)$c['canceladas'] === 1 ? 'cancelada' : 'canceladas' ?> ·
                <?= (int)$c['consultas'] ?> <?= (int)$c['consultas'] === 1 ? 'consulta' : 'consultas' ?> sin reunión
            </span>
        </a>
    <?php endforeach; endif; ?>
</div>

<?php if ($esAdmin): ?>
<form class="card mb-4" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="dueno">
    <div class="card-head"><h2>Quién atiende las consultas</h2></div>
    <p class="muted small card-intro">
        Cada consulta que llega sin reunión deja una tarea «Responder consulta web» con vencimiento el
        próximo día hábil al mediodía, y una alerta. Si no elegís a nadie, va a quien tiene la reunión de
        diagnóstico en la agenda<?= $duenoAuto && isset($nombreDe[$duenoAuto]) ? ' (hoy, ' . e($nombreDe[$duenoAuto]) . ')' : '' ?>.
    </p>
    <div class="filter-grid">
        <label><span>Responsable</span>
            <select name="web_dueno_id">
                <option value="">Automático</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $dueno === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="filter-actions">
        <button class="btn btn-primary btn-sm"><?= icon('ok', 15) ?>Guardar</button>
    </div>
</form>
<?php endif; ?>

<form class="filters card" method="get" action="<?= e(url('entradas-web')) ?>">
    <div class="filter-grid">
        <label><span>Buscar</span>
            <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nombre, correo, empresa, campaña…">
        </label>
        <label><span>Qué</span>
            <select name="tipo">
                <option value="">Todo</option>
                <option value="reserva"  <?= $f['tipo'] === 'reserva'  ? 'selected' : '' ?>>Reuniones</option>
                <option value="consulta" <?= $f['tipo'] === 'consulta' ? 'selected' : '' ?>>Consultas sin reunión</option>
            </select>
        </label>
        <label><span>Llegó por</span>
            <select name="canal">
                <option value="">Cualquier canal</option>
                <?php foreach ($opCanal as $c): ?>
                    <option value="<?= e($c) ?>" <?= $f['canal'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
                <option value="-" <?= $f['canal'] === '-' ? 'selected' : '' ?>>Sin dato</option>
            </select>
        </label>
        <label><span>Desde la página</span>
            <select name="pagina">
                <option value="">Cualquiera</option>
                <?php foreach ($opPagina as $pg): ?>
                    <option value="<?= e($pg) ?>" <?= $f['pagina'] === $pg ? 'selected' : '' ?>><?= e($pg) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Estado</span>
            <select name="estado">
                <option value="">Todos</option>
                <?php foreach ($estados as $k => [, $txt]): ?>
                    <option value="<?= e($k) ?>" <?= $f['estado'] === $k ? 'selected' : '' ?>><?= e($txt) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Desde el</span><input type="date" name="desde" value="<?= e($f['desde']) ?>"></label>
        <label><span>Hasta el</span><input type="date" name="hasta" value="<?= e($f['hasta']) ?>"></label>
        <label><span>Ordenar por</span>
            <select name="orden">
                <option value="">Más reciente</option>
                <option value="antigua" <?= $f['orden'] === 'antigua' ? 'selected' : '' ?>>Más antigua</option>
                <option value="canal"   <?= $f['orden'] === 'canal'   ? 'selected' : '' ?>>Canal</option>
                <option value="empresa" <?= $f['orden'] === 'empresa' ? 'selected' : '' ?>>Empresa</option>
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
                    <th>Llegó</th>
                    <th>Persona</th>
                    <th class="prio-2">Empresa</th>
                    <th>Qué</th>
                    <th>Llegó por</th>
                    <th class="prio-3">Desde la página</th>
                    <th class="prio-3">Campaña</th>
                    <th class="prio-4">Responsable</th>
                    <th class="w-estado">Estado</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$data['rows']): ?>
                <tr><td colspan="9" class="empty">No hay entradas que coincidan con el filtro. Cuando alguien deja
                    una consulta o reserva una reunión desde intellihelp.tech, aparece acá con la página y el canal
                    por el que llegó.</td></tr>
            <?php else: foreach ($data['rows'] as $r): ?>
                <?php
                $fuente = implode(' / ', array_filter([(string)$r['utm_source'], (string)$r['utm_medium']], 'strlen'));
                [$clase, $txt] = $estados[$r['estado']] ?? ['badge-neutral', $r['estado']];
                ?>
                <tr>
                    <td data-label="Llegó">
                        <div class="cell-main">
                            <strong><?= fecha_hora($r['creado_en']) ?></strong>
                            <small><?= e(tiempo_relativo($r['creado_en'])) ?></small>
                        </div>
                    </td>
                    <td data-label="Persona">
                        <div class="cell-main">
                            <?php if ($r['contacto_id']): ?>
                                <a href="<?= e(url('contacto-ver?id=' . (int)$r['contacto_id'])) ?>"><strong><?= e($r['nombre']) ?></strong></a>
                            <?php else: ?><strong><?= e($r['nombre']) ?></strong><?php endif; ?>
                            <small><?= e($r['email']) ?></small>
                        </div>
                    </td>
                    <td data-label="Empresa" class="prio-2">
                        <?php if ($r['cuenta_id']): ?>
                            <a href="<?= e(url('cuenta-ver?id=' . (int)$r['cuenta_id'])) ?>"><?= e(recortar($r['cuenta'] ?: $r['empresa'], 34)) ?></a>
                        <?php else: ?><?= e(recortar((string)$r['empresa'], 34)) ?: '<span class="muted">—</span>' ?><?php endif; ?>
                    </td>
                    <td data-label="Qué">
                        <div class="cell-main">
                            <strong><?= e($tipos[$r['tipo']] ?? $r['tipo']) ?></strong>
                            <?php if ($r['tipo'] === 'reserva' && $r['reserva_inicio']): ?>
                                <small><?= fecha_hora($r['reserva_inicio']) ?> · <?= e($reunion[$r['reserva_estado']] ?? (string)$r['reserva_estado']) ?></small>
                            <?php elseif ($r['servicio']): ?>
                                <small><?= e(recortar($r['servicio'], 40)) ?></small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Llegó por">
                        <div class="cell-main">
                            <span><?= e($r['canal'] ?: '—') ?></span>
                            <?php if ($fuente !== ''): ?><small><?= e($fuente) ?></small><?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Desde la página" class="prio-3">
                        <div class="cell-main">
                            <span class="mono"><?= e($r['origen_pagina'] ?: '—') ?></span>
                            <?php if ($r['landing'] && $r['landing'] !== $r['origen_pagina']): ?>
                                <small>Entró por <?= e($r['landing']) ?></small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Campaña" class="prio-3"><?= e($r['utm_campaign'] ?: '—') ?></td>
                    <td data-label="Responsable" class="prio-4"><?= e($r['propietario'] ?: '—') ?></td>
                    <td data-label="Estado" class="w-estado">
                        <span class="badge <?= $clase ?>" <?= $r['error'] ? 'title="' . e($r['error']) . '"' : '' ?>><?= e($txt) ?></span>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP . '/views/_pager.php'; ?>

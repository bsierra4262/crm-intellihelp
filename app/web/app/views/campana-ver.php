<?php
/** @var array $c @var array $envios @var array $data @var array $f @var ?array $vista
 *  @var bool $testing @var array $motivos */
$pct   = $c['total'] > 0 ? min(100, (int)round(100 * $c['enviados'] / $c['total'])) : 0;
$boton = function (string $accion, string $etiqueta, string $ic, string $clase = 'btn-ghost', array $confirmar = []) use ($c) {
    echo '<form class="inline-form" method="post" action="' . e(url('campana-accion')) . '">' . csrf_field()
       . '<input type="hidden" name="id" value="' . (int)$c['id'] . '">'
       . '<input type="hidden" name="accion" value="' . e($accion) . '">'
       . '<button class="btn ' . e($clase) . '"'
       . ($confirmar ? ' data-confirmar-titulo="' . e($confirmar['titulo']) . '" data-confirmar="' . e($confirmar['texto'])
           . '" data-confirmar-ok="' . e($confirmar['ok']) . '"' . (!empty($confirmar['peligro']) ? ' data-peligro' : '') : '')
       . '>' . icon($ic, 16) . e($etiqueta) . '</button></form>';
};
?>
<div class="page-head">
    <div>
        <h1><?= e($c['nombre']) ?></h1>
        <p class="muted">
            <?= badge_campana($c['estado']) ?>
            <?= e($c['lista'] ?: 'sin lista') ?> · <?= e($c['plantilla'] ?: 'sin plantilla') ?>
            <?php if ($c['inicio_en']): ?> · arrancó el <?= fecha_hora($c['inicio_en']) ?><?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php $boton('preparar', 'Preparar cola', 'refrescar'); ?>
        <?php if (in_array($c['estado'], ['borrador', 'pausada', 'programada'], true)): ?>
            <?php $boton('iniciar', 'Iniciar envío', 'campanas', 'btn-accent', [
                'titulo' => 'Iniciar la campaña',
                'texto'  => 'Se van a empezar a mandar los correos de a uno, con la pausa configurada. Podés pausarla cuando quieras.',
                'ok'     => 'Iniciar']); ?>
        <?php elseif ($c['estado'] === 'enviando'): ?>
            <?php $boton('pausar', 'Pausar', 'reloj', 'btn-ghost'); ?>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('campana-form?id=' . (int)$c['id'])) ?>"><?= icon('editar', 16) ?>Editar</a>
    </div>
</div>

<?php if ($testing && in_array($c['estado'], ['enviando', 'programada'], true)): ?>
    <div class="flash flash-info"><strong>Modo prueba:</strong> sólo salen correos a los destinatarios internos de Ajustes.</div>
<?php endif; ?>

<div class="cards">
    <div class="card stat">
        <span class="stat-label">Enviados</span>
        <span class="stat-value"><?= (int)$c['enviados'] ?> <span class="muted" style="font-size:15px">/ <?= (int)$c['total'] ?></span></span>
        <div class="progreso"><i class="<?= $pct >= 100 ? 'ok' : '' ?>" style="width:<?= $pct ?>%"></i></div>
    </div>
    <div class="card stat">
        <span class="stat-label">Respuestas</span>
        <span class="stat-value accent"><?= (int)$c['respondidos'] ?></span>
        <span class="stat-foot"><?= $c['enviados'] > 0 ? round(100 * $c['respondidos'] / $c['enviados'], 1) : 0 ?> % de los enviados</span>
    </div>
    <div class="card stat">
        <span class="stat-label">Rebotes</span>
        <span class="stat-value <?= $c['rebotados'] ? 'warn' : '' ?>"><?= (int)$c['rebotados'] ?></span>
        <span class="stat-foot"><?= (int)$c['desuscriptos'] ?> bajas · <?= (int)$c['errores'] ?> errores</span>
    </div>
    <div class="card stat">
        <span class="stat-label">Reuniones agendadas</span>
        <span class="stat-value accent"><?= (int)$c['reuniones'] ?></span>
        <span class="stat-foot">desde el enlace de la agenda</span>
    </div>
</div>

<?php if ($motivos): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-head"><h2>Por qué se omitieron destinatarios</h2></div>
    <?php foreach ($motivos as $m): ?>
        <div class="bar-row">
            <span class="bar-label"><?= e($m['motivo'] ?: 'Sin motivo') ?></span>
            <span class="bar-track"><i class="bar-fill" style="width:<?= $c['total'] > 0 ? min(100, round(100 * $m['n'] / max(1, (int)$c['total']))) : 0 ?>%"></i></span>
            <span class="bar-val"><?= (int)$m['n'] ?></span>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($vista): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-head"><h2>Vista previa</h2><span class="small muted">Como lo va a recibir <?= e($vista['para']) ?></span></div>
    <p style="margin:0 0 .6rem"><strong>Asunto:</strong> <?= e($vista['asunto']) ?></p>
    <div style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:1rem;background:#fff;overflow-x:auto">
        <?= $vista['html'] ?>
    </div>
</div>
<?php endif; ?>

<form class="filters card" method="get" action="<?= e(url('campana-ver')) ?>">
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    <div class="filter-grid">
        <label><span>Buscar destinatario</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Correo, nombre, empresa…"></label>
        <label><span>Estado</span>
            <select name="estado" data-auto>
                <option value="">Todos</option>
                <?php foreach (['pendiente', 'enviado', 'rebotado', 'rechazado', 'omitido', 'error'] as $x): ?>
                    <option value="<?= e($x) ?>" <?= $f['estado'] === $x ? 'selected' : '' ?>><?= e(ucfirst($x)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
</form>

<div class="card table-card">
    <div class="table-scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th>Destinatario</th>
                    <th class="prio-2">Empresa</th>
                    <th>Estado</th>
                    <th class="prio-3">Enviado</th>
                    <th class="prio-3">Respondió</th>
                    <th class="prio-4">Detalle</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$envios): ?>
                <tr><td colspan="6" class="empty">La cola está vacía. Usá «Preparar cola» para cargar los destinatarios de la lista.</td></tr>
            <?php else: foreach ($envios as $x): ?>
                <tr>
                    <td data-label="Destinatario">
                        <div class="cell-main">
                            <?php if ($x['contacto_id']): ?>
                                <a href="<?= e(url('contacto-ver?id=' . (int)$x['contacto_id'])) ?>"><strong><?= e($x['nombre'] ?: $x['email']) ?></strong></a>
                            <?php else: ?><strong><?= e($x['nombre'] ?: $x['email']) ?></strong><?php endif; ?>
                            <small><?= e($x['email']) ?></small>
                        </div>
                    </td>
                    <td data-label="Empresa" class="prio-2"><?= e(recortar($x['empresa'], 28) ?: '—') ?></td>
                    <td data-label="Estado"><?= badge_envio($x['estado']) ?></td>
                    <td data-label="Enviado" class="prio-3"><?= $x['enviado_en'] ? fecha_hora($x['enviado_en']) : '—' ?></td>
                    <td data-label="Respondió" class="prio-3"><?= $x['respondido_en'] ? fecha_hora($x['respondido_en']) : '—' ?></td>
                    <td data-label="Detalle" class="prio-4"><span class="small muted"><?= e(recortar($x['motivo'], 46) ?: '—') ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP . '/views/_pager.php'; ?>

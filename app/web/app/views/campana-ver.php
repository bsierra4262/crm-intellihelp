<?php
/** @var int $aperturas @var array $c @var array $envios @var array $data @var array $f @var ?array $vista
 *  @var bool $testing @var array $motivos */
// Dos cosas distintas que antes se mezclaban: «entregados» es cuántos llegaron,
// y el AVANCE es cuántos ya se resolvieron —entregados más rebotados—. Con 3
// entregados y 3 rebotados, entregados son 3 pero el avance es 6 de 6: no queda
// nada por hacer.
$hechos = (int)($c['procesados'] ?? $c['enviados']);
$pct    = $c['total'] > 0 ? min(100, (int)round(100 * $hechos / $c['total'])) : 0;
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
        <?php if (in_array($c['estado'], ['enviando', 'pausada', 'programada'], true)): ?>
            <?php /* Cancelar existía en el servidor y no había ningún botón que
                     lo disparara: una campaña arrancada por error sólo se podía
                     pausar, y quedaba ahí para siempre. */ ?>
            <?php $boton('cancelar', 'Cancelar campaña', 'x', 'btn-ghost', [
                'titulo' => 'Cancelar la campaña',
                'texto'  => 'Los correos que todavía no salieron se descartan y la campaña no se puede reanudar. '
                          . 'Lo que ya se envió no se puede deshacer.',
                'ok'     => 'Cancelar la campaña']); ?>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('campana-form?id=' . (int)$c['id'])) ?>"><?= icon('editar', 16) ?>Editar</a>
    </div>
</div>

<?php
$pasos = campana_pasos($c);
$faltan = array_filter($pasos, fn($p) => !$p['ok']);
?>
<div class="card pasos mb-4">
    <div class="card-head">
        <h2>Cómo arrancar esta campaña</h2>
        <span class="small <?= $faltan ? 'warn' : 'muted' ?>">
            <?= $faltan ? count($faltan) . ' paso(s) pendiente(s)' : 'todo listo' ?>
        </span>
    </div>
    <ol class="pasos-lista">
        <?php foreach ($pasos as $i => $p): ?>
            <li class="paso <?= $p['ok'] ? 'ok' : ($p['bloqueante'] ? 'falta' : 'aviso') ?>">
                <span class="paso-n"><?= $p['ok'] ? '✓' : $i + 1 ?></span>
                <span class="paso-txt">
                    <strong><?= e($p['titulo']) ?></strong>
                    <small><?= e($p['detalle']) ?></small>
                </span>
                <?php if (!$p['ok'] && $p['accion']): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e($p['enlace']) ?>"><?= e($p['accion']) ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <li class="paso <?= $c['estado'] === 'enviando' ? 'ok' : 'falta' ?>">
            <span class="paso-n"><?= $c['estado'] === 'enviando' ? '✓' : count($pasos) + 1 ?></span>
            <span class="paso-txt">
                <strong>Arrancar</strong>
                <small>
                    <?php if ($c['estado'] === 'enviando'): ?>
                        La campaña está enviando. Sale un correo cada <?= (int)$c['pausa_min_seg'] ?>–<?= (int)$c['pausa_max_seg'] ?> segundos,
                        hasta <?= (int)$c['limite_diario'] ?> por día. Podés cerrar el navegador: sigue sola.
                    <?php elseif ($c['estado'] === 'completada'): ?>
                        La campaña terminó.
                    <?php elseif (campana_lista_para_enviar($c)): ?>
                        Ya está todo listo. Al iniciar se prepara la cola y empieza a enviar.
                    <?php else: ?>
                        Primero hay que resolver los pasos de arriba.
                    <?php endif; ?>
                </small>
            </span>
        </li>
    </ol>
</div>

<?php if ($testing && in_array($c['estado'], ['enviando', 'programada'], true)): ?>
    <div class="flash flash-info"><strong>Modo prueba:</strong> sólo salen correos a los destinatarios internos de Ajustes.</div>
<?php endif; ?>

<?php /* data-vivo enciende la actualización sola: el script pregunta cada seis
         segundos y cambia estos números sin recargar la página. Sin eso había
         que apretar F5 para saber si la campaña estaba avanzando. */ ?>
<div class="cards" data-vivo data-vivo-campana="<?= (int)$c['id'] ?>">
    <div class="card stat">
        <span class="stat-label">Entregados</span>
        <span class="stat-value"><span data-vivo-campo="enviados"><?= (int)$c['enviados'] ?></span>
            <span class="muted">/ <span data-vivo-campo="total"><?= (int)$c['total'] ?></span></span></span>
        <div class="progreso"><i data-vivo-barra class="<?= $pct >= 100 ? 'ok' : '' ?>" style="width:<?= $pct ?>%"></i></div>
        <span class="stat-foot">
            Avance: <span data-vivo-campo="procesados"><?= $hechos ?></span> de
            <span data-vivo-campo="total2"><?= (int)$c['total'] ?></span> resueltos
            <?php if ($hechos >= (int)$c['total'] && (int)$c['total'] > 0): ?>
                — no queda nada en la cola
            <?php endif; ?>
        </span>
        <span class="stat-foot" data-vivo-cron <?= setting_get('cron_en_curso_desde', '') !== '' ? '' : 'hidden' ?>>
            <strong class="accent">Enviando ahora…</strong>
        </span>
    </div>
    <div class="card stat">
        <span class="stat-label">Aperturas</span>
        <span class="stat-value" data-vivo-campo="aperturas"><?= (int)$aperturas ?></span>
        <span class="stat-foot">
            <?= $c['enviados'] > 0 ? round(100 * $aperturas / $c['enviados'], 1) : 0 ?> % de los enviados.
            Es un piso: muchos clientes bloquean las imágenes y esa apertura no se cuenta.
        </span>
    </div>
    <div class="card stat">
        <span class="stat-label">Respuestas</span>
        <span class="stat-value accent" data-vivo-campo="respondidos"><?= (int)$c['respondidos'] ?></span>
        <span class="stat-foot"><?= $c['enviados'] > 0 ? round(100 * $c['respondidos'] / $c['enviados'], 1) : 0 ?> % de los enviados</span>
    </div>
    <div class="card stat">
        <span class="stat-label">Rebotes</span>
        <span class="stat-value <?= $c['rebotados'] ? 'warn' : '' ?>" data-vivo-campo="rebotados"><?= (int)$c['rebotados'] ?></span>
        <span class="stat-foot"><?= (int)$c['desuscriptos'] ?> bajas · <?= (int)$c['errores'] ?> errores</span>
    </div>
    <div class="card stat">
        <span class="stat-label">Reuniones agendadas</span>
        <span class="stat-value accent" data-vivo-campo="reuniones"><?= (int)$c['reuniones'] ?></span>
        <span class="stat-foot">desde el enlace de la agenda</span>
    </div>
</div>

<?php if ($motivos): ?>
<div class="card mb-4">
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
<div class="card mb-4">
    <div class="card-head"><h2>Vista previa</h2><span class="small muted">Como lo va a recibir <?= e($vista['para']) ?></span></div>
    <p class="mb-3 m-0"><strong>Asunto:</strong> <?= e($vista['asunto']) ?></p>
    <div class="correo-vista">
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

<div class="card table-card" data-vivo-tabla>
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
                <tr data-envio="<?= (int)$x['id'] ?>">
                    <td data-label="Destinatario">
                        <div class="cell-main">
                            <?php if ($x['contacto_id']): ?>
                                <a href="<?= e(url('contacto-ver?id=' . (int)$x['contacto_id'])) ?>"><strong><?= e($x['nombre'] ?: $x['email']) ?></strong></a>
                            <?php else: ?><strong><?= e($x['nombre'] ?: $x['email']) ?></strong><?php endif; ?>
                            <small><?= e($x['email']) ?></small>
                        </div>
                    </td>
                    <td data-label="Empresa" class="prio-2"><?= e(recortar($x['empresa'], 28) ?: '—') ?></td>
                    <td data-label="Estado"><span data-vivo-badge><?= badge_envio($x['estado']) ?></span></td>
                    <td data-label="Enviado" class="prio-3" data-vivo-enviado><?= $x['enviado_en'] ? fecha_hora($x['enviado_en']) : '—' ?></td>
                    <td data-label="Respondió" class="prio-3" data-vivo-respondio><?= $x['respondido_en'] ? fecha_hora($x['respondido_en']) : '—' ?></td>
                    <td data-label="Detalle" class="prio-4"><span class="small muted"><?= e(recortar($x['motivo'], 46) ?: '—') ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include APP . '/views/_pager.php'; ?>

<?php
/** @var array $m @var array $emb @var float $ganadoMes @var array $hoy
 *  @var int $vencidas @var array $campanas @var bool $mio */
$u = auth_user();
?>
<div class="page-head">
    <div>
        <h1>Hola, <?= e(explode(' ', (string)$u['nombre'])[0]) ?></h1>
        <p class="muted"><?= e(ucfirst(fecha_larga())) ?></p>
    </div>
    <div class="head-actions">
        <div class="seg">
            <a class="seg-item <?= $mio ? 'active' : '' ?>" href="<?= e(url('dashboard?ambito=mio')) ?>">Lo mío</a>
            <a class="seg-item <?= $mio ? '' : 'active' ?>" href="<?= e(url('dashboard?ambito=todos')) ?>">Todo el equipo</a>
        </div>
        <a class="btn btn-primary" href="<?= e(url('posible-form')) ?>"><?= icon('mas', 16) ?>Nuevo posible cliente</a>
    </div>
</div>

<?php include APP . '/views/_aviso_gcal.php'; ?>

<div class="cards">
    <a class="card stat stat-link" href="<?= e(url('posibles')) ?>">
        <span class="stat-label">Posibles clientes</span>
        <span class="stat-value"><?= number_format($m['posibles'], 0, ',', '.') ?></span>
        <span class="stat-foot"><?= number_format($m['sin_tocar'], 0, ',', '.') ?> sin contactar</span>
    </a>
    <a class="card stat stat-link" href="<?= e(url('embudo')) ?>">
        <span class="stat-label">Embudo abierto</span>
        <span class="stat-value"><?= money((float)($emb['total'] ?? 0)) ?></span>
        <span class="stat-foot"><?= (int)($emb['n'] ?? 0) ?> negocios · <?= money((float)($emb['ponderado'] ?? 0)) ?> ponderado</span>
    </a>
    <div class="card stat">
        <span class="stat-label">Ganado este mes</span>
        <span class="stat-value accent"><?= money($ganadoMes) ?></span>
        <span class="stat-foot">desde el <?= date('d/m') ?></span>
    </div>
    <a class="card stat stat-link" href="<?= e(url('actividades?vencidas=1')) ?>">
        <span class="stat-label">Actividades vencidas</span>
        <span class="stat-value <?= $vencidas ? 'danger' : '' ?>"><?= number_format($vencidas, 0, ',', '.') ?></span>
        <span class="stat-foot"><?= number_format($m['contactos'], 0, ',', '.') ?> contactos · <?= number_format($m['cuentas'], 0, ',', '.') ?> empresas</span>
    </a>
</div>

<div class="rep-grid">
    <div class="card">
        <div class="card-head">
            <h2>Agenda de hoy</h2>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('actividades')) ?>">Ver todas</a>
        </div>
        <?php if (!$hoy): ?>
            <p class="muted small">No tenés nada agendado para hoy.</p>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($hoy as $a):
                    $cuando = $a['inicia_el'] ?: $a['vence_el'];
                    $ico = ['tarea' => 'tareas', 'llamada' => 'llamadas', 'reunion' => 'agenda'][$a['tipo']] ?? 'tareas';
                ?>
                    <div class="tl-item">
                        <div class="tl-when"><?= $cuando ? date('H:i', strtotime($cuando)) : '—' ?> · <?= e(ucfirst($a['tipo'])) ?></div>
                        <div class="tl-title"><?= icon($ico, 14) ?> <?= e($a['asunto']) ?></div>
                        <?php if ($a['contacto']): ?><div class="tl-detail"><?= e($a['contacto']) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" data-vivo>
        <div class="card-head">
            <h2>Campañas en curso</h2>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('campanas')) ?>">Ver todas</a>
        </div>
        <?php if (!$campanas): ?>
            <p class="muted small">No hay campañas activas en este momento.</p>
        <?php else: ?>
            <?php foreach ($campanas as $c):
                $pct = $c['total'] > 0 ? min(100, round(100 * $c['enviados'] / $c['total'])) : 0;
            ?>
                <div class="mb-3" data-vivo-fila="<?= (int)$c['id'] ?>">
                    <div class="urow entre">
                        <a href="<?= e(url('campana-ver?id=' . (int)$c['id'])) ?>" class="strong"><?= e($c['nombre']) ?></a>
                        <span class="badge <?= $c['estado'] === 'enviando' ? 'badge-ok' : 'badge-neutral' ?>"><?= e($c['estado']) ?></span>
                    </div>
                    <div class="progreso"><i data-vivo-barra style="width:<?= $pct ?>%"></i></div>
                    <div class="small muted" data-vivo-resumen>
                        <?= (int)$c['enviados'] ?> de <?= (int)$c['total'] ?> enviados ·
                        <?= (int)$c['respondidos'] ?> respuestas · <?= (int)$c['rebotados'] ?> rebotes
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

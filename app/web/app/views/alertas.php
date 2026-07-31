<?php /** @var array $rows @var int $sinLeer */
$tipos = notif_tipos();
?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Alertas</h1>
        <p class="muted">
            <?= $sinLeer ? $sinLeer . ' sin leer' : 'Todo leído' ?>
            · lo que pasa mientras no estás mirando
        </p>
    </div>
    <div class="head-actions">
        <?php if ($sinLeer): ?>
            <form class="inline-form" method="post">
                <?= csrf_field() ?>
                <button class="btn btn-ghost"><?= icon('ok', 16) ?>Marcar todo como leído</button>
            </form>
        <?php endif; ?>
        <?php if (auth_is_admin()): ?>
            <a class="btn btn-ghost" href="<?= e(url('ajustes')) ?>#alertas"><?= icon('ajustes', 16) ?>Qué me avisan</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$rows): ?>
    <div class="card">
        <div class="empty-state">
            <?= icon('campanas', 34) ?>
            <h2>Todavía no pasó nada</h2>
            <p class="small">
                Acá van a aparecer las respuestas de los clientes, las reuniones que agenden
                desde tu enlace público y los rebotes definitivos. También te lo podemos mandar
                por correo: se elige en <a href="<?= e(url('ajustes')) ?>">Ajustes</a>.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="card table-card" data-vivo="tabla">
        <div class="table-scroll">
            <table class="grid">
                <thead><tr><th>Cuándo</th><th>Qué pasó</th><th class="prio-2">Tipo</th><th class="acts">Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $n): $t = $tipos[$n['tipo']] ?? null; ?>
                    <tr class="<?= $n['leida_en'] ? '' : 'sin-leer' ?>">
                        <td data-label="Cuándo">
                            <div class="cell-main">
                                <strong><?= fecha_hora($n['creada_en']) ?></strong>
                                <small><?= e(tiempo_relativo($n['creada_en'])) ?></small>
                            </div>
                        </td>
                        <td data-label="Qué pasó">
                            <div class="cell-main">
                                <?php if ($n['url']): ?>
                                    <a href="<?= e(url((string)$n['url'])) ?>"><strong><?= e($n['titulo']) ?></strong></a>
                                <?php else: ?><strong><?= e($n['titulo']) ?></strong><?php endif; ?>
                                <?php if ($n['detalle']): ?><small><?= e($n['detalle']) ?></small><?php endif; ?>
                            </div>
                        </td>
                        <td data-label="Tipo" class="prio-2">
                            <span class="badge <?= $n['tipo'] === 'respuesta' ? 'badge-ok'
                                : ($n['tipo'] === 'reunion' ? 'badge-info'
                                : ($n['tipo'] === 'rebote' ? 'badge-rej' : 'badge-neutral')) ?>">
                                <?= e($t['nombre'] ?? $n['tipo']) ?>
                            </span>
                        </td>
                        <td class="acts" data-label="Acciones">
                            <?php if (!$n['leida_en']): ?>
                                <form class="inline-form" method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button class="act" title="Marcar como leída"><?= icon('ok', 16) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php /** @var string $entidad @var array $defs @var array $rows @var array $conteos */ ?>
<div class="page-head">
    <div>
        <h1>Papelera</h1>
        <p class="muted">Nada se borra de verdad: acá se puede recuperar</p>
    </div>
</div>

<div class="seg" style="margin-bottom:1rem">
    <?php foreach ($defs as $k => $d): ?>
        <a class="seg-item <?= $entidad === $k ? 'active' : '' ?>" href="<?= e(url('papelera?entidad=' . $k)) ?>">
            <?= e($d['etiqueta']) ?> <span class="badge badge-neutral"><?= (int)$conteos[$k] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="card table-card">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th><?= e($defs[$entidad]['etiqueta']) ?></th><th>Eliminado</th><th class="acts">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="3" class="empty">No hay nada en esta parte de la papelera.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td data-label="Registro"><strong><?= e($r['etiqueta'] ?: '(sin nombre)') ?></strong></td>
                    <td data-label="Eliminado"><?= fecha_hora($r['eliminado_en']) ?> · <?= e(tiempo_relativo($r['eliminado_en'])) ?></td>
                    <td class="acts" data-label="Acciones">
                        <form class="inline-form" method="post" action="<?= e(url('registro-eliminar')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="entidad" value="<?= e($entidad) ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="accion" value="restaurar">
                            <input type="hidden" name="volver" value="papelera?entidad=<?= e($entidad) ?>">
                            <button class="btn btn-ghost btn-sm"><?= icon('refrescar', 15) ?>Restaurar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

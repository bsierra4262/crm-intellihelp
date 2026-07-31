<?php /** @var string $entidad @var array $defs @var array $rows @var array $conteos
 *  @var int $dias @var array $motivos */ ?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Papelera</h1>
        <p class="muted">Lo que se elimina queda acá <?= (int)$dias ?> días y se puede recuperar</p>
    </div>
</div>

<div class="flash flash-info">
    Cuando un correo rebota de forma <strong>definitiva</strong> —la casilla ya no existe o el
    dominio se dio de baja— el sistema <strong>archiva el contacto solo</strong>, para que la base
    no siga cargando direcciones muertas. No se pierde nada: queda acá
    <strong><?= (int)$dias ?> días</strong> y se puede restaurar con un clic. Recién pasado ese
    plazo se borra de verdad.
</div>

<div class="seg mb-4">
    <?php foreach ($defs as $k => $d): ?>
        <a class="seg-item <?= $entidad === $k ? 'active' : '' ?>" href="<?= e(url('papelera?entidad=' . $k)) ?>">
            <?= e($d['etiqueta']) ?> <span class="badge badge-neutral"><?= (int)$conteos[$k] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="card table-card" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th><?= e($defs[$entidad]['etiqueta']) ?></th><th>Por qué</th><th>Eliminado</th>
                       <th>Se borra en</th><th class="acts">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="empty">No hay nada en esta parte de la papelera.</td></tr>
            <?php else: foreach ($rows as $r):
                $m = $motivos[(int)$r['id']] ?? null;
                $quedan = (int)$r['dias_restantes'];
            ?>
                <tr>
                    <td data-label="Registro">
                        <div class="cell-main">
                            <strong><?= e($r['etiqueta'] ?: '(sin nombre)') ?></strong>
                            <?php if ($m && $m['automatico']): ?><small><?= e(recortar($m['detalle'], 70)) ?></small><?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Por qué">
                        <?php if ($m && $m['automatico']): ?>
                            <span class="badge badge-pend">Correo rebotado</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Lo eliminó una persona</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Eliminado"><?= fecha_hora($r['eliminado_en']) ?></td>
                    <td data-label="Se borra en">
                        <span class="badge <?= $quedan <= 7 ? 'badge-rej' : 'badge-info' ?>">
                            <?= $quedan > 0 ? $quedan . ' días' : 'en la próxima purga' ?>
                        </span>
                    </td>
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

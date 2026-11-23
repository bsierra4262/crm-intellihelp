<?php
/**
 * Panel de notas de un registro. Espera $notas y, en el scope, la entidad y el
 * id (se deducen de la ruta actual para no repetirlos en cada vista).
 *
 * Se incluye desde las fichas (contacto-ver, cuenta-ver, posible-ver,
 * negocio-ver); $notas lo arma la página con notas_de(). En otra ruta no se
 * muestra el formulario de alta (entidad vacía). El formulario va a
 * nota-guardar; el botón de borrar, a nota-eliminar.
 *
 * El botón de borrar sólo aparece en las notas que la persona puede borrar:
 * las suyas, o todas si es administrador (el servidor igual lo controla).
 * El borrado es lógico (eliminado_en): la nota deja de verse en la ficha,
 * pero no hay pantalla para recuperarla.
 */
/** @var array $notas */
$ent = match (current_route()) {
    'contacto-ver' => 'contacto',
    'cuenta-ver'   => 'cuenta',
    'posible-ver'  => 'posible_cliente',
    'negocio-ver'  => 'negocio',
    default        => '',
};
$entId = (int)($_GET['id'] ?? 0);
$notaUid   = current_user_id();
$notaAdmin = auth_is_admin();
?>
<div class="card">
    <div class="card-head"><h2>Notas</h2></div>
    <?php if ($ent): ?>
    <form method="post" action="<?= e(url('nota-guardar')) ?>" class="mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="entidad" value="<?= e($ent) ?>">
        <input type="hidden" name="entidad_id" value="<?= $entId ?>">
        <label class="fld">
            <textarea name="cuerpo" rows="3" placeholder="Escribí lo que pasó en la reunión, la llamada o el correo…" required></textarea>
        </label>
        <div class="mt-3"><button class="btn btn-primary btn-sm"><?= icon('nota', 14) ?>Agregar nota</button></div>
    </form>
    <?php endif; ?>

    <?php if (!$notas): ?>
        <p class="muted small">Todavía no hay notas.</p>
    <?php else: ?>
        <div class="timeline">
            <?php foreach ($notas as $n): ?>
                <div class="tl-item">
                    <div class="tl-when"><?= fecha_hora($n['creado_en']) ?> · <?= e($n['autor'] ?: 'sistema') ?></div>
                    <?php if ($n['titulo']): ?><div class="tl-title"><?= e($n['titulo']) ?></div><?php endif; ?>
                    <div class="tl-detail cuerpo"><?= e($n['cuerpo']) ?></div>
                    <?php /* Borrar una nota existía como función y no la llamaba
                             nadie: una nota escrita por error no se podía sacar. */ ?>
                    <?php if ($notaAdmin || ((int)($n['creado_por'] ?? 0) === $notaUid && $notaUid)): ?>
                    <form class="inline-form" method="post" action="<?= e(url('nota-eliminar')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                        <button class="act danger" title="Borrar la nota" data-peligro
                                data-confirmar-titulo="Borrar la nota"
                                data-confirmar="La nota deja de verse en la ficha. No va a la papelera: no se puede recuperar desde la pantalla."
                                data-confirmar-ok="Borrar"><?= icon('papelera', 14) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

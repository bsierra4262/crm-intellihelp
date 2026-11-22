<?php
// ============================================================
//  Confirmación para cerrar la sesión. La dibuja pages/logout.php cuando
//  llega un GET que no se puede confirmar como propio (un enlace desde otro
//  sitio, un navegador que no dice de dónde viene el pedido): en vez de cerrar
//  la sesión sin preguntar, se ofrece el botón, que va por POST con el token.
//
//  Variables: $motivo ('inactividad' | 'expiro' | ''), que viaja en el
//  formulario para que la auditoría y el login digan por qué se salió.
// ============================================================
/** @var string $motivo */
?>
<div class="empty-state">
    <div class="err-code"><?= icon('salir', 40) ?></div>
    <h2>¿Cerrar la sesión?</h2>
    <p>
        <?= $motivo === 'inactividad'
            ? 'Pasó el tiempo de inactividad. Si seguís trabajando en otra pestaña, podés quedarte.'
            : ($motivo === 'expiro'
                ? 'La sesión llegó a su duración máxima.'
                : 'Confirmá para salir del sistema.') ?>
    </p>
    <form class="mt-5" method="post" action="<?= e(url('logout')) ?>">
        <?= csrf_field() ?>
        <?php if ($motivo !== ''): ?><input type="hidden" name="motivo" value="<?= e($motivo) ?>"><?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= icon('salir', 16) ?>Cerrar sesión</button>
        <a class="btn btn-ghost" href="<?= e(url('dashboard')) ?>">Seguir trabajando</a>
    </form>
</div>

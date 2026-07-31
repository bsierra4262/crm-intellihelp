<?php /** @var int $codigo @var string $titulo @var string $mensaje */ ?>
<div class="empty-state">
    <div class="err-code"><?= (int)$codigo ?></div>
    <h2><?= e($titulo) ?></h2>
    <p><?= e($mensaje) ?></p>
    <p class="mt-5"><a class="btn btn-primary" href="<?= e(url('dashboard')) ?>">Volver al panel</a></p>
</div>

<?php /** @var int $codigo @var string $titulo @var string $mensaje */ ?>
<div class="empty-state">
    <div style="font-size:52px;font-weight:800;color:var(--line);letter-spacing:-.03em"><?= (int)$codigo ?></div>
    <h2><?= e($titulo) ?></h2>
    <p><?= e($mensaje) ?></p>
    <p style="margin-top:1.2rem"><a class="btn btn-primary" href="<?= e(url('dashboard')) ?>">Volver al panel</a></p>
</div>

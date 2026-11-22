<?php
// ============================================================
//  Pantalla de error genérica (404, 403, 500, «módulo no disponible»).
//
//  La usan index.php, require_admin(), render() cuando una vista falla y
//  varios controladores. Variables: $codigo (número grande), $titulo y
//  $mensaje (texto, se escapan). Siempre ofrece volver al panel.
// ============================================================
?>
<?php /** @var int $codigo @var string $titulo @var string $mensaje */ ?>
<div class="empty-state">
    <div class="err-code"><?= (int)$codigo ?></div>
    <h2><?= e($titulo) ?></h2>
    <p><?= e($mensaje) ?></p>
    <p class="mt-5"><a class="btn btn-primary" href="<?= e(url('dashboard')) ?>">Volver al panel</a></p>
</div>

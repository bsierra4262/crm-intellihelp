<?php
// ============================================================
//  Pantalla de error genérica (404, 403, 419, 500, «módulo no disponible»).
//
//  La usan index.php, require_admin(), verify_csrf(), render() cuando una
//  vista falla y varios controladores. Variables: $codigo (número grande),
//  $titulo y $mensaje (texto, se escapan). Opcionales:
//    · $campos       nombre => texto que la persona había escrito (419): se
//                    muestra en cuadros de sólo lectura para poder copiarlo;
//    · $volverAtras  true agrega el botón «Volver atrás» (historial).
//  Siempre ofrece volver al panel.
// ============================================================
?>
<?php /** @var int $codigo @var string $titulo @var string $mensaje */
$campos      = isset($campos) && is_array($campos) ? $campos : [];
$volverAtras = !empty($volverAtras);
?>
<div class="empty-state">
    <div class="err-code"><?= (int)$codigo ?></div>
    <h2><?= e($titulo) ?></h2>
    <p><?= e($mensaje) ?></p>
    <p class="mt-5">
        <?php if ($volverAtras): ?>
            <a class="btn btn-primary" href="<?= e(url('dashboard')) ?>" onclick="history.back(); return false;">Volver atrás</a>
            <a class="btn btn-ghost" href="<?= e(url('dashboard')) ?>">Ir al panel</a>
        <?php else: ?>
            <a class="btn btn-primary" href="<?= e(url('dashboard')) ?>">Volver al panel</a>
        <?php endif; ?>
    </p>
</div>
<?php // Fuera de .empty-state, que centra el texto: esto se lee mejor alineado. ?>
<?php if ($campos): ?>
    <div class="card mt-5">
        <div class="card-head"><h2>Lo que escribiste</h2></div>
        <?php foreach ($campos as $nombre => $texto): ?>
            <label class="fld">
                <span><?= e(ucfirst(str_replace('_', ' ', (string)$nombre))) ?></span>
                <textarea rows="<?= min(10, max(2, substr_count((string)$texto, "\n") + 2)) ?>" readonly><?= e($texto) ?></textarea>
            </label>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// ============================================================
//  Página pública de error de la agenda (sin login, con el logo y sin el
//  menú del CRM). La usan pages/r.php y pages/reservar.php: módulo apagado,
//  enlace inexistente, demasiados intentos, falla al reservar.
//
//  Recibe: $titulo y $mensaje (texto plano, se escapan). El código HTTP lo
//  pone quien llama antes de dibujarla.
// ============================================================
?>
<?php /** @var string $titulo @var string $mensaje */ ?>
<div class="pub-wrap angosta">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div><h1><?= e($titulo) ?></h1></div>
        </div>
        <div class="pub-pad"><p class="muted"><?= e($mensaje) ?></p></div>
    </div>
</div>

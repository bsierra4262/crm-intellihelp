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

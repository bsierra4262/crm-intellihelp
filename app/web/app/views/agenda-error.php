<?php /** @var string $titulo @var string $mensaje */ ?>
<div class="pub-wrap" style="max-width:520px">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div><h1 style="font-size:19px;margin:0"><?= e($titulo) ?></h1></div>
        </div>
        <div style="padding:1.5rem"><p class="muted"><?= e($mensaje) ?></p></div>
    </div>
</div>

<?php /** @var ?array $r @var bool $ok */ ?>
<div class="pub-wrap" style="max-width:520px">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div><h1 style="font-size:19px;margin:0">Cancelar la reunión</h1></div>
        </div>
        <div style="padding:1.5rem">
            <?php if (!$r): ?>
                <div class="flash flash-error">El enlace no es válido.</div>
            <?php elseif ($ok || $r['estado'] === 'cancelada'): ?>
                <div class="flash flash-success">La reunión quedó cancelada. Ya avisamos por el calendario.</div>
            <?php else: ?>
                <p>Vas a cancelar la reunión del
                    <strong><?= e(fecha_larga((string)$r['inicia_el'])) ?>
                    a las <?= date('H:i', strtotime((string)$r['inicia_el'])) ?> h</strong>.</p>
                <form method="post" style="margin-top:1rem">
                    <label class="fld"><span>Motivo (opcional)</span>
                        <textarea name="motivo" rows="3" placeholder="Nos ayuda a mejorar"></textarea>
                    </label>
                    <button class="btn btn-danger btn-block" style="margin-top:1rem">Cancelar la reunión</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

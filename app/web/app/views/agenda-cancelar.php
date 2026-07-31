<?php /** @var ?array $r @var bool $ok */ ?>
<div class="pub-wrap angosta">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div><h1>Cancelar la reunión</h1></div>
        </div>
        <div class="pub-pad">
            <?php if (!$r): ?>
                <div class="flash flash-error">El enlace no es válido.</div>
            <?php elseif ($ok || $r['estado'] === 'cancelada'): ?>
                <div class="flash flash-success">La reunión quedó cancelada. Ya avisamos por el calendario.</div>
            <?php else: ?>
                <p>Vas a cancelar la reunión del
                    <strong><?= e(fecha_larga((string)$r['inicia_el'])) ?>
                    a las <?= date('H:i', strtotime((string)$r['inicia_el'])) ?> h</strong>.</p>
                <form method="post" class="mt-4">
                    <label class="fld"><span>Motivo (opcional)</span>
                        <textarea name="motivo" rows="3" placeholder="Nos ayuda a mejorar"></textarea>
                    </label>
                    <button class="btn btn-danger btn-block mt-4">Cancelar la reunión</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php /** @var ?array $envio @var bool $hecho */ ?>
<div class="pub-wrap angosta">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div>
                <h1>Baja de suscripción</h1>
                <p class="muted small m-0">Comunicaciones comerciales de INTELLIHELP</p>
            </div>
        </div>
        <div class="pub-pad">
            <?php if (!$envio): ?>
                <div class="flash flash-error">
                    Este enlace no es válido o ya venció. Si querés dejar de recibir nuestros correos,
                    respondé al último que te llegó y lo damos de baja a mano.
                </div>
            <?php elseif ($hecho): ?>
                <div class="flash flash-success">
                    Listo. Sacamos a <strong><?= e($envio['email']) ?></strong> de nuestra base de envíos.
                </div>
                <p class="muted small">
                    No vas a recibir más correos comerciales nuestros. Si fue un error, escribinos y te
                    volvemos a dar de alta.
                </p>
            <?php else: ?>
                <p>¿Querés dejar de recibir los correos comerciales de INTELLIHELP?</p>
                <p class="muted small">Se dará de baja la dirección <strong><?= e($envio['email']) ?></strong>.</p>
                <form method="post" class="mt-5">
                    <input type="hidden" name="confirmar" value="1">
                    <button class="btn btn-danger btn-block">Sí, quiero darme de baja</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <p class="muted small pub-foot">© <?= date('Y') ?> INTELLIHELP S.A.</p>
</div>

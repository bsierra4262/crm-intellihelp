<?php /** @var array $r @var array $tipo */ ?>
<div class="pub-wrap" style="max-width:560px">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div>
                <h1 style="font-size:20px;margin:0">¡Listo, quedó agendada!</h1>
                <p class="muted small" style="margin:0">Te mandamos la confirmación por correo</p>
            </div>
        </div>
        <div style="padding:1.5rem">
            <div class="kv">
                <div><div class="k">Reunión</div><div class="v"><?= e($tipo['nombre']) ?></div></div>
                <div><div class="k">Con</div><div class="v"><?= e($tipo['usuario']) ?></div></div>
                <div><div class="k">Cuándo</div><div class="v">
                    <?= e(ucfirst(fecha_larga((string)$r['inicia_el']))) ?>,
                    <?= date('H:i', strtotime((string)$r['inicia_el'])) ?> h (Argentina)
                </div></div>
                <div><div class="k">Duración</div><div class="v"><?= (int)$tipo['duracion_min'] ?> minutos</div></div>
            </div>

            <?php if (!empty($r['meet_url'])): ?>
                <p style="margin-top:1.4rem">
                    <a class="btn btn-primary btn-block" href="<?= e($r['meet_url']) ?>" target="_blank" rel="noopener">
                        <?= icon('video', 16) ?>Entrar por Google Meet
                    </a>
                </p>
                <p class="muted small" style="text-align:center">El enlace también te llega por correo y queda en tu calendario.</p>
            <?php else: ?>
                <div class="flash flash-info" style="margin-top:1.2rem">
                    Te vamos a mandar el enlace de la videollamada por correo antes de la reunión.
                </div>
            <?php endif; ?>

            <p class="muted small" style="margin-top:1.4rem">
                ¿No podés? <a href="<?= e(url_abs('r/cancelar?t=' . $r['token'])) ?>">Cancelá la reunión acá</a>.
            </p>
        </div>
    </div>
    <p class="muted small" style="text-align:center;margin-top:1rem">© <?= date('Y') ?> INTELLIHELP S.A.</p>
</div>

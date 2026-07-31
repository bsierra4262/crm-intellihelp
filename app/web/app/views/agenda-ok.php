<?php /** @var array $r @var array $tipo */ ?>
<div class="pub-wrap angosta">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div>
                <h1>¡Listo, quedó agendada!</h1>
                <p class="muted small m-0">Te mandamos la confirmación por correo</p>
            </div>
        </div>
        <div class="pub-pad">
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
                <p class="mt-6">
                    <a class="btn btn-primary btn-block" href="<?= e($r['meet_url']) ?>" target="_blank" rel="noopener">
                        <?= icon('video', 16) ?>Entrar por Google Meet
                    </a>
                </p>
                <p class="muted small centro">El enlace también te llega por correo y queda en tu calendario.</p>
            <?php elseif (!empty($tipo['con_meet'])): ?>
                <?php /* La reunión quedó tomada, pero el enlace de la videollamada
                         no se pudo crear. Antes se prometía igual —«te lo mandamos
                         por correo»— y no llegaba nunca. Se dice lo que realmente
                         va a pasar: alguien lo manda a mano. */ ?>
                <div class="flash flash-warn mt-5">
                    El horario ya quedó reservado. El enlace de la videollamada te lo
                    mandamos por correo apenas lo tengamos, antes de la reunión.
                </div>
            <?php else: ?>
                <div class="flash flash-info mt-5">
                    Te vamos a escribir por correo para confirmar cómo nos conectamos.
                </div>
            <?php endif; ?>

            <p class="muted small mt-6">
                ¿No podés? <a href="<?= e(url_abs('r/cancelar?t=' . $r['token'])) ?>">Cancelá la reunión acá</a>.
            </p>
        </div>
    </div>
    <p class="muted small pub-foot">© <?= date('Y') ?> INTELLIHELP S.A.</p>
</div>

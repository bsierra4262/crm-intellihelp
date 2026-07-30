<?php
/** @var array $tipo @var array $huecos @var string $diaSel @var bool $conectado @var int $campana */
$dias = array_keys($huecos);
$slotsDelDia = $huecos[$diaSel] ?? [];
$nombresDia  = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$meses       = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
?>
<div class="pub-wrap">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div>
                <h1 style="font-size:20px;margin:0"><?= e($tipo['nombre']) ?></h1>
                <p class="muted small" style="margin:0">con <?= e($tipo['usuario']) ?> · INTELLIHELP</p>
            </div>
        </div>

        <div class="pub-body">
            <div class="pub-info">
                <p class="urow" style="gap:.5rem;color:var(--muted);font-size:13.5px">
                    <?= icon('reloj', 16) ?><?= (int)$tipo['duracion_min'] ?> minutos
                </p>
                <?php if ($tipo['con_meet']): ?>
                <p class="urow" style="gap:.5rem;color:var(--muted);font-size:13.5px">
                    <?= icon('video', 16) ?>Videollamada por Google Meet
                </p>
                <?php endif; ?>
                <p class="urow" style="gap:.5rem;color:var(--muted);font-size:13.5px">
                    <?= icon('agenda', 16) ?>Hora de Argentina (UTC−3)
                </p>
                <?php if (trim((string)$tipo['descripcion']) !== ''): ?>
                    <p style="margin-top:1rem;white-space:pre-wrap"><?= e($tipo['descripcion']) ?></p>
                <?php endif; ?>

                <?php if (!$conectado): ?>
                    <div class="flash flash-warn" style="margin-top:1.2rem">
                        La agenda no está sincronizada en este momento. Podés reservar igual y te
                        confirmamos por correo.
                    </div>
                <?php endif; ?>
            </div>

            <div class="pub-slots">
                <?php if (!$dias): ?>
                    <div class="empty-state" style="padding:2rem 0">
                        <?= icon('agenda', 34) ?>
                        <h2 style="font-size:16px">No hay horarios disponibles</h2>
                        <p class="small">Escribinos y coordinamos a mano.</p>
                    </div>
                <?php else: ?>
                    <div class="slot-dias">
                        <?php foreach ($dias as $d): $t = strtotime($d); ?>
                            <a class="slot-dia <?= $d === $diaSel ? 'active' : '' ?>"
                               href="?dia=<?= e($d) ?><?= $campana ? '&c=' . $campana : '' ?>">
                                <?= e($nombresDia[(int)date('w', $t)]) ?>
                                <strong><?= (int)date('j', $t) ?></strong>
                                <?= e($meses[(int)date('n', $t)]) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <h2 style="margin-bottom:.7rem"><?= e(ucfirst(fecha_larga($diaSel))) ?></h2>
                    <div class="slot-grid" id="slots">
                        <?php foreach ($slotsDelDia as $ts): ?>
                            <button type="button" class="slot" data-ts="<?= (int)$ts ?>"><?= date('H:i', $ts) ?></button>
                        <?php endforeach; ?>
                        <?php if (!$slotsDelDia): ?>
                            <p class="muted small">No quedan horarios ese día. Probá con otro.</p>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="<?= e(url('reservar')) ?>" id="formReserva" hidden style="margin-top:1.2rem">
                        <input type="hidden" name="tipo_id" value="<?= (int)$tipo['id'] ?>">
                        <input type="hidden" name="inicio" id="inputInicio" value="">
                        <input type="hidden" name="campana_id" value="<?= $campana ?: '' ?>">
                        <div class="flash flash-info" id="avisoHorario"></div>
                        <label class="fld"><span>Nombre y apellido <i class="req">*</i></span>
                            <input name="nombre" maxlength="160" required></label>
                        <label class="fld" style="margin-top:.7rem"><span>Correo <i class="req">*</i></span>
                            <input type="email" name="email" maxlength="190" required></label>
                        <label class="fld" style="margin-top:.7rem"><span>Empresa</span>
                            <input name="empresa" maxlength="200"></label>
                        <label class="fld" style="margin-top:.7rem"><span>Teléfono</span>
                            <input name="telefono" maxlength="60"></label>
                        <label class="fld" style="margin-top:.7rem"><span>¿De qué querés que hablemos?</span>
                            <textarea name="comentario" rows="3"></textarea></label>
                        <button class="btn btn-primary btn-block" style="margin-top:1rem">Confirmar la reunión</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <p class="muted small" style="text-align:center;margin-top:1rem">© <?= date('Y') ?> INTELLIHELP S.A.</p>
</div>

<script>
// Elegir horario: se marca el slot y aparece el formulario, sin recargar.
(function () {
    var form = document.getElementById('formReserva');
    var campo = document.getElementById('inputInicio');
    var aviso = document.getElementById('avisoHorario');
    document.querySelectorAll('.slot').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.slot').forEach(function (x) { x.classList.remove('active'); });
            b.classList.add('active');
            campo.value = b.getAttribute('data-ts');
            aviso.textContent = 'Horario elegido: ' + b.textContent.trim() + ' h';
            form.hidden = false;
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });
})();
</script>

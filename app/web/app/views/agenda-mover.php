<?php
/** @var ?array $r @var ?array $tipo @var array $huecos @var string $diaSel @var ?string $motivo
 *  @var ?string $error @var string $formToken */
$dias        = array_keys($huecos);
$slotsDelDia = $huecos[$diaSel] ?? [];
$nombresDia  = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$meses       = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$correo      = (string) setting_get('empresa_email', 'info@intellihelp.tech');
?>
<div class="pub-wrap">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div>
                <h1>Cambiar el horario</h1>
                <?php if ($r && $tipo): ?>
                    <p class="muted small m-0"><?= e($tipo['nombre']) ?> con <?= e($tipo['usuario']) ?> · INTELLIHELP</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$r || !$tipo): ?>
            <div class="pub-pad"><div class="flash flash-error">El enlace no es válido.</div></div>
        <?php elseif ($r['estado'] !== 'confirmada'): ?>
            <div class="pub-pad">
                <div class="flash flash-info">
                    Esta reunión ya no está activa<?= $r['estado'] === 'cancelada' ? ': se canceló' : '' ?>.
                    Si querés coordinar otra, escribinos a
                    <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a>.
                </div>
            </div>
        <?php else: $actual = agenda_local(strtotime((string)$r['inicia_el']), $tipo); ?>
            <div class="pub-body">
                <div class="pub-info">
                    <p class="small muted m-0">Hoy está agendada para el</p>
                    <p class="mt-3"><strong><?= e(ucfirst(fecha_larga($actual))) ?>, <?= e(substr($actual, 11, 5)) ?> h</strong></p>
                    <p class="urow meta mt-4">
                        <?= icon('reloj', 16) ?><?= (int)$tipo['duracion_min'] ?> minutos
                    </p>
                    <p class="urow meta">
                        <?= icon('agenda', 16) ?><?= e(agenda_zona_etiqueta($tipo)) ?>
                    </p>
                    <p class="small muted mt-4">
                        Elegí el horario nuevo. Te mandamos la invitación actualizada por correo y la
                        reunión se corre sola en tu calendario.
                    </p>
                    <p class="small mt-4">
                        ¿Preferís cancelarla? <a href="<?= e(url_abs('r/cancelar?t=' . $r['token'])) ?>">Cancelá acá</a>.
                    </p>
                </div>

                <div class="pub-slots">
                    <?php if (!empty($error)): ?>
                        <div class="flash flash-error mb-3" role="alert"><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if (!$dias): ?>
                        <div class="empty-state compacto">
                            <?= icon('agenda', 34) ?>
                            <h2><?= ($motivo ?? null) === 'sin_calendario'
                                    ? 'No podemos mostrarte los horarios ahora'
                                    : 'No hay otros horarios disponibles' ?></h2>
                            <p class="small">
                                Escribinos a <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a>
                                y buscamos otro horario a mano. Tu reunión sigue en pie mientras tanto.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="slot-dias">
                            <?php foreach ($dias as $d): $t = strtotime($d); ?>
                                <a class="slot-dia <?= $d === $diaSel ? 'active' : '' ?>"
                                   href="?<?= e(http_build_query(['t' => $r['token'], 'dia' => $d])) ?>">
                                    <?= e($nombresDia[(int)date('w', $t)]) ?>
                                    <strong><?= (int)date('j', $t) ?></strong>
                                    <?= e($meses[(int)date('n', $t)]) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <h2 class="mb-3"><?= e(ucfirst(fecha_larga($diaSel))) ?></h2>
                        <div class="slot-grid">
                            <?php foreach ($slotsDelDia as $ts): ?>
                                <button type="button" class="slot" data-ts="<?= (int)$ts ?>" aria-pressed="false"><?= e(agenda_local($ts, $tipo, 'H:i')) ?></button>
                            <?php endforeach; ?>
                            <?php if (!$slotsDelDia): ?>
                                <p class="muted small">No quedan horarios ese día. Probá con otro.</p>
                            <?php endif; ?>
                        </div>

                        <form method="post" action="<?= e(url('r/mover?t=' . $r['token'])) ?>" id="formMover" hidden class="mt-5">
                            <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
                            <input type="hidden" name="inicio" id="inputInicio" value="">
                            <div class="flash flash-info" id="avisoHorario"></div>
                            <button class="btn btn-primary btn-block mt-4">Cambiar al horario elegido</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <p class="muted small pub-foot">© <?= date('Y') ?> INTELLIHELP S.A.</p>
</div>

<script>
// Elegir el horario nuevo: se marca el slot y aparece el botón para confirmar.
(function () {
    var form = document.getElementById('formMover');
    if (!form) { return; }
    var campo = document.getElementById('inputInicio');
    var aviso = document.getElementById('avisoHorario');
    document.querySelectorAll('.slot').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.slot').forEach(function (x) {
                x.classList.remove('active');
                x.setAttribute('aria-pressed', 'false');
            });
            b.classList.add('active');
            b.setAttribute('aria-pressed', 'true');
            campo.value = b.getAttribute('data-ts');
            aviso.textContent = 'Horario nuevo: ' + b.textContent.trim() + ' h';
            form.hidden = false;
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });
})();
</script>

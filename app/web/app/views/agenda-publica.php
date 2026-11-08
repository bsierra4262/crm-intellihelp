<?php
/** @var array $tipo @var array $huecos @var string $diaSel @var bool $conectado
 *  @var int $campana @var ?string $motivo @var array $origen @var string $formToken
 *  @var ?string $error @var ?string $campo @var array $viejo */
$dias = array_keys($huecos);
$slotsDelDia = $huecos[$diaSel] ?? [];
$nombresDia  = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$meses       = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$viejo       = $viejo ?? [];
$origen      = $origen ?? [];
$v           = static fn(string $k): string => e($viejo[$k] ?? '');
$elegido     = (int)($viejo['inicio'] ?? 0);
if (!in_array($elegido, $slotsDelDia, true)) { $elegido = 0; }

// Al cambiar de día se conserva de dónde vino el visitante (campaña, UTM y
// el sitio que lo mandó): si no, se perdía con el primer clic.
$conservar = array_filter([
    'c'            => $campana ?: null,
    'utm_source'   => $origen['utm_source'] ?? null,
    'utm_medium'   => $origen['utm_medium'] ?? null,
    'utm_campaign' => $origen['utm_campaign'] ?? null,
    'utm_term'     => $origen['utm_term'] ?? null,
    'utm_content'  => $origen['utm_content'] ?? null,
    'ref'          => $origen['referrer'] ?? null,
]);
$privacidad = agenda_privacidad_url();
?>
<div class="pub-wrap">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div>
                <h1><?= e($tipo['nombre']) ?></h1>
                <p class="muted small m-0">con <?= e($tipo['usuario']) ?> · INTELLIHELP</p>
            </div>
        </div>

        <div class="pub-body">
            <div class="pub-info">
                <p class="urow meta">
                    <?= icon('reloj', 16) ?><?= (int)$tipo['duracion_min'] ?> minutos
                </p>
                <?php if ($tipo['con_meet']): ?>
                <p class="urow meta">
                    <?= icon('video', 16) ?>Videollamada por Google Meet
                </p>
                <?php endif; ?>
                <p class="urow meta">
                    <?= icon('agenda', 16) ?><?= e(agenda_zona_etiqueta($tipo)) ?>
                </p>
                <?php if (trim((string)$tipo['descripcion']) !== ''): ?>
                    <p class="mt-4 pre-wrap"><?= e($tipo['descripcion']) ?></p>
                <?php endif; ?>

                <?php if (!$conectado): ?>
                    <div class="flash flash-warn mt-5">
                        La agenda no está sincronizada en este momento. Escribinos y coordinamos
                        la reunión por correo.
                    </div>
                <?php endif; ?>
            </div>

            <div class="pub-slots">
                <?php if (!empty($error)): ?>
                    <div class="flash flash-error mb-3" role="alert"><?= e($error) ?></div>
                <?php endif; ?>

                <?php if (!$dias && ($motivo ?? null) === 'sin_calendario'): ?>
                    <?php /* Sin lectura del calendario no se ofrece ningún
                             horario, para no encimar reuniones. Al visitante se
                             le da una salida, no un error. */ ?>
                    <div class="empty-state compacto">
                        <?= icon('agenda', 34) ?>
                        <h2>No podemos mostrarte los horarios ahora</h2>
                        <p class="small">
                            Es un problema nuestro, no tuyo. Escribinos a
                            <a href="mailto:<?= e(setting_get('empresa_email', 'info@intellihelp.tech')) ?>"><?= e(setting_get('empresa_email', 'info@intellihelp.tech')) ?></a>
                            y coordinamos la reunión a mano.
                        </p>
                    </div>
                <?php elseif (!$dias): ?>
                    <div class="empty-state compacto">
                        <?= icon('agenda', 34) ?>
                        <h2>No hay horarios disponibles</h2>
                        <p class="small">
                            Se llenó la agenda de los próximos días. Escribinos a
                            <a href="mailto:<?= e(setting_get('empresa_email', 'info@intellihelp.tech')) ?>"><?= e(setting_get('empresa_email', 'info@intellihelp.tech')) ?></a>
                            y coordinamos a mano.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="slot-dias">
                        <?php foreach ($dias as $d): $t = strtotime($d); ?>
                            <a class="slot-dia <?= $d === $diaSel ? 'active' : '' ?>"
                               href="?<?= e(http_build_query(['dia' => $d] + $conservar)) ?>">
                                <?= e($nombresDia[(int)date('w', $t)]) ?>
                                <strong><?= (int)date('j', $t) ?></strong>
                                <?= e($meses[(int)date('n', $t)]) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <h2 class="mb-3"><?= e(ucfirst(fecha_larga($diaSel))) ?></h2>
                    <div class="slot-grid" id="slots">
                        <?php foreach ($slotsDelDia as $ts): ?>
                            <button type="button" class="slot <?= $ts === $elegido ? 'active' : '' ?>"
                                    data-ts="<?= (int)$ts ?>"
                                    aria-pressed="<?= $ts === $elegido ? 'true' : 'false' ?>"><?= e(agenda_local($ts, $tipo, 'H:i')) ?></button>
                        <?php endforeach; ?>
                        <?php if (!$slotsDelDia): ?>
                            <p class="muted small">No quedan horarios ese día. Probá con otro.</p>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="<?= e(url('reservar')) ?>" id="formReserva" <?= $elegido ? '' : 'hidden' ?> class="mt-5">
                        <input type="hidden" name="tipo_id" value="<?= (int)$tipo['id'] ?>">
                        <input type="hidden" name="inicio" id="inputInicio" value="<?= $elegido ?: '' ?>">
                        <input type="hidden" name="campana_id" value="<?= $campana ?: '' ?>">
                        <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
                        <?php foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer'] as $k): ?>
                            <?php if (!empty($origen[$k])): ?>
                                <input type="hidden" name="<?= e($k) ?>" value="<?= e($origen[$k]) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php /* Campo trampa: una persona no lo ve ni llega con el
                                 tabulador; un programa que llena todo, sí. */ ?>
                        <div class="pub-trampa" hidden aria-hidden="true">
                            <label>No completar este campo
                                <input type="text" name="sitio_web" value="" tabindex="-1" autocomplete="off">
                            </label>
                        </div>

                        <div class="flash flash-info" id="avisoHorario"><?= $elegido ? 'Horario elegido: ' . e(agenda_local($elegido, $tipo, 'H:i')) . ' h' : '' ?></div>
                        <label class="fld"><span>Nombre y apellido <i class="req">*</i></span>
                            <input name="nombre" maxlength="120" required placeholder="Juan Perez" value="<?= $v('nombre') ?>"
                                   autocomplete="name" <?= ($campo ?? '') === 'nombre' ? 'aria-invalid="true" autofocus' : '' ?>>
                            <i class="hint">Como querés que te llamemos en la reunión.</i>
                        </label>
                        <label class="fld mt-3"><span>Correo <i class="req">*</i></span>
                            <input type="email" name="email" maxlength="190" required placeholder="juan.perez@empresa.com" value="<?= $v('email') ?>"
                                   autocomplete="email" <?= ($campo ?? '') === 'email' ? 'aria-invalid="true" autofocus' : '' ?>>
                            <i class="hint">Ahí te llega la confirmación y el enlace de la videollamada.</i>
                        </label>
                        <label class="fld mt-3"><span>Empresa</span>
                            <input name="empresa" maxlength="200" placeholder="Distribuidora del Sur S.A." value="<?= $v('empresa') ?>"
                                   autocomplete="organization">
                            <i class="hint">Nos ayuda a llegar preparados a la reunión.</i>
                        </label>
                        <label class="fld mt-3"><span>Teléfono</span>
                            <input type="tel" name="telefono" maxlength="60" inputmode="tel" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios." placeholder="+54 9 11 5162-9038" value="<?= $v('telefono') ?>"
                                   autocomplete="tel" <?= ($campo ?? '') === 'telefono' ? 'aria-invalid="true" autofocus' : '' ?>>
                            <i class="hint">Por si necesitamos avisarte algo a último momento. Sólo números.</i>
                        </label>
                        <label class="fld mt-3"><span>¿De qué querés que hablemos?</span>
                            <textarea name="comentario" rows="3" maxlength="3000" placeholder="Necesitamos renovar los equipos de la oficina."><?= $v('comentario') ?></textarea>
                            <i class="hint">Contanos qué necesitás y no arrancamos de cero.</i>
                        </label>
                        <?php /* Consentimiento expreso (Ley 25.326, art. 5): sin marcar
                                 por defecto y obligatorio. Se guarda la fecha y la
                                 versión del texto. */ ?>
                        <label class="urow mt-4 small">
                            <input type="checkbox" name="acepto" value="1" required <?= !empty($viejo['acepto']) ? 'checked' : '' ?>
                                   <?= ($campo ?? '') === 'acepto' ? 'aria-invalid="true" autofocus' : '' ?>>
                            <span><?= e(AGENDA_CONSENTIMIENTO_TEXTO) ?>
                                <a href="<?= e($privacidad) ?>" target="_blank" rel="noopener">Ver el aviso de privacidad</a>.</span>
                        </label>
                        <button class="btn btn-primary btn-block mt-4">Confirmar la reunión</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <p class="muted small pub-foot">© <?= date('Y') ?> INTELLIHELP S.A.</p>
</div>

<script>
// Elegir horario: se marca el slot y aparece el formulario, sin recargar.
(function () {
    var form = document.getElementById('formReserva');
    var campo = document.getElementById('inputInicio');
    var aviso = document.getElementById('avisoHorario');
    if (!form) { return; }
    document.querySelectorAll('.slot').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.slot').forEach(function (x) {
                x.classList.remove('active');
                x.setAttribute('aria-pressed', 'false');
            });
            b.classList.add('active');
            b.setAttribute('aria-pressed', 'true');
            campo.value = b.getAttribute('data-ts');
            aviso.textContent = 'Horario elegido: ' + b.textContent.trim() + ' h';
            form.hidden = false;
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });
})();
</script>

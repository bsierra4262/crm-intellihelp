<?php
/** @var array $tipo @var array $huecos @var string $diaSel @var bool $conectado
 *  @var int $campana @var ?string $motivo @var array $origen @var string $formToken
 *  @var ?string $error @var ?string $campo @var array $viejo */
// ============================================================
//  Página pública de reserva /r/<usuario>/<tipo> (sin login). La arma
//  agenda_render_publica() (agenda.php), llamada desde pages/r.php y, para
//  volver a mostrarla con un aviso, desde pages/reservar.php.
//
//  Se ve IGUAL que el bloque de reserva de intellihelp.tech/diagnostico-sin-cargo/
//  (mismas clases y estilos, copiados en assets/css/reserva.css): columna carbón
//  con los datos de la reunión, pasos 01-02-03, calendario mensual, horarios en
//  tres columnas y el formulario con «¿Cómo nos conociste?».
//
//  Recibe:
//    · $tipo ....... tipo de reunión con `usuario` (nombre del dueño)
//    · $huecos ..... fecha Y-m-d => inicios en segundos Unix (ya sin feriados)
//    · $diaSel ..... el día que se muestra (uno de los de $huecos, o '')
//    · $conectado .. si el dueño tiene Google conectado
//    · $motivo ..... 'sin_calendario' | 'sin_horario' | null
//    · $campana .... id de campaña de correo (viene en ?c=), 0 si no hay
//    · $origen ..... UTM y referrer, que viajan en campos ocultos
//    · $formToken .. token firmado de un solo uso ('reservar')
//    · $error, $campo, $viejo: aviso, campo con el problema y lo ya escrito
//
//  Funciona sin JavaScript: el mes y los días son enlaces (?dia=), los
//  horarios son botones que eligen y el formulario se manda a `reservar`
//  (pages/reservar.php) con el horario en `inicio` (segundos Unix; el servidor
//  vuelve a comprobar que sea uno de los ofrecidos). Con JavaScript (al pie),
//  todo pasa sin recargar, como en la web.
// ============================================================
$dias        = array_keys($huecos);
$slotsDelDia = $huecos[$diaSel] ?? [];
$viejo       = $viejo ?? [];
$origen      = $origen ?? [];
$v           = static fn(string $k): string => e($viejo[$k] ?? '');
$elegido     = (int)($viejo['inicio'] ?? 0);
if (!in_array($elegido, $slotsDelDia, true)) { $elegido = 0; }
// Con un error al confirmar se vuelve directo al paso de los datos.
$enDatos     = $elegido > 0;

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
$correo     = (string) setting_get('empresa_email', 'info@intellihelp.tech');
$whatsapp   = preg_replace('/\D/', '', (string) setting_get('empresa_whatsapp', '5491150274827'));
$meses      = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$diasLargos = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$hoy        = agenda_local(time(), $tipo, 'Y-m-d');

// Mes que se dibuja sin JavaScript: el del día elegido.
$mesBase = $diaSel !== '' ? substr($diaSel, 0, 7) : substr($hoy, 0, 7);
$t0      = strtotime($mesBase . '-01 12:00:00');
$enMes   = (int) date('t', $t0);
$desfase = ((int) date('N', $t0)) - 1;   // lunes = 0
$mesesCon = array_values(array_unique(array_map(fn($d) => substr($d, 0, 7), $dias)));
$iMes    = array_search($mesBase, $mesesCon, true);
$primerDiaDe = static function (string $ym) use ($dias): ?string {
    foreach ($dias as $d) { if (str_starts_with($d, $ym)) { return $d; } }
    return null;
};
$mesAnt = ($iMes !== false && $iMes > 0) ? $primerDiaDe($mesesCon[$iMes - 1]) : null;
$mesSig = ($iMes !== false && $iMes < count($mesesCon) - 1) ? $primerDiaDe($mesesCon[$iMes + 1]) : null;
$fechaLarga = static function (string $d) use ($meses, $diasLargos): string {
    $t = strtotime($d . ' 12:00:00');
    return $diasLargos[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' de ' . $meses[(int) date('n', $t)];
};

// Horarios para el JavaScript: día => [[inicio, "HH:MM"], …].
$datosJs = [];
foreach ($huecos as $d => $lista) {
    $datosJs[$d] = array_map(fn($ts) => [(int)$ts, agenda_local($ts, $tipo, 'H:i')], $lista);
}
$ico = static fn(string $path, int $t = 20): string =>
    '<svg viewBox="0 0 24 24" width="' . $t . '" height="' . $t . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">' . $path . '</svg>';
?>
<section class="dx-reserva" id="agendar" aria-labelledby="dx-reserva-titulo">
    <div class="dx-reserva__card">
        <div class="dx-reserva__info">
            <p class="dx-reserva__rotulo"><?= e($tipo['nombre']) ?></p>
            <h1 class="dx-reserva__titulo" id="dx-reserva-titulo">Reservá tu reunión</h1>
            <ul class="dx-reserva__datos list-reset">
                <li><span class="dx-reserva__ico"><?= $ico('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>') ?></span><span><?= (int)$tipo['duracion_min'] ?>&nbsp;minutos</span></li>
                <?php if ($tipo['con_meet']): ?>
                <li><span class="dx-reserva__ico"><?= $ico('<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3"/>') ?></span><span>Videollamada por Google Meet</span></li>
                <?php endif; ?>
                <li><span class="dx-reserva__ico"><?= $ico('<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>') ?></span><span>Con <?= e($tipo['usuario']) ?>, de Intellihelp</span></li>
                <li><span class="dx-reserva__ico"><?= $ico('<path d="M20 6 9 17l-5-5"/>') ?></span><span>Sin cargo y sin compromiso</span></li>
            </ul>
        </div>

        <p class="dx-reserva__alt">
            <a class="dx-reserva__alt-btn" href="mailto:<?= e($correo) ?>">¿Preferís que te contactemos? <strong>Escribinos <span aria-hidden="true">→</span></strong></a>
        </p>

        <div class="dx-reserva__main">
            <ol class="dx-pasos list-reset" aria-label="Pasos de la reserva">
                <li class="dx-pasos__item <?= $enDatos ? 'is-hecho' : '' ?>" data-paso-ind="horario" <?= $enDatos ? '' : 'aria-current="step"' ?>><span class="dx-pasos__num">01</span><span class="dx-pasos__txt">Día y horario</span></li>
                <li class="dx-pasos__item" data-paso-ind="datos" <?= $enDatos ? 'aria-current="step"' : '' ?>><span class="dx-pasos__num">02</span><span class="dx-pasos__txt">Tus datos</span></li>
                <li class="dx-pasos__item"><span class="dx-pasos__num">03</span><span class="dx-pasos__txt">Confirmación</span></li>
            </ol>

            <div class="agenda-panel dx-agenda">
              <div class="agenda-crm">
                <?php if (!empty($error)): ?>
                    <div class="agenda-status" role="alert"><?= e($error) ?></div>
                <?php endif; ?>

                <?php if (!$dias): ?>
                    <div class="agenda-crm__paso">
                        <div class="dx-paso-cab">
                            <h2 class="agenda-crm__titulo">
                                <?= ($motivo ?? null) === 'sin_calendario' ? 'No podemos mostrarte los horarios ahora' : 'No hay horarios disponibles' ?>
                            </h2>
                            <p class="agenda-crm__sub">
                                <?= ($motivo ?? null) === 'sin_calendario' ? 'Es un problema nuestro, no tuyo.' : 'Se llenó la agenda de los próximos días.' ?>
                            </p>
                        </div>
                        <p class="agenda-crm__alt">Escribinos por <a href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener">WhatsApp</a>
                            o a <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a> y coordinamos la reunión.</p>
                    </div>
                <?php else: ?>
                <!-- Paso 1: día y horario -->
                <div class="agenda-crm__paso" data-paso="horario" <?= $enDatos ? 'hidden' : '' ?>>
                    <div class="dx-paso-cab">
                        <h2 class="agenda-crm__titulo" tabindex="-1">Elegí día y horario</h2>
                        <p class="agenda-crm__sub"><?= e(agenda_zona_etiqueta($tipo)) ?>. Videollamada de <?= (int)$tipo['duracion_min'] ?>&nbsp;minutos.</p>
                    </div>
                    <div class="dx-horario">
                        <div class="dx-mes" data-mes>
                            <div class="dx-mes__cab">
                                <?php if ($mesAnt): ?>
                                    <a class="dx-mes__flecha" href="?<?= e(http_build_query(['dia' => $mesAnt] + $conservar)) ?>" aria-label="Mes anterior"><?= $ico('<path d="m15 5-7 7 7 7"/>', 18) ?></a>
                                <?php else: ?>
                                    <button type="button" class="dx-mes__flecha" disabled aria-label="Mes anterior"><?= $ico('<path d="m15 5-7 7 7 7"/>', 18) ?></button>
                                <?php endif; ?>
                                <p class="dx-mes__titulo" aria-live="polite"><?= e($meses[(int) date('n', $t0)] . ' de ' . date('Y', $t0)) ?></p>
                                <?php if ($mesSig): ?>
                                    <a class="dx-mes__flecha" href="?<?= e(http_build_query(['dia' => $mesSig] + $conservar)) ?>" aria-label="Mes siguiente"><?= $ico('<path d="m9 5 7 7-7 7"/>', 18) ?></a>
                                <?php else: ?>
                                    <button type="button" class="dx-mes__flecha" disabled aria-label="Mes siguiente"><?= $ico('<path d="m9 5 7 7-7 7"/>', 18) ?></button>
                                <?php endif; ?>
                            </div>
                            <div class="dx-mes__semana" aria-hidden="true"><span>Lu</span><span>Ma</span><span>Mi</span><span>Ju</span><span>Vi</span><span>Sá</span><span>Do</span></div>
                            <div class="dx-mes__grilla">
                                <?php for ($i = 0; $i < $desfase; $i++): ?><span></span><?php endfor; ?>
                                <?php for ($n = 1; $n <= $enMes; $n++):
                                    $f = $mesBase . '-' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
                                    $cls = 'dx-mes__dia' . ($f === $hoy ? ' is-hoy' : ''); ?>
                                    <?php if (isset($huecos[$f])): ?>
                                        <a class="<?= $cls ?> is-libre" href="?<?= e(http_build_query(['dia' => $f] + $conservar)) ?>"
                                           aria-pressed="<?= $f === $diaSel ? 'true' : 'false' ?>" aria-label="<?= e(ucfirst($fechaLarga($f))) ?>"><?= $n ?></a>
                                    <?php else: ?>
                                        <span class="<?= $cls ?>"><?= $n ?></span>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <fieldset class="agenda-crm__grupo dx-horas">
                            <legend class="agenda-crm__legend" data-legend>Horarios del <?= e($fechaLarga($diaSel)) ?></legend>
                            <div class="agenda-crm__horas" data-horas>
                                <?php foreach ($slotsDelDia as $ts): ?>
                                    <button type="button" class="agenda-crm__hora" data-ts="<?= (int)$ts ?>"
                                            aria-pressed="<?= $ts === $elegido ? 'true' : 'false' ?>"><?= e(agenda_local($ts, $tipo, 'H:i')) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    </div>
                    <p class="agenda-crm__alt">Si ningún horario te resulta conveniente, escribinos por
                        <a href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener">WhatsApp<span class="sr-only"> (se abre en una pestaña nueva)</span></a>
                        o a <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a> y coordinamos otra fecha.</p>
                </div>

                <!-- Paso 2: datos -->
                <form class="agenda-form" method="post" action="<?= e(url('reservar')) ?>" data-form <?= $enDatos ? '' : 'hidden' ?>>
                    <div class="agenda-crm__elegido">
                        <span class="dx-elegido__ico"><?= $ico('<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>', 22) ?></span>
                        <p class="agenda-crm__elegido-texto"><span>Tu reunión</span>
                            <strong data-elegido><?= $elegido ? e(ucfirst($fechaLarga(agenda_local($elegido, $tipo, 'Y-m-d'))) . ' a las ' . agenda_local($elegido, $tipo, 'H:i') . ' h') : '' ?></strong></p>
                        <button type="button" class="agenda-crm__cambiar" data-cambiar>Cambiar</button>
                    </div>

                    <input type="hidden" name="tipo_id" value="<?= (int)$tipo['id'] ?>">
                    <input type="hidden" name="inicio" value="<?= $elegido ?: '' ?>" data-inicio>
                    <input type="hidden" name="campana_id" value="<?= $campana ?: '' ?>">
                    <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
                    <?php foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer'] as $k): ?>
                        <?php if (!empty($origen[$k])): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($origen[$k]) ?>"><?php endif; ?>
                    <?php endforeach; ?>

                    <div class="agenda-form__fila">
                        <div class="agenda-form__campo">
                            <label class="agenda-form__label" for="ag-nombre">Nombre y apellido</label>
                            <input class="agenda-form__input" id="ag-nombre" name="nombre" type="text" autocomplete="name" maxlength="120" placeholder="Tu nombre y apellido" required value="<?= $v('nombre') ?>" <?= ($campo ?? '') === 'nombre' ? 'aria-invalid="true" autofocus' : '' ?>>
                        </div>
                        <div class="agenda-form__campo">
                            <label class="agenda-form__label" for="ag-empresa">Empresa u organismo</label>
                            <input class="agenda-form__input" id="ag-empresa" name="empresa" type="text" autocomplete="organization" maxlength="200" placeholder="Dónde trabajás" required value="<?= $v('empresa') ?>">
                        </div>
                    </div>
                    <div class="agenda-form__fila">
                        <div class="agenda-form__campo">
                            <label class="agenda-form__label" for="ag-mail">Mail laboral</label>
                            <input class="agenda-form__input" id="ag-mail" name="email" type="email" inputmode="email" autocomplete="email" autocapitalize="off" spellcheck="false" maxlength="190" placeholder="nombre@empresa.com" required value="<?= $v('email') ?>" <?= ($campo ?? '') === 'email' ? 'aria-invalid="true" autofocus' : '' ?>>
                        </div>
                        <div class="agenda-form__campo">
                            <label class="agenda-form__label" for="ag-telefono">WhatsApp (con código de país)</label>
                            <input class="agenda-form__input" id="ag-telefono" name="telefono" type="tel" inputmode="tel" autocomplete="tel" maxlength="60" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios." placeholder="+54 9 11 1234 5678" required value="<?= $v('telefono') ?>" <?= ($campo ?? '') === 'telefono' ? 'aria-invalid="true" autofocus' : '' ?>>
                        </div>
                    </div>

                    <fieldset class="dx-chips">
                        <legend class="agenda-form__label">¿Cómo nos conociste? <span class="agenda-form__opc">(opcional)</span></legend>
                        <div class="dx-chips__lista">
                            <?php foreach (['LinkedIn', 'Google', 'ChatGPT u otro asistente', 'YouTube', 'Instagram', 'Recomendación', 'Ya somos clientes', 'Otro'] as $op): ?>
                                <label class="dx-chip"><input type="radio" name="como_nos_conociste" value="<?= e($op) ?>" <?= ($viejo['como_nos_conociste'] ?? '') === $op ? 'checked' : '' ?>><span><?= e($op) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <?php /* Campo trampa: una persona no lo ve ni llega con el tabulador. */ ?>
                    <div class="agenda-form__hp" aria-hidden="true" hidden>
                        <label for="ag-website">No completar</label>
                        <input id="ag-website" name="sitio_web" type="text" tabindex="-1" autocomplete="off">
                    </div>

                    <?php /* Consentimiento expreso (Ley 25.326, art. 5): sin marcar y obligatorio. */ ?>
                    <div class="agenda-form__check dx-check">
                        <input class="dx-check__input" id="ag-acepto" name="acepto" type="checkbox" value="1" required <?= !empty($viejo['acepto']) ? 'checked' : '' ?> <?= ($campo ?? '') === 'acepto' ? 'aria-invalid="true" autofocus' : '' ?>>
                        <label for="ag-acepto"><span class="dx-check__caja" aria-hidden="true"><?= $ico('<path d="M20 6 9 17l-5-5"/>', 14) ?></span><span><?php
                            // El texto es el legal (se guarda su versión y su hash): sólo se convierte en enlace
                            // «aviso de privacidad», como en la web. Si el texto no lo nombra, el enlace va al final.
                            $txt = e(AGENDA_CONSENTIMIENTO_TEXTO);
                            $lnk = '<a href="' . e($privacidad) . '" target="_blank" rel="noopener">aviso de privacidad</a>';
                            $pos = mb_strrpos($txt, 'aviso de privacidad');
                            echo $pos !== false ? mb_substr($txt, 0, $pos) . $lnk . mb_substr($txt, $pos + 19)
                                                : $txt . ' <a href="' . e($privacidad) . '" target="_blank" rel="noopener">Ver el aviso de privacidad</a>.';
                        ?></span></label>
                    </div>

                    <div class="dx-enviar">
                        <button type="submit" class="btn btn-violeta agenda-form__enviar">Confirmar reunión <span class="arr" aria-hidden="true">→</span></button>
                        <p class="agenda-form__legal">Usamos tus datos solo para coordinar esta reunión. No los compartimos ni los incorporamos a listas de correo.</p>
                    </div>
                </form>
                <?php endif; ?>
              </div>
            </div>
        </div>
    </div>
</section>
<p class="reserva-pie">© <?= date('Y') ?> INTELLIHELP S.A.</p>

<?php if ($dias): ?>
<script type="application/json" id="reserva-datos"><?= json_encode(['huecos' => $datosJs, 'dia' => $diaSel, 'hoy' => $hoy],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
// Con JavaScript, la reserva funciona como en la web: el calendario cambia de
// mes y de día sin recargar, al tocar un horario se pasa a «Tus datos» y
// «Cambiar» vuelve al paso 1. Sin JavaScript, todo lo de arriba sigue andando
// con enlaces y el formulario normal.
(function () {
    var datos = JSON.parse(document.getElementById('reserva-datos').textContent);
    var H = datos.huecos, dias = Object.keys(H).sort();
    var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    var DIAS = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    var raiz = document.querySelector('.dx-reserva');
    var mes = raiz.querySelector('[data-mes]'), horas = raiz.querySelector('[data-horas]');
    var legend = raiz.querySelector('[data-legend]');
    var paso1 = raiz.querySelector('[data-paso="horario"]'), form = raiz.querySelector('[data-form]');
    var inicio = form.querySelector('[data-inicio]'), elegido = form.querySelector('[data-elegido]');
    var ind1 = raiz.querySelector('[data-paso-ind="horario"]'), ind2 = raiz.querySelector('[data-paso-ind="datos"]');
    var diaSel = datos.dia || dias[0], verMes = diaSel.slice(0, 7);
    var meses = dias.map(function (d) { return d.slice(0, 7); }).filter(function (m, i, a) { return a.indexOf(m) === i; });

    function fecha(d) { var p = d.split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); }
    function largo(d) { var f = fecha(d); return DIAS[f.getDay()] + ' ' + f.getDate() + ' de ' + MESES[f.getMonth()]; }
    function svg(p) { return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>'; }

    function pintarMes() {
        var f = fecha(verMes + '-01'), i = meses.indexOf(verMes);
        var html = '<div class="dx-mes__cab">'
            + '<button type="button" class="dx-mes__flecha" data-mover="-1" aria-label="Mes anterior"' + (i > 0 ? '' : ' disabled') + '>' + svg('<path d="m15 5-7 7 7 7"/>') + '</button>'
            + '<p class="dx-mes__titulo" aria-live="polite">' + MESES[f.getMonth()] + ' de ' + f.getFullYear() + '</p>'
            + '<button type="button" class="dx-mes__flecha" data-mover="1" aria-label="Mes siguiente"' + (i < meses.length - 1 ? '' : ' disabled') + '>' + svg('<path d="m9 5 7 7-7 7"/>') + '</button></div>'
            + '<div class="dx-mes__semana" aria-hidden="true"><span>Lu</span><span>Ma</span><span>Mi</span><span>Ju</span><span>Vi</span><span>Sá</span><span>Do</span></div><div class="dx-mes__grilla">';
        var desfase = (f.getDay() + 6) % 7, total = new Date(f.getFullYear(), f.getMonth() + 1, 0).getDate();
        for (var k = 0; k < desfase; k++) { html += '<span></span>'; }
        for (var n = 1; n <= total; n++) {
            var d = verMes + '-' + (n < 10 ? '0' : '') + n, cls = 'dx-mes__dia' + (d === datos.hoy ? ' is-hoy' : '');
            html += H[d] ? '<button type="button" class="' + cls + '" data-dia="' + d + '" aria-pressed="' + (d === diaSel) + '" aria-label="' + largo(d) + '">' + n + '</button>'
                         : '<span class="' + cls + '">' + n + '</span>';
        }
        mes.innerHTML = html + '</div>';
    }
    function pintarHoras() {
        legend.textContent = 'Horarios del ' + largo(diaSel);
        horas.innerHTML = (H[diaSel] || []).map(function (h) {
            return '<button type="button" class="agenda-crm__hora" data-ts="' + h[0] + '" aria-pressed="' + (String(h[0]) === inicio.value) + '">' + h[1] + '</button>';
        }).join('');
    }
    function paso(n) {
        paso1.hidden = n !== 1; form.hidden = n !== 2;
        ind1.classList.toggle('is-hecho', n === 2);
        if (n === 1) { ind1.setAttribute('aria-current', 'step'); ind2.removeAttribute('aria-current'); }
        else { ind2.setAttribute('aria-current', 'step'); ind1.removeAttribute('aria-current'); }
        raiz.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    mes.addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b || b.disabled) { return; }
        if (b.hasAttribute('data-mover')) {
            verMes = meses[meses.indexOf(verMes) + (+b.getAttribute('data-mover'))];
            if (!H[diaSel] || diaSel.slice(0, 7) !== verMes) { diaSel = dias.filter(function (d) { return d.slice(0, 7) === verMes; })[0]; pintarHoras(); }
            pintarMes();
        } else if (b.hasAttribute('data-dia')) {
            diaSel = b.getAttribute('data-dia'); pintarMes(); pintarHoras();
        }
    });
    horas.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-ts]');
        if (!b) { return; }
        inicio.value = b.getAttribute('data-ts');
        var t = largo(diaSel);
        elegido.textContent = t.charAt(0).toUpperCase() + t.slice(1) + ' a las ' + b.textContent.trim() + ' h';
        pintarHoras(); paso(2);
        var n = form.querySelector('#ag-nombre'); if (n) { n.focus({ preventScroll: true }); }
    });
    form.querySelector('[data-cambiar]').addEventListener('click', function () { paso(1); });
    // Doble clic en «Confirmar»: el segundo no manda otra reserva.
    form.addEventListener('submit', function () {
        var b = form.querySelector('button[type="submit"]'); b.disabled = true; b.firstChild.textContent = 'Confirmando… ';
    });
    pintarMes(); pintarHoras();
})();
</script>
<?php endif; ?>

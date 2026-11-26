<?php /** @var array $r @var array $tipo @var bool $movida */
// ============================================================
//  Página pública «quedó agendada» / «cambiamos el horario» (sin login).
//  La dibujan pages/reservar.php (reserva nueva) y pages/r.php (/r/mover,
//  con $movida = true).
//
//  Recibe: $r (la reserva vigente, ya completada con agenda_reserva_completar()
//  cuando es nueva: puede traer meet_url), $tipo (con quien la atiende) y
//  $movida (opcional).
//
//  Mismo diseño que la reserva (views/agenda-publica.php y reserva.css, copia
//  del bloque de la web), con el paso 03 «Confirmación» marcado.
//  La hora se muestra en la zona del tipo. Los enlaces de mover y cancelar
//  llevan el token de la reserva: quien ve esta página es quien la reservó.
// ============================================================
$movida = !empty($movida);
$local  = agenda_local(strtotime((string)$r['inicia_el']), $tipo);
$meses  = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$dias   = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$t      = strtotime(substr($local, 0, 10) . ' 12:00:00');
$cuando = $dias[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' de ' . $meses[(int) date('n', $t)] . ' a las ' . substr($local, 11, 5) . ' h';
$ico = static fn(string $path, int $tam = 20, string $grosor = '1.8'): string =>
    '<svg viewBox="0 0 24 24" width="' . $tam . '" height="' . $tam . '" fill="none" stroke="currentColor" stroke-width="' . $grosor . '" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">' . $path . '</svg>';
?>
<section class="dx-reserva" aria-labelledby="ag-ok-titulo">
    <div class="dx-reserva__card">
        <div class="dx-reserva__info">
            <p class="dx-reserva__rotulo"><?= e($tipo['nombre']) ?></p>
            <p class="dx-reserva__titulo"><?= $movida ? 'Horario cambiado' : 'Reunión confirmada' ?></p>
            <ul class="dx-reserva__datos list-reset">
                <li><span class="dx-reserva__ico"><?= $ico('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>') ?></span><span><?= (int)$tipo['duracion_min'] ?>&nbsp;minutos</span></li>
                <?php if ($tipo['con_meet']): ?>
                <li><span class="dx-reserva__ico"><?= $ico('<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3"/>') ?></span><span>Videollamada por Google Meet</span></li>
                <?php endif; ?>
                <li><span class="dx-reserva__ico"><?= $ico('<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>') ?></span><span>Con <?= e($tipo['usuario']) ?>, de Intellihelp</span></li>
                <li><span class="dx-reserva__ico"><?= $ico('<path d="M20 6 9 17l-5-5"/>') ?></span><span>Sin cargo y sin compromiso</span></li>
            </ul>
        </div>
        <p class="dx-reserva__alt" hidden></p>

        <div class="dx-reserva__main">
            <ol class="dx-pasos list-reset" aria-label="Pasos de la reserva">
                <li class="dx-pasos__item is-hecho"><span class="dx-pasos__num">01</span><span class="dx-pasos__txt">Día y horario</span></li>
                <li class="dx-pasos__item is-hecho"><span class="dx-pasos__num">02</span><span class="dx-pasos__txt">Tus datos</span></li>
                <li class="dx-pasos__item" aria-current="step"><span class="dx-pasos__num">03</span><span class="dx-pasos__txt">Confirmación</span></li>
            </ol>

            <div class="agenda-panel dx-agenda">
                <div class="agenda-done">
                    <span class="dx-ok__ico"><?= $ico('<path d="M20 6 9 17l-5-5"/>', 26, '2.4') ?></span>
                    <h1 class="agenda-done__titulo" id="ag-ok-titulo"><?= $movida ? '¡Listo, cambiamos el horario!' : '¡Listo, quedó agendada!' ?></h1>
                    <p class="agenda-done__texto">
                        La reunión es el <strong><?= e($cuando) ?></strong>
                        (<?= e(mb_strtolower(agenda_zona_etiqueta($tipo))) ?>), con <?= e($tipo['usuario']) ?>.
                        <?= $movida ? 'Te mandamos la invitación nueva por mail: reemplaza a la anterior.'
                                    : 'Te mandamos un mail con la invitación para tu calendario.' ?>
                    </p>

                    <?php // Tres casos: hay Meet; el tipo pide Meet pero no se creó; el tipo no usa Meet. ?>
                    <?php if (!empty($r['meet_url'])): ?>
                        <div class="agenda-done__acciones">
                            <a class="btn btn-violeta" href="<?= e($r['meet_url']) ?>" target="_blank" rel="noopener">Entrar por Google Meet <span class="arr" aria-hidden="true">→</span></a>
                            <span class="agenda-done__links">El enlace también queda en tu mail y en tu calendario.</span>
                        </div>
                    <?php elseif (!empty($tipo['con_meet'])): ?>
                        <?php /* El horario quedó tomado pero el enlace de la videollamada no
                                 se pudo crear: se dice lo que realmente va a pasar. */ ?>
                        <p class="agenda-done__aviso">El horario ya quedó reservado. El enlace de la videollamada te lo
                            mandamos por mail apenas lo tengamos, antes de la reunión.</p>
                    <?php else: ?>
                        <p class="agenda-done__aviso">Te vamos a escribir por mail para confirmar cómo nos conectamos.</p>
                    <?php endif; ?>

                    <p class="agenda-done__links">
                        ¿Necesitás otro horario? <a href="<?= e(url_abs('r/mover?t=' . $r['token'])) ?>">Cambialo acá</a>.
                        ¿No podés? <a href="<?= e(url_abs('r/cancelar?t=' . $r['token'])) ?>">Cancelá la reunión</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
<p class="reserva-pie">© <?= date('Y') ?> INTELLIHELP S.A.</p>

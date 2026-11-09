<?php
/** @var int $anio @var array $filas @var array $afectadas @var string $jurisdiccion @var bool $activo
 *  @var bool $sinTabla @var string $sincronizadoEn @var string $ultimoError */
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$hoy  = date('Y-m-d');
?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Feriados <?= (int)$anio ?></h1>
        <p class="muted">Los días en que la agenda no ofrece horarios, ni en la web ni en el enlace público</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('feriados?anio=' . ($anio - 1))) ?>">← <?= $anio - 1 ?></a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('feriados?anio=' . ($anio + 1))) ?>"><?= $anio + 1 ?> →</a>
    </div>
</div>

<?php if ($sinTabla): ?>
    <div class="flash flash-warn">
        Falta aplicar la migración de feriados en esta instalación. Mientras tanto la agenda funciona
        como siempre, sin descontar feriados.
    </div>
<?php elseif (!$activo): ?>
    <div class="flash flash-warn">
        <strong>La agenda no está respetando los feriados.</strong> Se ofrecen horarios todos los días
        del horario laboral. Se vuelve a encender abajo, en «Cómo se aplican».
    </div>
<?php endif; ?>

<?php if ($afectadas): ?>
    <div class="flash flash-warn">
        <strong>Hay <?= count($afectadas) ?> reunión(es) agendadas en un feriado.</strong>
        Quedaron tomadas de antes de que se declarara el feriado: coordiná con cada invitado si se
        mantiene o se mueve.
        <?php foreach ($afectadas as $r): ?>
            <br>· <?= e(fecha_hora($r['inicia_el'])) ?> · <?= e($r['tipo']) ?> con <?= e($r['invitado_nombre']) ?><?= $r['invitado_empresa'] ? ' (' . e($r['invitado_empresa']) . ')' : '' ?> · atiende <?= e($r['usuario']) ?>
        <?php endforeach; ?>
        <br><a href="<?= e(url('reservas?todos=1')) ?>">Ver las reuniones reservadas</a>
    </div>
<?php endif; ?>

<div class="card table-card mb-4" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Fecha</th><th>Feriado</th><th>Dónde rige</th><th>Origen</th><th>En la agenda</th><th class="acts">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$filas): ?>
                <tr><td colspan="6" class="empty">Todavía no hay feriados cargados para <?= (int)$anio ?>. Si el calendario oficial ya está publicado, se traen solos con «Actualizar ahora».</td></tr>
            <?php else: foreach ($filas as $f):
                $t      = strtotime((string)$f['fecha']);
                $rige   = $f['jurisdiccion'] === 'nacional' || $f['jurisdiccion'] === $jurisdiccion;
                $pasado = (string)$f['fecha'] < $hoy;
            ?>
                <tr>
                    <td data-label="Fecha">
                        <div class="cell-main">
                            <strong><?= date('d/m/Y', $t) ?></strong>
                            <small><?= e(ucfirst($dias[(int)date('w', $t)])) ?></small>
                        </div>
                    </td>
                    <td data-label="Feriado">
                        <div class="cell-main">
                            <strong><?= e($f['nombre']) ?></strong>
                            <small><?= e(FERIADOS_TIPOS[$f['tipo']] ?? $f['tipo']) ?></small>
                        </div>
                    </td>
                    <td data-label="Dónde rige">
                        <?php if ($f['jurisdiccion'] === 'nacional'): ?>
                            Todo el país
                        <?php else: ?>
                            <span class="badge badge-cool">Sólo <?= e(FERIADOS_JURISDICCIONES[$f['jurisdiccion']] ?? $f['jurisdiccion']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Origen">
                        <?php if ($f['origen'] === 'auto'): ?>
                            <span class="badge badge-info">Calendario oficial</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Cargado a mano</span>
                            <?php if (!empty($f['autor'])): ?><small class="muted"><?= e($f['autor']) ?></small><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="En la agenda">
                        <?php if ((int)$f['ignorado']): ?>
                            <span class="badge badge-pend">Se trabaja</span>
                        <?php elseif (!$rige): ?>
                            <span class="badge badge-neutral">No rige acá</span>
                        <?php else: ?>
                            <span class="badge <?= $pasado ? 'badge-neutral' : 'badge-rej' ?>">Sin turnos</span>
                        <?php endif; ?>
                    </td>
                    <td class="acts" data-label="Acciones">
                        <form class="inline-form" method="post" action="<?= e(url('feriados?anio=' . $anio)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                            <?php if ((int)$f['ignorado']): ?>
                                <button class="btn btn-ghost btn-sm" name="accion" value="restituir"><?= icon('refrescar', 15) ?>Volver a feriado</button>
                            <?php elseif ($f['origen'] === 'auto'): ?>
                                <button class="btn btn-ghost btn-sm" name="accion" value="ignorar"
                                        data-confirmar-titulo="Trabajar este feriado"
                                        data-confirmar="La agenda va a ofrecer horarios ese día. La actualización automática no lo vuelve a agregar; si cambiás de idea, lo restituís desde acá."
                                        data-confirmar-ok="Se trabaja"><?= icon('x', 15) ?>Se trabaja</button>
                            <?php else: ?>
                                <button class="btn btn-ghost btn-sm" name="accion" value="ignorar" data-peligro
                                        data-confirmar-titulo="Eliminar feriado"
                                        data-confirmar="Se borra este feriado cargado a mano y la agenda vuelve a ofrecer horarios ese día."
                                        data-confirmar-ok="Eliminar"><?= icon('x', 15) ?>Eliminar</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="rep-grid">
    <div class="card form-card">
        <h2 class="sec">Actualización automática</h2>
        <p class="muted small">
            Todos los días se consulta el calendario oficial de feriados nacionales de este año y del
            próximo. Se agregan solos los días puente y los feriados que se decreten, y los trasladables
            se reubican cuando se fija su fecha. Si un feriado nuevo cae sobre una reunión ya agendada,
            le llega una alerta a quien la atiende.
        </p>
        <p class="muted small">
            <?= $sincronizadoEn !== '' ? 'Última actualización: ' . e(fecha_hora($sincronizadoEn)) . '.' : 'Todavía no se actualizó.' ?>
            <?php if ($ultimoError !== ''): ?>
                <br>Último intento sin respuesta: <?= e($ultimoError) ?>. La agenda sigue con los feriados que ya tenía.
            <?php endif; ?>
        </p>
        <form method="post" action="<?= e(url('feriados?anio=' . $anio)) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" name="accion" value="sincronizar"><?= icon('refrescar', 15) ?>Actualizar ahora</button>
        </form>
    </div>

    <div class="card form-card">
        <h2 class="sec">Cómo se aplican</h2>
        <form method="post" action="<?= e(url('feriados?anio=' . $anio)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="ajustes">
            <label class="switch bloque mb-3">
                <input type="checkbox" name="activo" value="1" <?= $activo ? 'checked' : '' ?>>
                <span class="track"></span><span>La agenda no ofrece horarios en los feriados</span>
            </label>
            <div class="grid-fields">
                <label class="fld wide"><span>Además de los nacionales, rigen los de</span>
                    <select name="jurisdiccion">
                        <?php foreach (FERIADOS_JURISDICCIONES as $k => $n): if ($k === 'nacional') { continue; } ?>
                            <option value="<?= e($k) ?>" <?= $jurisdiccion === $k ? 'selected' : '' ?>><?= e($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="hint">Donde trabaja el equipo. Los regionales de otras provincias se ven en la lista pero no quitan turnos.</i>
                </label>
            </div>
            <button class="btn btn-primary btn-sm">Guardar</button>
        </form>
    </div>
</div>

<div class="card form-card">
    <h2 class="sec">Agregar un feriado</h2>
    <p class="muted small">Para los feriados provinciales o los días que la empresa no trabaja. Lo que se carga a mano no lo cambia la actualización automática.</p>
    <form method="post" action="<?= e(url('feriados?anio=' . $anio)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="agregar">
        <div class="grid-fields">
            <label class="fld"><span>Fecha <i class="req">*</i></span>
                <input type="date" name="fecha" required value="<?= e($anio === (int)date('Y') ? $hoy : $anio . '-01-01') ?>">
                <i class="hint">Ese día la agenda no ofrece horarios.</i>
            </label>
            <label class="fld wide"><span>Nombre <i class="req">*</i></span>
                <input name="nombre" required maxlength="160" placeholder="Día no laborable por el aniversario de la empresa">
            </label>
            <label class="fld"><span>Dónde rige</span>
                <select name="jurisdiccion">
                    <?php foreach (FERIADOS_JURISDICCIONES as $k => $n): ?>
                        <option value="<?= e($k) ?>" <?= $k === 'nacional' ? 'selected' : '' ?>><?= e($k === 'nacional' ? 'Toda la empresa' : $n) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Uno provincial sólo quita turnos si es de la jurisdicción configurada.</i>
            </label>
            <label class="fld"><span>Tipo</span>
                <select name="tipo">
                    <?php foreach (FERIADOS_TIPOS as $k => $n): ?>
                        <option value="<?= e($k) ?>" <?= $k === 'no_laborable' ? 'selected' : '' ?>><?= e($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <button class="btn btn-primary btn-sm"><?= icon('mas', 15) ?>Agregar</button>
    </form>
</div>

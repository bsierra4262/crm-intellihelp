<?php /** @var array $r @var array $tipo @var array $huecos @var ?string $motivo */ ?>
<div class="page-head">
    <div>
        <h1>Mover la reunión</h1>
        <p class="muted">
            <?= e($tipo['nombre']) ?> con <?= e($r['invitado_nombre']) ?>
            · hoy está el <?= fecha_hora($r['inicia_el']) ?>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('reservas')) ?>">Volver</a>
    </div>
</div>

<div class="card mb-4">
    <p class="muted small card-intro">
        Al moverla le llega una confirmación nueva por correo, con la invitación para su
        calendario, y el evento de Google se corre solo. El horario viejo queda libre.
    </p>
    <div class="kv">
        <?php
        dato('Invitado', $r['invitado_nombre']);
        dato('Correo', $r['invitado_email'], 'mail');
        dato('Empresa', $r['invitado_empresa']);
        dato('Teléfono', $r['invitado_telefono'], 'tel');
        ?>
    </div>
</div>

<?php if ($motivo === 'sin_calendario'): ?>
    <div class="flash flash-error">
        No podemos leer el calendario de Google, así que no sabemos qué horarios están libres.
        Se muestran igual los del horario laboral, pero <strong>revisá antes de mover</strong>:
        puede haber algo agendado encima.
    </div>
<?php endif; ?>

<?php if (!$huecos): ?>
    <div class="card">
        <div class="empty-state compacto">
            <?= icon('agenda', 34) ?>
            <h2>No hay horarios libres</h2>
            <p class="small">
                No quedan huecos dentro de la ventana configurada para
                «<?= e($tipo['nombre']) ?>». Podés ampliar los días o el horario laboral
                desde <a href="<?= e(url('agenda-form?id=' . (int)$tipo['id'])) ?>">el tipo de reunión</a>.
            </p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($huecos as $fecha => $slots): ?>
        <div class="card mb-4">
            <div class="card-head"><h2><?= e(ucfirst(fecha_larga($fecha))) ?></h2>
                <span class="small muted"><?= count($slots) ?> horario(s) libre(s)</span></div>
            <div class="slot-grid">
                <?php foreach ($slots as $ts): ?>
                    <form method="post" action="<?= e(url('reserva-accion')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="reprogramar">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="cuando" value="<?= e(date('Y-m-d H:i:s', $ts)) ?>">
                        <button class="slot" type="submit"
                                data-confirmar-titulo="Mover la reunión"
                                data-confirmar="La reunión con <?= e($r['invitado_nombre']) ?> pasa al <?= e(fecha_hora(date('Y-m-d H:i:s', $ts))) ?>. Le avisamos por correo."
                                data-confirmar-ok="Mover"><?= date('H:i', $ts) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

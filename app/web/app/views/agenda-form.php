<?php
/** @var array $duenioU @var bool $conectado @var array $candidatos @var ?array $t @var int $id @var array $errores @var array $porDia */
$v = fn(string $k, $def = '') => e($t[$k] ?? $def);
$nombresDia = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
               5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo'];
?>
<div class="page-head">
    <div><h1><?= $id ? 'Editar tipo de reunión' : 'Nuevo tipo de reunión' ?></h1></div>
    <div class="head-actions"><a class="btn btn-ghost" href="<?= e(url('agenda')) ?>">Volver</a></div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error"><?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">La reunión</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre <i class="req">*</i></span>
                <input name="nombre" value="<?= $v('nombre') ?>" maxlength="120" required autofocus
                       placeholder="Reunión de presentación">
                       <i class="hint">Lo que ve el visitante en la página de reserva. Por ejemplo «Reunión de presentación».</i>
                   </label>
            <label class="fld"><span>Dirección del enlace</span>
                <input name="slug" value="<?= $v('slug') ?>" maxlength="60" placeholder="se genera del nombre">
                <i class="hint">Es la parte final de la URL pública.</i>
            </label>
            <label class="fld"><span>Duración (min)</span>
                <input type="number" name="duracion_min" value="<?= $v('duracion_min', '30') ?>" min="5" max="480" required>
                <i class="hint">Cuánto dura. Es lo que define el tamaño de cada hueco que se ofrece.</i>
            </label>
            <label class="fld wide"><span>Calendario donde se crean las reuniones <i class="req">*</i></span>
                <?php if ($candidatos): ?>
                    <select name="usuario_id">
                        <?php foreach ($candidatos as $cand): ?>
                            <option value="<?= (int)$cand['id'] ?>" <?= (int)($duenioU['id'] ?? 0) === (int)$cand['id'] ? 'selected' : '' ?>>
                                <?= e($cand['nombre']) ?> · <?= e($cand['email']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input value="<?= e($duenioU['email'] ?? '') ?>" readonly>
                    <input type="hidden" name="usuario_id" value="<?= (int)($duenioU['id'] ?? 0) ?>">
                <?php endif; ?>
                <i class="hint <?= $conectado ? '' : 'bad' ?>">
                    <?php if ($conectado): ?>
                        La reunión se crea en <strong>este</strong> calendario de Google, con el Meet, y
                        esta cuenta queda como organizadora: le llega la invitación igual que al cliente.
                    <?php else: ?>
                        <strong>Esta cuenta no tiene Google conectado.</strong> Las reservas se van a
                        seguir tomando, pero la reunión <strong>no va a aparecer en ningún calendario</strong>
                        ni va a generar el enlace de Meet: el cliente se queda con su confirmación y del
                        lado nuestro no se entera nadie.
                        <a href="<?= e(url('google-login?conectar=1')) ?>">Conectar Google</a>.
                    <?php endif; ?>
                </i>
            </label>
            <label class="fld full"><span>Descripción para el visitante</span>
                <textarea name="descripcion" rows="3" placeholder="Qué van a hablar, qué tiene que traer…"><?= $v('descripcion') ?></textarea>
                <i class="hint">Se muestra en la página pública, debajo del título. Sirve para que la persona llegue sabiendo de qué se va a hablar.</i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Cuándo se puede reservar</h2>
        <div class="grid-fields">
            <label class="fld"><span>Colchón entre reuniones (min)</span>
                <input type="number" name="margen_min" value="<?= $v('margen_min', '10') ?>" min="0" max="120">
                <i class="hint">Para no quedar con dos reuniones pegadas.</i>
            </label>
            <label class="fld"><span>Antelación mínima (horas)</span>
                <input type="number" name="aviso_min_horas" value="<?= $v('aviso_min_horas', '4') ?>" min="0" max="168">
                <i class="hint">Cuánto tiempo antes, como mínimo, te pueden reservar. Con 4,
                    nadie puede tomarte un horario que empiece dentro de las próximas 4 horas.</i>
            </label>
            <label class="fld"><span>Se puede reservar hasta (días)</span>
                <input type="number" name="ventana_dias" value="<?= $v('ventana_dias', '30') ?>" min="1" max="180">
                <i class="hint">Hasta cuántos días para adelante se pueden reservar. Con 30, nadie puede tomarte un horario de dentro de dos meses.</i>
            </label>
        </div>

        <h3 class="mt-6 mb-3">Horario laboral</h3>
        <?php foreach ($nombresDia as $dia => $etiqueta): $act = isset($porDia[$dia]); ?>
            <div class="urow row-sep">
                <label class="switch w-dia">
                    <input type="checkbox" name="dias[]" value="<?= $dia ?>" <?= $act ? 'checked' : '' ?>>
                    <span class="track"></span><span><?= e($etiqueta) ?></span>
                </label>
                <input type="time" name="desde[<?= $dia ?>]" value="<?= e($porDia[$dia][0] ?? '09:00') ?>" class="w-sm">
                <span class="muted small">a</span>
                <input type="time" name="hasta[<?= $dia ?>]" value="<?= e($porDia[$dia][1] ?? '18:00') ?>" class="w-sm">
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card form-card">
        <h2 class="sec">Opciones</h2>
        <label class="switch bloque mb-3">
            <input type="checkbox" name="con_meet" value="1" <?= !empty($t['con_meet']) ? 'checked' : '' ?>>
            <span class="track"></span><span>Crear un Google Meet automáticamente en cada reserva</span>
        </label>
        <label class="switch bloque">
            <input type="checkbox" name="activo" value="1" <?= !empty($t['activo']) ? 'checked' : '' ?>>
            <span class="track"></span><span>Publicar la página (si lo apagás, el enlace deja de funcionar)</span>
        </label>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar cambios' : 'Crear' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('agenda')) ?>">Cancelar</a>
    </div>
</form>

<?php
/** @var ?array $t @var int $id @var array $errores @var array $porDia */
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
            </label>
            <label class="fld"><span>Dirección del enlace</span>
                <input name="slug" value="<?= $v('slug') ?>" maxlength="60" placeholder="se genera del nombre">
                <i class="hint">Es la parte final de la URL pública.</i>
            </label>
            <label class="fld"><span>Duración (min)</span>
                <input type="number" name="duracion_min" value="<?= $v('duracion_min', '30') ?>" min="5" max="480" required>
            </label>
            <label class="fld full"><span>Descripción para el visitante</span>
                <textarea name="descripcion" rows="3" placeholder="Qué van a hablar, qué tiene que traer…"><?= $v('descripcion') ?></textarea>
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
                <i class="hint">Nadie puede reservarte para dentro de 10 minutos.</i>
            </label>
            <label class="fld"><span>Se puede reservar hasta (días)</span>
                <input type="number" name="ventana_dias" value="<?= $v('ventana_dias', '30') ?>" min="1" max="180">
            </label>
        </div>

        <h3 style="margin:1.4rem 0 .6rem">Horario laboral</h3>
        <?php foreach ($nombresDia as $dia => $etiqueta): $act = isset($porDia[$dia]); ?>
            <div class="urow" style="padding:.35rem 0;border-bottom:1px solid var(--line-soft)">
                <label class="switch" style="min-width:150px">
                    <input type="checkbox" name="dias[]" value="<?= $dia ?>" <?= $act ? 'checked' : '' ?>>
                    <span class="track"></span><span><?= e($etiqueta) ?></span>
                </label>
                <input type="time" name="desde[<?= $dia ?>]" value="<?= e($porDia[$dia][0] ?? '09:00') ?>" style="width:130px">
                <span class="muted small">a</span>
                <input type="time" name="hasta[<?= $dia ?>]" value="<?= e($porDia[$dia][1] ?? '18:00') ?>" style="width:130px">
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card form-card">
        <h2 class="sec">Opciones</h2>
        <label class="switch" style="display:flex;margin-bottom:.8rem">
            <input type="checkbox" name="con_meet" value="1" <?= !empty($t['con_meet']) ? 'checked' : '' ?>>
            <span class="track"></span><span>Crear un Google Meet automáticamente en cada reserva</span>
        </label>
        <label class="switch" style="display:flex">
            <input type="checkbox" name="activo" value="1" <?= !empty($t['activo']) ? 'checked' : '' ?>>
            <span class="track"></span><span>Publicar la página (si lo apagás, el enlace deja de funcionar)</span>
        </label>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar cambios' : 'Crear' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('agenda')) ?>">Cancelar</a>
    </div>
</form>

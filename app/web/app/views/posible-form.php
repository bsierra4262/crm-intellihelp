<?php
/** @var ?array $p @var int $id @var array $errores @var array $usuarios */
$v = fn(string $k, $def = '') => e($p[$k] ?? $def);
?>
<div class="page-head">
    <div><h1><?= $id ? 'Editar posible cliente' : 'Nuevo posible cliente' ?></h1></div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url($id ? 'posible-ver?id=' . $id : 'posibles')) ?>">Cancelar</a>
    </div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error">Revisá los campos marcados: <?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">Quién es</h2>
        <div class="grid-fields">
            <label class="fld"><span>Nombre</span><input name="nombre" value="<?= $v('nombre') ?>" maxlength="120" autofocus></label>
            <label class="fld"><span>Apellido <i class="req">*</i></span>
                <input name="apellido" value="<?= $v('apellido') ?>" maxlength="120" required>
                <?php if (isset($errores['apellido'])): ?><i class="hint bad"><?= e($errores['apellido']) ?></i><?php endif; ?>
            </label>
            <label class="fld"><span>Empresa <i class="req">*</i></span>
                <input name="empresa" value="<?= $v('empresa') ?>" maxlength="200" required>
                <?php if (isset($errores['empresa'])): ?><i class="hint bad"><?= e($errores['empresa']) ?></i><?php endif; ?>
            </label>
            <label class="fld"><span>Cargo</span><input name="cargo" value="<?= $v('cargo') ?>" maxlength="150"></label>

            <label class="fld"><span>Estado</span>
                <select name="estado">
                    <?php foreach (estados_posible_cliente() as $x): ?>
                        <option value="<?= e($x) ?>" <?= ($p['estado'] ?? 'Sin contactar') === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld"><span>Calificación</span>
                <select name="calificacion">
                    <option value="">— Sin calificar —</option>
                    <?php foreach (calificaciones() as $x): ?>
                        <option value="<?= e($x) ?>" <?= ($p['calificacion'] ?? '') === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld"><span>Fuente</span>
                <select name="fuente">
                    <option value="">— Sin definir —</option>
                    <?php foreach (fuentes() as $x): ?>
                        <option value="<?= e($x) ?>" <?= ($p['fuente'] ?? '') === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld"><span>Propietario</span>
                <select name="propietario_id">
                    <option value="">— Sin asignar —</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)($p['propietario_id'] ?? current_user_id()) === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Cómo se lo contacta</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Correo</span>
                <input type="email" name="email" value="<?= $v('email') ?>" maxlength="190">
                <?php if (isset($errores['email'])): ?><i class="hint bad"><?= e($errores['email']) ?></i><?php endif; ?>
            </label>
            <label class="fld wide"><span>Correo secundario</span><input type="email" name="email2" value="<?= $v('email2') ?>" maxlength="190"></label>
            <label class="fld"><span>Teléfono directo</span><input type="tel" name="telefono" value="<?= $v('telefono') ?>" maxlength="60" inputmode="tel" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios."></label>
            <label class="fld"><span>Móvil</span><input type="tel" name="movil" value="<?= $v('movil') ?>" maxlength="60" inputmode="tel" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios."></label>
            <label class="fld"><span>Conmutador</span><input type="tel" name="conmutador" value="<?= $v('conmutador') ?>" maxlength="60" inputmode="tel" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios."></label>
            <label class="fld"><span>Sitio web</span><input type="url" name="sitio_web" value="<?= $v('sitio_web') ?>" maxlength="255"></label>
            <label class="fld wide"><span>LinkedIn</span><input type="url" name="linkedin" value="<?= $v('linkedin') ?>" maxlength="255"></label>
            <label class="fld"><span>Sector</span><input name="sector" value="<?= $v('sector') ?>" maxlength="80"></label>
            <label class="fld"><span>Empleados</span><input type="number" name="empleados" value="<?= $v('empleados') ?>" min="0"></label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Dónde está</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Domicilio</span><input name="calle" value="<?= $v('calle') ?>" maxlength="250"></label>
            <label class="fld"><span>Localidad</span><input name="localidad" value="<?= $v('localidad') ?>" maxlength="120"></label>
            <label class="fld"><span>Código postal</span><input name="codigo_postal" value="<?= $v('codigo_postal') ?>" maxlength="30"></label>
            <label class="fld"><span>Provincia</span>
                <select name="provincia">
                    <option value="">— Sin definir —</option>
                    <?php foreach (provincias_ar() as $pv): ?>
                        <option value="<?= e($pv) ?>" <?= ($p['provincia'] ?? '') === $pv ? 'selected' : '' ?>><?= e($pv) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld"><span>País</span><input name="pais" value="<?= $v('pais', 'Argentina') ?>" maxlength="60"></label>
            <label class="fld full"><span>Notas</span><textarea name="descripcion" rows="4"><?= $v('descripcion') ?></textarea></label>
            <div class="fld full">
                <label class="switch">
                    <input type="checkbox" name="no_email" value="1" <?= !empty($p['no_email']) ? 'checked' : '' ?>>
                    <span class="track"></span><span>No incluirlo en campañas de correo</span>
                </label>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar cambios' : 'Crear posible cliente' ?></button>
        <a class="btn btn-ghost" href="<?= e(url($id ? 'posible-ver?id=' . $id : 'posibles')) ?>">Cancelar</a>
    </div>
</form>

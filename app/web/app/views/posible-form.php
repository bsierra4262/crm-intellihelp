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
            <label class="fld"><span>Cargo</span><input name="cargo" value="<?= $v('cargo') ?>" maxlength="150">
                <i class="hint">El puesto. Se usa en la variable {{cargo}} de las campañas.</i>
            </label>

            <label class="fld"><span>Estado</span>
                <select name="estado">
                    <?php foreach (estados_posible_cliente() as $x): ?>
                        <option value="<?= e($x) ?>" <?= ($p['estado'] ?? 'Sin contactar') === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">En qué punto está el contacto. Al convertirlo en cliente, este campo deja de usarse.</i>
            </label>
            <label class="fld"><span>Calificación</span>
                <select name="calificacion">
                    <option value="">— Sin calificar —</option>
                    <?php foreach (calificaciones() as $x): ?>
                        <option value="<?= e($x) ?>" <?= ($p['calificacion'] ?? '') === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Qué tan cerca está de comprar, a ojo del vendedor. Sirve para priorizar a quién llamar primero.</i>
            </label>
            <label class="fld"><span>Fuente</span>
                <select name="fuente">
                    <option value="">— Sin definir —</option>
                    <?php foreach (fuentes() as $x): ?>
                        <option value="<?= e($x) ?>" <?= ($p['fuente'] ?? '') === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">De dónde salió. Sirve para saber qué canal trae más clientes en Reportes.</i>
            </label>
            <label class="fld"><span>Propietario</span>
                <select name="propietario_id">
                    <option value="">— Sin asignar —</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)($p['propietario_id'] ?? current_user_id()) === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Quién lo atiende. Es lo que separa «Lo mío» de «Todo el equipo» en el panel.</i>
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
            <label class="fld wide"><span>Correo secundario</span><input type="email" name="email2" value="<?= $v('email2') ?>" maxlength="190">
                <i class="hint">Una segunda dirección. Las campañas usan la principal.</i>
            </label>
            <label class="fld"><span>Teléfono directo</span><input type="tel" name="telefono" value="<?= $v('telefono') ?>" maxlength="60" inputmode="tel" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.">
                <i class="hint">El directo. Sólo números; se aceptan + ( ) - . y espacios.</i>
            </label>
            <label class="fld"><span>Móvil</span><input type="tel" name="movil" value="<?= $v('movil') ?>" maxlength="60" inputmode="tel" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.">
                <i class="hint">El celular. Es el que se usa para escribirle por WhatsApp.</i>
            </label>
            <label class="fld"><span>Conmutador</span><input type="tel" name="conmutador" value="<?= $v('conmutador') ?>" maxlength="60" inputmode="tel" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.">
                <i class="hint">La central de la empresa, cuando hay que pedir por interno.</i>
            </label>
            <label class="fld"><span>Sitio web</span><input type="url" name="sitio_web" value="<?= $v('sitio_web') ?>" maxlength="255">
                <i class="hint">El de la empresa. Mirarlo antes de llamar cambia la conversación.</i>
            </label>
            <label class="fld wide"><span>LinkedIn</span><input type="url" name="linkedin" value="<?= $v('linkedin') ?>" maxlength="255">
                <i class="hint">El perfil de la persona.</i>
            </label>
            <label class="fld"><span>Sector</span><input name="sector" value="<?= $v('sector') ?>" maxlength="80">
                <i class="hint">El rubro: petroleras, bancos, laboratorios. Sirve para segmentar las campañas.</i>
            </label>
            <label class="fld"><span>Empleados</span><input type="number" name="empleados" value="<?= $v('empleados') ?>" min="0">
                <i class="hint">Cuánta gente tiene la empresa. Ayuda a dimensionar la propuesta.</i>
            </label>
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
            <label class="fld full"><span>Notas</span><textarea name="descripcion" rows="4"><?= $v('descripcion') ?></textarea>
                <i class="hint">Todo lo que convenga recordar. Es interno: no sale en ningún correo.</i>
            </label>
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

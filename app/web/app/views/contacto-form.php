<?php
/** @var ?array $c @var int $id @var array $errores @var array $usuarios @var array $cuentas */
$v = fn(string $k, $def = '') => e($c[$k] ?? $def);
?>
<div class="page-head">
    <div>
        <h1><?= $id ? 'Editar contacto' : 'Nuevo contacto' ?></h1>
        <?php if ($id): ?><p class="muted">Alta: <?= fecha_corta($c['creado_en'] ?? null) ?></p><?php endif; ?>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url($id ? 'contacto-ver?id=' . $id : 'contactos')) ?>">Cancelar</a>
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
            <label class="fld"><span>Nombre</span>
                <input name="nombre" value="<?= $v('nombre') ?>" maxlength="120" autofocus>
            </label>
            <label class="fld"><span>Apellido <i class="req">*</i></span>
                <input name="apellido" value="<?= $v('apellido') ?>" maxlength="120" required>
                <?php if (isset($errores['apellido'])): ?><i class="hint bad"><?= e($errores['apellido']) ?></i><?php endif; ?>
            </label>
            <label class="fld"><span>Cargo</span>
                <input name="cargo" value="<?= $v('cargo') ?>" maxlength="150" placeholder="Gerente de Sistemas">
            </label>
            <label class="fld"><span>Departamento</span>
                <input name="departamento" value="<?= $v('departamento') ?>" maxlength="120">
            </label>

            <label class="fld wide"><span>Empresa</span>
                <select name="cuenta_id">
                    <option value="">— Sin empresa —</option>
                    <?php foreach ($cuentas as $cu): ?>
                        <option value="<?= (int)$cu['id'] ?>" <?= (string)($c['cuenta_id'] ?? '') === (string)$cu['id'] ? 'selected' : '' ?>><?= e($cu['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld wide"><span>…o crear una empresa nueva</span>
                <input name="cuenta_nueva" placeholder="Escribí el nombre y se crea sola al guardar" maxlength="200">
            </label>

            <label class="fld"><span>Propietario</span>
                <select name="propietario_id">
                    <option value="">— Sin asignar —</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)($c['propietario_id'] ?? current_user_id()) === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld"><span>Fuente</span>
                <select name="fuente">
                    <option value="">— Sin definir —</option>
                    <?php foreach (fuentes() as $fu): ?>
                        <option value="<?= e($fu) ?>" <?= ($c['fuente'] ?? '') === $fu ? 'selected' : '' ?>><?= e($fu) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Cómo se lo contacta</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Correo principal</span>
                <input type="email" name="email" value="<?= $v('email') ?>" maxlength="190">
                <?php if (isset($errores['email'])): ?><i class="hint bad"><?= e($errores['email']) ?></i><?php endif; ?>
            </label>
            <label class="fld"><span>Correo secundario</span>
                <input type="email" name="email2" value="<?= $v('email2') ?>" maxlength="190">
            </label>
            <label class="fld"><span>Correo terciario</span>
                <input type="email" name="email3" value="<?= $v('email3') ?>" maxlength="190">
            </label>
            <label class="fld"><span>Teléfono directo</span>
                <input type="tel" name="telefono" value="<?= $v('telefono') ?>" maxlength="60" inputmode="tel">
            </label>
            <label class="fld"><span>Móvil</span>
                <input type="tel" name="movil" value="<?= $v('movil') ?>" maxlength="60" inputmode="tel">
            </label>
            <label class="fld"><span>Conmutador</span>
                <input type="tel" name="conmutador" value="<?= $v('conmutador') ?>" maxlength="60" placeholder="Central de la empresa" inputmode="tel">
            </label>
            <label class="fld"><span>LinkedIn</span>
                <input type="url" name="linkedin" value="<?= $v('linkedin') ?>" maxlength="255" placeholder="https://linkedin.com/in/…">
            </label>
            <label class="fld wide"><span>Sitio web</span>
                <input type="url" name="sitio_web" value="<?= $v('sitio_web') ?>" maxlength="255">
            </label>
            <div class="fld wide abajo">
                <label class="switch">
                    <input type="checkbox" name="no_email" value="1" <?= !empty($c['no_email']) ? 'checked' : '' ?>>
                    <span class="track"></span>
                    <span>No incluirlo en campañas de correo</span>
                </label>
            </div>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Dónde está</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Domicilio</span>
                <input name="calle" value="<?= $v('calle') ?>" maxlength="250">
            </label>
            <label class="fld"><span>Localidad</span>
                <input name="localidad" value="<?= $v('localidad') ?>" maxlength="120">
            </label>
            <label class="fld"><span>Código postal</span>
                <input name="codigo_postal" value="<?= $v('codigo_postal') ?>" maxlength="30">
            </label>
            <label class="fld"><span>Provincia</span>
                <select name="provincia">
                    <option value="">— Sin definir —</option>
                    <?php foreach (provincias_ar() as $pv): ?>
                        <option value="<?= e($pv) ?>" <?= ($c['provincia'] ?? '') === $pv ? 'selected' : '' ?>><?= e($pv) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld"><span>País</span>
                <input name="pais" value="<?= $v('pais', 'Argentina') ?>" maxlength="60">
            </label>
            <label class="fld full"><span>Notas</span>
                <textarea name="descripcion" rows="4"><?= $v('descripcion') ?></textarea>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar cambios' : 'Crear contacto' ?></button>
        <a class="btn btn-ghost" href="<?= e(url($id ? 'contacto-ver?id=' . $id : 'contactos')) ?>">Cancelar</a>
    </div>
</form>

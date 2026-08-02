<?php
/** @var ?array $c @var int $id @var array $errores @var array $usuarios */
$v = fn(string $k, $def = '') => e($c[$k] ?? $def);
?>
<div class="page-head">
    <div><h1><?= $id ? 'Editar empresa' : 'Nueva empresa' ?></h1></div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url($id ? 'cuenta-ver?id=' . $id : 'cuentas')) ?>">Cancelar</a>
    </div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error">Revisá los campos marcados: <?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">Identificación</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre de la empresa <i class="req">*</i></span>
                <input name="nombre" value="<?= $v('nombre') ?>" maxlength="200" required autofocus>
                <?php if (isset($errores['nombre'])): ?><i class="hint bad"><?= e($errores['nombre']) ?></i><?php endif; ?>
            </label>
            <label class="fld"><span>Tipo</span>
                <select name="tipo">
                    <option value="">— Sin definir —</option>
                    <?php foreach (tipos_cuenta() as $t): ?>
                        <option value="<?= e($t) ?>" <?= ($c['tipo'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Qué es para nosotros: un cliente, un prospecto, un proveedor.</i>
            </label>
            <label class="fld"><span>Sector</span>
                <input name="sector" value="<?= $v('sector') ?>" maxlength="80" placeholder="Industria, Salud, Retail…">
                <i class="hint">El rubro: petroleras, bancos, laboratorios. Sirve para segmentar las campañas.</i>
            </label>
            <label class="fld"><span>CUIT</span>
                <input name="cuit" value="<?= e(formatear_cuit($c['cuit'] ?? '')) ?>" maxlength="13" placeholder="30-12345678-1"
                       data-solo="cuit" inputmode="numeric" pattern="[0-9]{2}-?[0-9]{8}-?[0-9]" title="Once números: 30-12345678-1">
                <?php if (isset($errores['cuit'])): ?><i class="hint bad"><?= e($errores['cuit']) ?></i>
                <?php else: ?><i class="hint">Se valida con el dígito verificador (módulo 11).</i><?php endif; ?>
            </label>
            <label class="fld"><span>Condición frente al IVA</span>
                <input name="condicion_iva" value="<?= $v('condicion_iva') ?>" maxlength="40" placeholder="Responsable Inscripto">
                <i class="hint">Cómo factura. Hace falta al emitir.</i>
            </label>
            <label class="fld"><span>Empleados</span>
                <input type="number" name="empleados" value="<?= $v('empleados') ?>" min="0" step="1">
                <i class="hint">Cuánta gente tiene. Ayuda a dimensionar la propuesta.</i>
            </label>
            <label class="fld"><span>Propietario</span>
                <select name="propietario_id">
                    <option value="">— Sin asignar —</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)($c['propietario_id'] ?? current_user_id()) === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Quién la atiende. Es lo que separa «Lo mío» de «Todo el equipo» en el panel.</i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Contacto y ubicación</h2>
        <div class="grid-fields">
            <label class="fld"><span>Teléfono</span>
                <input type="tel" name="telefono" value="<?= $v('telefono') ?>" maxlength="60" inputmode="tel" pattern="<?= e(TELEFONO_PATRON) ?>" title="Sólo números, y al menos seis. Se aceptan + ( ) - . y espacios.">
                <i class="hint">La central. Sólo números; se aceptan + ( ) - . y espacios.</i>
            </label>
            <label class="fld"><span>Correo</span>
                <input type="email" name="email" value="<?= $v('email') ?>" maxlength="190">
                <i class="hint">La casilla general de la empresa (info@, ventas@). El correo de cada persona va en su ficha.</i>
            </label>
            <label class="fld wide"><span>Sitio web</span>
                <input type="url" name="sitio_web" value="<?= $v('sitio_web') ?>" maxlength="255">
                <i class="hint">El sitio de la empresa.</i>
            </label>
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
            <label class="fld wide"><span>LinkedIn</span>
                <input type="url" name="linkedin" value="<?= $v('linkedin') ?>" maxlength="255">
                <i class="hint">La página de la empresa en LinkedIn.</i>
            </label>
            <label class="fld full"><span>Notas</span>
                <textarea name="descripcion" rows="4"><?= $v('descripcion') ?></textarea>
                <i class="hint">Contexto que convenga tener a mano. Es interno.</i>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar cambios' : 'Crear empresa' ?></button>
        <a class="btn btn-ghost" href="<?= e(url($id ? 'cuenta-ver?id=' . $id : 'cuentas')) ?>">Cancelar</a>
    </div>
</form>

<?php
/** @var ?array $c @var int $id @var array $errores @var array $usuarios @var array $listas
 *  @var array $plantillas @var array $cuentas @var array $categorias */
$v = fn(string $k, $def = '') => e($c[$k] ?? $def);
?>
<div class="page-head">
    <div><h1><?= $id ? 'Editar campaña' : 'Nueva campaña' ?></h1></div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url($id ? 'campana-ver?id=' . $id : 'campanas')) ?>">Cancelar</a>
    </div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error">Revisá los campos marcados: <?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<?php if (!$listas || !$plantillas): ?>
    <div class="flash flash-warn">
        Antes de armar una campaña necesitás
        <?php if (!$plantillas): ?><a href="<?= e(url('plantilla-form')) ?>">crear una plantilla</a><?php endif; ?>
        <?= (!$listas && !$plantillas) ? ' y ' : '' ?>
        <?php if (!$listas): ?><a href="<?= e(url('lista-form')) ?>">armar una lista de destinatarios</a><?php endif; ?>.
    </div>
<?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">Qué se manda y a quién</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre de la campaña <i class="req">*</i></span>
                <input name="nombre" value="<?= $v('nombre') ?>" maxlength="200" required autofocus>
            </label>
            <label class="fld"><span>Lista de destinatarios <i class="req">*</i></span>
                <select name="lista_id" required>
                    <option value="">— Elegí una —</option>
                    <?php foreach ($listas as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= (string)($c['lista_id'] ?? '') === (string)$l['id'] ? 'selected' : '' ?>>
                            <?= $l['categoria'] ? '[' . e($l['categoria']) . '] ' : '' ?><?= e($l['nombre']) ?> (<?= (int)$l['miembros'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld"><span>Segmento</span>
                <select name="categoria_id">
                    <option value="">— Sin segmento —</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= (string)($c['categoria_id'] ?? '') === (string)$cat['id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Sirve para agrupar las campañas por rubro y comparar resultados.</i>
            </label>
            <label class="fld"><span>Plantilla <i class="req">*</i></span>
                <select name="plantilla_id" required>
                    <option value="">— Elegí una —</option>
                    <?php foreach ($plantillas as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (string)($c['plantilla_id'] ?? '') === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld full"><span>Asunto (pisa el de la plantilla)</span>
                <input name="asunto" value="<?= $v('asunto') ?>" maxlength="255" placeholder="Dejalo vacío para usar el de la plantilla">
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">De quién viene</h2>
        <p class="muted small" style="margin:-.5rem 0 1rem">
            Elegí desde qué casilla sale esta campaña. Cada cuenta tiene su propio tope diario y
            su propia reputación, así que se pueden correr <strong>varias campañas en paralelo</strong>
            sin que se pisen.
            <?php if (auth_is_admin()): ?><a href="<?= e(url('cuentas-correo')) ?>">Administrar cuentas</a>.<?php endif; ?>
        </p>
        <div class="grid-fields">
            <label class="fld wide"><span>Cuenta de correo</span>
                <select name="cuenta_correo_id">
                    <option value="">— La cuenta por defecto —</option>
                    <?php foreach ($cuentas as $cu): ?>
                        <option value="<?= (int)$cu['id'] ?>" <?= (string)($c['cuenta_correo_id'] ?? '') === (string)$cu['id'] ? 'selected' : '' ?>>
                            <?= e($cu['nombre']) ?> · <?= e($cu['remitente']) ?>
                            (<?= (int)$cu['enviados_hoy'] ?>/<?= (int)$cu['limite_diario'] ?> hoy)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$cuentas): ?>
                    <i class="hint bad">No hay cuentas cargadas. Sin una cuenta la campaña no puede salir.</i>
                <?php endif; ?>
            </label>
            <label class="fld"><span>Nombre del remitente</span>
                <input name="remitente_nombre" value="<?= $v('remitente_nombre') ?>" maxlength="160">
            </label>
            <label class="fld"><span>Correo del remitente</span>
                <input type="email" name="remitente_email" value="<?= $v('remitente_email') ?>" maxlength="190">
                <i class="hint">Tiene que ser una cuenta autorizada en el servidor de salida.</i>
            </label>
            <label class="fld"><span>Responder a</span>
                <input type="email" name="responder_a" value="<?= $v('responder_a') ?>" maxlength="190">
            </label>
            <label class="fld wide"><span>Con copia (CC)</span>
                <input name="copia_a" value="<?= $v('copia_a') ?>" maxlength="500"
                       placeholder="ventas@intellihelp.tech, gerencia@intellihelp.tech">
                <i class="hint">Estas direcciones reciben <strong>una copia de cada correo</strong> de la campaña.
                    Sirve para que el equipo vea lo que sale. Separalas con coma. Ojo: si la campaña tiene
                    500 destinatarios, quien esté en copia recibe 500 correos.</i>
            </label>
            <label class="fld"><span>Responsable</span>
                <select name="propietario_id">
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)($c['propietario_id'] ?? current_user_id()) === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Ritmo de envío</h2>
        <p class="muted small" style="margin:-.5rem 0 1rem">
            La pausa entre correos es lo que evita que el proveedor nos marque como spam.
            Por debajo de 25 segundos el ritmo se vuelve robótico; por encima de 45 la campaña
            se hace eterna. El valor por defecto da unos 80–100 correos por hora.
        </p>
        <div class="grid-fields">
            <label class="fld"><span>Pausa mínima (seg)</span>
                <input type="number" name="pausa_min_seg" value="<?= $v('pausa_min_seg', '25') ?>" min="5" max="600">
            </label>
            <label class="fld"><span>Pausa máxima (seg)</span>
                <input type="number" name="pausa_max_seg" value="<?= $v('pausa_max_seg', '45') ?>" min="10" max="900">
            </label>
            <label class="fld"><span>Límite por día</span>
                <input type="number" name="limite_diario" value="<?= $v('limite_diario', '300') ?>" min="1" max="5000">
            </label>
            <label class="fld"><span>Programar arranque</span>
                <input type="datetime-local" name="programada_para"
                       value="<?= e($c['programada_para'] ?? '' ? date('Y-m-d\TH:i', strtotime((string)$c['programada_para'])) : '') ?>">
            </label>
            <label class="fld"><span>Enviar desde (hora)</span>
                <input type="time" name="enviar_desde" value="<?= e(substr((string)($c['enviar_desde'] ?? '09:00'), 0, 5)) ?>">
            </label>
            <label class="fld"><span>Enviar hasta (hora)</span>
                <input type="time" name="enviar_hasta" value="<?= e(substr((string)($c['enviar_hasta'] ?? '18:30'), 0, 5)) ?>">
            </label>
            <label class="fld full"><span>Notas internas</span>
                <textarea name="descripcion" rows="3"><?= $v('descripcion') ?></textarea>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar cambios' : 'Crear campaña' ?></button>
        <a class="btn btn-ghost" href="<?= e(url($id ? 'campana-ver?id=' . $id : 'campanas')) ?>">Cancelar</a>
    </div>
</form>

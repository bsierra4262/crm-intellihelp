<?php
/** @var ?array $n @var int $id @var array $errores @var array $usuarios @var array $etapas
 *  @var array $cuentas @var array $contactos @var array $campanas */
$v = fn(string $k, $def = '') => e($n[$k] ?? $def);
?>
<div class="page-head">
    <div><h1><?= $id ? 'Editar negocio' : 'Nuevo negocio' ?></h1></div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url($id ? 'negocio-ver?id=' . $id : 'embudo')) ?>">Cancelar</a>
    </div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error">Revisá los campos marcados: <?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">La oportunidad</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre del negocio <i class="req">*</i></span>
                <input name="nombre" value="<?= $v('nombre') ?>" maxlength="200" required autofocus placeholder="Renovacion de equipamiento - Distribuidora del Sur">
                <?php if (isset($errores['nombre'])): ?><i class="hint bad"><?= e($errores['nombre']) ?></i><?php endif; ?>
            </label>
            <label class="fld"><span>Etapa</span>
                <select name="etapa_id">
                    <?php foreach ($etapas as $et): ?>
                        <option value="<?= (int)$et['id'] ?>" <?= (string)($n['etapa_id'] ?? '') === (string)$et['id'] ? 'selected' : '' ?>>
                            <?= e($et['nombre']) ?> (<?= (int)$et['probabilidad'] ?> %)
                        </option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">La probabilidad se toma de la etapa salvo que la cambies abajo.</i>
            </label>
            <label class="fld"><span>Probabilidad (%)</span>
                <input type="number" name="probabilidad" value="<?= $v('probabilidad') ?>" min="0" max="100" placeholder="según la etapa">
                <i class="hint">Qué tan probable es cerrarlo, de 0 a 100. Si lo dejás vacío toma la de la etapa. Es lo que multiplica el importe en el «ponderado» de Reportes.</i>
            </label>

            <label class="fld"><span>Importe</span>
                <input type="number" name="importe" value="<?= $v('importe', '0') ?>" inputmode="decimal" step="0.01" min="0">
                <i class="hint">Cuánto vale el negocio si se cierra. Es lo que suma en el embudo y en el pronóstico.</i>
            </label>
            <label class="fld"><span>Moneda</span>
                <select name="moneda">
                    <option value="ARS" <?= ($n['moneda'] ?? 'ARS') === 'ARS' ? 'selected' : '' ?>>Pesos (ARS)</option>
                    <option value="USD" <?= ($n['moneda'] ?? '') === 'USD' ? 'selected' : '' ?>>Dólares (USD)</option>
                </select>
                <i class="hint">En qué moneda está el importe.</i>
            </label>
            <label class="fld"><span>Fecha estimada de cierre</span>
                <input type="date" name="fecha_cierre" value="<?= $v('fecha_cierre') ?>">
                <i class="hint">Cuándo esperás cerrarlo. Es lo que ubica el negocio en el pronóstico del mes.</i>
            </label>
            <label class="fld"><span>Propietario</span>
                <select name="propietario_id">
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)($n['propietario_id'] ?? current_user_id()) === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Quién lo lleva. Es lo que separa «Lo mío» de «Todo el equipo».</i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Con quién</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Empresa</span>
                <select name="cuenta_id">
                    <option value="">— Sin empresa —</option>
                    <?php foreach ($cuentas as $cu): ?>
                        <option value="<?= (int)$cu['id'] ?>" <?= (string)($n['cuenta_id'] ?? '') === (string)$cu['id'] ? 'selected' : '' ?>><?= e($cu['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">La empresa que compra.</i>
            </label>
            <label class="fld wide"><span>Contacto principal</span>
                <select name="contacto_id">
                    <option value="">— Sin contacto —</option>
                    <?php foreach ($contactos as $ct): ?>
                        <option value="<?= (int)$ct['id'] ?>" <?= (string)($n['contacto_id'] ?? '') === (string)$ct['id'] ? 'selected' : '' ?>><?= e($ct['nombre_completo']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Con quién se está hablando. Suele ser quien decide o quien lleva el tema adentro.</i>
            </label>
            <label class="fld"><span>Tipo</span>
                <select name="tipo">
                    <option value="">— Sin definir —</option>
                    <?php foreach (['Cliente nuevo', 'Cliente existente', 'Renovación'] as $t): ?>
                        <option value="<?= e($t) ?>" <?= ($n['tipo'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Si es un cliente nuevo o una venta a uno que ya tenemos.</i>
            </label>
            <label class="fld"><span>Fuente</span>
                <select name="fuente">
                    <option value="">— Sin definir —</option>
                    <?php foreach (fuentes() as $fu): ?>
                        <option value="<?= e($fu) ?>" <?= ($n['fuente'] ?? '') === $fu ? 'selected' : '' ?>><?= e($fu) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">De dónde salió la oportunidad.</i>
            </label>
            <label class="fld wide"><span>Campaña de origen</span>
                <select name="campana_id">
                    <option value="">— Ninguna —</option>
                    <?php foreach ($campanas as $ca): ?>
                        <option value="<?= (int)$ca['id'] ?>" <?= (string)($n['campana_id'] ?? '') === (string)$ca['id'] ? 'selected' : '' ?>><?= e($ca['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Sirve para medir qué campaña trae plata de verdad.</i>
            </label>
            <label class="fld full"><span>Siguiente paso</span>
                <input name="siguiente_paso" value="<?= $v('siguiente_paso') ?>" maxlength="255" placeholder="Mandar propuesta, llamar al gerente…">
                <i class="hint">Lo próximo que hay que hacer, concreto: «mandar propuesta», «llamar al gerente». Es lo que evita que un negocio se quede quieto.</i>
            </label>
            <label class="fld full"><span>Motivo de pérdida</span>
                <input name="motivo_perdida" value="<?= $v('motivo_perdida') ?>" maxlength="200" placeholder="Se completa si el negocio se pierde">
                <i class="hint">Por qué se perdió. Se completa al cerrarlo como perdido, y con el tiempo muestra dónde se cae siempre.</i>
            </label>
            <label class="fld full"><span>Notas</span>
                <textarea name="descripcion" rows="4" placeholder="15 puestos de trabajo mas el servidor. Deciden en comite."><?= $v('descripcion') ?></textarea>
                <i class="hint">Contexto del negocio. Es interno.</i>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar cambios' : 'Crear negocio' ?></button>
        <a class="btn btn-ghost" href="<?= e(url($id ? 'negocio-ver?id=' . $id : 'embudo')) ?>">Cancelar</a>
    </div>
</form>

<?php
/** @var array $p @var ?string $error @var ?array $cuentaExistente @var ?array $contactoExistente */
$nombre = trim(($p['nombre'] ?? '') . ' ' . $p['apellido']);
?>
<div class="page-head">
    <div>
        <h1>Convertir posible cliente</h1>
        <p class="muted"><?= e($nombre) ?> · <?= e($p['empresa']) ?></p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('posible-ver?id=' . (int)$p['id'])) ?>">Cancelar</a>
    </div>
</div>

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">Qué va a pasar</h2>
        <div class="timeline">
            <div class="tl-item">
                <div class="tl-title"><?= icon('cuentas', 14) ?> Empresa</div>
                <div class="tl-detail">
                    <?php if ($cuentaExistente): ?>
                        Se va a usar la empresa que ya existe: <strong><?= e($cuentaExistente['nombre']) ?></strong>. No se duplica.
                    <?php else: ?>
                        Se crea la empresa <strong><?= e($p['empresa']) ?></strong> con los datos del posible cliente.
                    <?php endif; ?>
                </div>
            </div>
            <div class="tl-item">
                <div class="tl-title"><?= icon('contactos', 14) ?> Contacto</div>
                <div class="tl-detail">
                    <?php if ($contactoExistente): ?>
                        Ya existe un contacto con ese correo (<strong><?= e($contactoExistente['nombre_completo']) ?></strong>): se enlaza a la empresa en vez de crear otro.
                    <?php else: ?>
                        Se crea el contacto <strong><?= e($nombre) ?></strong> dentro de esa empresa.
                    <?php endif; ?>
                </div>
            </div>
            <div class="tl-item">
                <div class="tl-title"><?= icon('nota', 14) ?> Notas y actividades</div>
                <div class="tl-detail">Todo lo que hay cargado acá se traslada al contacto: no se pierde nada.</div>
            </div>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Negocio (opcional)</h2>
        <label class="switch mb-4">
            <input type="checkbox" name="crear_negocio" value="1" checked>
            <span class="track"></span>
            <span>Crear también una oportunidad de venta</span>
        </label>
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre del negocio</span>
                <input name="negocio_nombre" value="<?= e($p['empresa']) ?>" maxlength="200">
                <i class="hint">Cómo se va a llamar la oportunidad en el embudo. Por ejemplo «Outsourcing 20 puestos».</i>
            </label>
            <label class="fld"><span>Importe estimado</span>
                <input name="negocio_importe" value="0" inputmode="decimal">
                <i class="hint">Cuánto vale si se cierra. Se puede ajustar después.</i>
            </label>
            <label class="fld"><span>Moneda</span>
                <select name="negocio_moneda">
                    <option value="ARS">Pesos (ARS)</option>
                    <option value="USD">Dólares (USD)</option>
                </select>
                <i class="hint">En qué moneda está el importe.</i>
            </label>
            <label class="fld"><span>Fecha estimada de cierre</span>
                <input type="date" name="negocio_cierre" value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>">
                <i class="hint">Cuándo esperás cerrarlo. Ubica el negocio en el pronóstico del mes.</i>
            </label>
        </div>
        <p class="muted small mt-3">
            El negocio arranca en la primera etapa del embudo. Podés cambiar el importe y la fecha cuando quieras.
        </p>
    </div>

    <div class="form-actions">
        <button class="btn btn-accent"><?= icon('convertir', 16) ?>Convertir</button>
        <a class="btn btn-ghost" href="<?= e(url('posible-ver?id=' . (int)$p['id'])) ?>">Cancelar</a>
    </div>
</form>

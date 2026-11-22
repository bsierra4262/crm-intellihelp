<?php
// ============================================================
//  Vista: confirmación de la conversión de un posible cliente.
//
//  La dibuja pages/posible-convertir.php. Explica qué se crea y qué se
//  reutiliza, y ofrece crear un negocio en la primera etapa del embudo
//  predeterminado. El formulario se manda a la misma página.
//
//  Variables que recibe:
//    $p                  array        el posible cliente (posible_get)
//    $error              string|null  mensaje si la conversión falló
//    $errores            array        campo => mensaje de los datos del negocio
//    $form               array        lo que se mandó (vacío en el GET)
//    $cuentaExistente    array|null   empresa con el mismo nombre, si hay
//    $contactoExistente  array|null   contacto con el mismo correo principal
//                                     (con `cuenta_id` y `cuenta`), si hay
//    $verNegocios        bool         módulo de negocios encendido
//    $hayEmbudo          bool         hay embudo predeterminado con etapas activas
//
//  Lo que se promete acá es lo que hace posible_convertir(): un contacto
//  existente se completa sin pisar nada y sólo se enlaza a la empresa si no
//  tenía otra; notas, actividades y adjuntos se mudan, las etiquetas se
//  copian y lo que no tiene columna queda en una nota.
//  La tarjeta del negocio sólo sale con el módulo de negocios encendido.
// ============================================================
/** @var array $p @var ?string $error @var array $errores @var array $form
 *  @var ?array $cuentaExistente @var ?array $contactoExistente @var bool $verNegocios @var bool $hayEmbudo */
$nombre = trim(($p['nombre'] ?? '') . ' ' . $p['apellido']);
// En el GET, los valores iniciales; después de un error, lo que se escribió.
$fv = static fn(string $k, string $def) => (string)($form[$k] ?? $def);
$crearMarcado = $form ? !empty($form['crear_negocio']) : $hayEmbudo;
$empresaVacia = trim((string)$p['empresa']) === '';
$otraEmpresa  = $contactoExistente && !empty($contactoExistente['cuenta_id'])
             && (!$cuentaExistente || (int)$contactoExistente['cuenta_id'] !== (int)$cuentaExistente['id']);
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
<?php if ($errores): ?><div class="flash flash-error">Revisá los datos del negocio: <?= e(implode(' ', $errores)) ?></div><?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">Qué va a pasar</h2>
        <div class="timeline">
            <div class="tl-item">
                <div class="tl-title"><?= icon('cuentas', 14) ?> Empresa</div>
                <div class="tl-detail">
                    <?php if ($empresaVacia): ?>
                        El posible cliente no tiene empresa cargada: el contacto queda sin empresa.
                    <?php elseif ($cuentaExistente): ?>
                        Se va a usar la empresa que ya existe: <strong><?= e($cuentaExistente['nombre']) ?></strong>. No se duplica; sólo se completa lo que tenga vacío.
                    <?php else: ?>
                        Se crea la empresa <strong><?= e($p['empresa']) ?></strong> con los datos del posible cliente.
                    <?php endif; ?>
                </div>
            </div>
            <div class="tl-item">
                <div class="tl-title"><?= icon('contactos', 14) ?> Contacto</div>
                <div class="tl-detail">
                    <?php if ($contactoExistente && $otraEmpresa): ?>
                        Ya existe un contacto con ese correo (<strong><?= e($contactoExistente['nombre_completo']) ?></strong>): se completa con lo que le falte y conserva su empresa actual, <strong><?= e($contactoExistente['cuenta']) ?></strong>.
                    <?php elseif ($contactoExistente): ?>
                        Ya existe un contacto con ese correo (<strong><?= e($contactoExistente['nombre_completo']) ?></strong>): en vez de crear otro, se completa con lo que le falte<?= !$empresaVacia && empty($contactoExistente['cuenta_id']) ? ' y se enlaza a la empresa' : '' ?>. No se pisa ningún dato cargado.
                    <?php else: ?>
                        Se crea el contacto <strong><?= e($nombre) ?></strong><?= $empresaVacia ? '' : ' dentro de esa empresa' ?>.
                    <?php endif; ?>
                </div>
            </div>
            <div class="tl-item">
                <div class="tl-title"><?= icon('nota', 14) ?> Notas, actividades, adjuntos y etiquetas</div>
                <div class="tl-detail">Pasan al contacto. Lo que no tiene lugar en su ficha (calificación, sector, empleados) queda escrito en una nota. El posible cliente no se borra: queda marcado como convertido.</div>
            </div>
        </div>
    </div>

    <?php if ($verNegocios): ?>
    <div class="card form-card">
        <h2 class="sec">Negocio (opcional)</h2>
        <?php if (!$hayEmbudo): ?>
            <p class="flash flash-warn">No hay un embudo predeterminado con etapas activas, así que no se puede crear el negocio. Se puede cargar después, desde la ficha del contacto.</p>
        <?php else: ?>
        <label class="switch mb-4">
            <input type="checkbox" name="crear_negocio" value="1" <?= $crearMarcado ? 'checked' : '' ?>>
            <span class="track"></span>
            <span>Crear también una oportunidad de venta</span>
        </label>
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre del negocio</span>
                <input name="negocio_nombre" value="<?= e($fv('negocio_nombre', (string)$p['empresa'])) ?>" maxlength="200" placeholder="Renovación de equipamiento">
                <i class="hint">Cómo se va a llamar la oportunidad en el embudo. Por ejemplo «Outsourcing 20 puestos».</i>
            </label>
            <label class="fld"><span>Importe estimado</span>
                <input name="negocio_importe" value="<?= e($fv('negocio_importe', '0')) ?>" inputmode="decimal" placeholder="1.500.000">
                <?php if (isset($errores['negocio_importe'])): ?><i class="hint bad"><?= e($errores['negocio_importe']) ?></i><?php endif; ?>
                <i class="hint">Cuánto vale si se cierra. Se aceptan puntos de miles y coma decimal: 1.500.000 o 1.234,56.</i>
            </label>
            <label class="fld"><span>Moneda</span>
                <select name="negocio_moneda">
                    <?php foreach (conversion_monedas() as $cod => $et): ?>
                        <option value="<?= e($cod) ?>" <?= $fv('negocio_moneda', 'ARS') === $cod ? 'selected' : '' ?>><?= e($et) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errores['negocio_moneda'])): ?><i class="hint bad"><?= e($errores['negocio_moneda']) ?></i><?php endif; ?>
                <i class="hint">En qué moneda está el importe. No se convierte: los totales se muestran por moneda.</i>
            </label>
            <label class="fld"><span>Fecha estimada de cierre</span>
                <input type="date" name="negocio_cierre" value="<?= e($fv('negocio_cierre', date('Y-m-d', strtotime('+30 days')))) ?>">
                <?php if (isset($errores['negocio_cierre'])): ?><i class="hint bad"><?= e($errores['negocio_cierre']) ?></i><?php endif; ?>
                <i class="hint">Cuándo esperás cerrarlo. Ubica el negocio en el pronóstico del mes.</i>
            </label>
        </div>
        <p class="muted small mt-3">
            El negocio arranca en la primera etapa del embudo. Podés cambiar el importe y la fecha cuando quieras.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn btn-accent"><?= icon('convertir', 16) ?>Convertir</button>
        <a class="btn btn-ghost" href="<?= e(url('posible-ver?id=' . (int)$p['id'])) ?>">Cancelar</a>
    </div>
</form>

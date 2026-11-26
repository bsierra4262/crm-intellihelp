<?php
// ============================================================
//  Vista: plantilla en texto plano. La pinta pages/plantilla-texto.php.
//  Variables:
//    $p            la plantilla (o los valores iniciales si es nueva).
//    $id           id; 0 si es nueva.
//    $errores      campo => mensaje.
//    $asuntoVista  el asunto con los datos de ejemplo.
//    $vistaTexto   el cuerpo con los datos de ejemplo y el renglón de baja.
//  Sin JavaScript: la vista previa se actualiza al guardar.
// ============================================================
/** @var array $p @var int $id @var array $errores @var string $asuntoVista @var string $vistaTexto */
$v = fn(string $k, $def = '') => e($p[$k] ?? $def);
?>
<div class="page-head">
    <div>
        <h1><?= $id ? 'Editar plantilla de texto' : 'Nueva plantilla de texto' ?></h1>
        <p class="muted">Un correo sin diseño, como uno escrito a mano. Sale tal cual lo escribís.</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('plantillas')) ?>">Volver</a>
        <button form="formTexto" class="btn btn-primary"><?= icon('ok', 16) ?>Guardar</button>
    </div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error">Revisá los campos marcados: <?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<form method="post" id="formTexto" class="form-wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="tipo" value="texto">
    <div class="card form-card">
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre de la plantilla <i class="req">*</i></span>
                <input name="nombre" value="<?= $v('nombre') ?>" maxlength="160" required placeholder="Retomando el contacto">
                <i class="hint">Para reconocerla al armar una campaña. El destinatario nunca lo ve.</i>
            </label>
            <label class="fld"><span>Carpeta</span>
                <input name="carpeta" value="<?= $v('carpeta') ?>" maxlength="80" placeholder="Prospección">
                <i class="hint">Para agrupar plantillas cuando ya hay muchas. Es opcional.</i>
            </label>
            <div class="fld abajo">
                <label class="switch">
                    <input type="checkbox" name="activo" value="1" <?= !empty($p['activo']) ? 'checked' : '' ?>>
                    <span class="track"></span><span>Disponible para campañas</span>
                </label>
            </div>
            <label class="fld full"><span>Asunto <i class="req">*</i></span>
                <input name="asunto" value="<?= $v('asunto') ?>" maxlength="255" required placeholder="Retomando el contacto">
                <i class="hint">Corto y sin mayúsculas ni signos de más: en el celular se corta cerca de los 45 caracteres.</i>
            </label>
            <label class="fld full"><span>Correo <i class="req">*</i></span>
                <textarea name="cuerpo_texto" rows="16" required class="mono-texto"><?= $v('cuerpo_texto') ?></textarea>
                <i class="hint">Escribilo con tu firma al final. Al pie se agrega solo un renglón para pedir la baja
                    respondiendo BAJA (el CRM la procesa sola). Variables:
                    <?php foreach (['{{nombre}}', '{{apellido}}', '{{empresa}}', '{{cargo}}', '{{agenda}}'] as $var): ?>
                        <code><?= e($var) ?></code>
                    <?php endforeach; ?>
                    — también sirve <code>@nombre</code>, como en Pulsemail. Sin nombre, «¡Hola {{nombre}}!» queda «¡Hola!».</i>
            </label>
        </div>
    </div>
</form>

<div class="card mb-4">
    <div class="card-head"><h2>Vista previa</h2><span class="small muted">Con datos de ejemplo (Juan, de ACME S.A.)</span></div>
    <p class="mb-3 m-0"><strong>Asunto:</strong> <?= e($asuntoVista) ?></p>
    <pre class="correo-texto"><?= e($vistaTexto) ?></pre>
</div>

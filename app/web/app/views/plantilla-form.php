<?php
/** @var ?array $p @var int $id @var array $errores */
$v = fn(string $k, $def = '') => e($p[$k] ?? $def);
?>
<div class="page-head">
    <div><h1><?= $id ? 'Editar plantilla' : 'Nueva plantilla' ?></h1></div>
    <div class="head-actions"><a class="btn btn-ghost" href="<?= e(url('plantillas')) ?>">Volver</a></div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error">Revisá los campos marcados: <?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">Identificación</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre <i class="req">*</i></span>
                <input name="nombre" value="<?= $v('nombre') ?>" maxlength="160" required autofocus>
            </label>
            <label class="fld"><span>Carpeta</span>
                <input name="carpeta" value="<?= $v('carpeta') ?>" maxlength="80" placeholder="Outsourcing, Consultoría…">
            </label>
            <div class="fld" style="justify-content:flex-end">
                <label class="switch">
                    <input type="checkbox" name="activo" value="1" <?= !empty($p['activo']) ? 'checked' : '' ?>>
                    <span class="track"></span><span>Disponible para campañas</span>
                </label>
            </div>
            <label class="fld full"><span>Asunto <i class="req">*</i></span>
                <input name="asunto" value="<?= $v('asunto') ?>" maxlength="255" required>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Variables disponibles</h2>
        <p class="muted small" style="margin:-.5rem 0 .8rem">
            Escribilas tal cual en el asunto o en el cuerpo; se reemplazan por los datos de cada destinatario.
        </p>
        <div class="kv">
            <?php foreach (variables_plantilla() as $var => $desc): ?>
                <div><div class="k"><code class="mono"><?= e($var) ?></code></div><div class="v"><?= e($desc) ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Cuerpo del correo</h2>
        <p class="muted small" style="margin:-.5rem 0 .8rem">
            Usá HTML con estilos <strong>en línea</strong>: Gmail y Outlook ignoran las hojas de estilo externas.
            Si no incluís <code class="mono">{{baja}}</code>, el sistema agrega igual el pie de baja al final: es obligatorio.
        </p>
        <label class="fld"><span>HTML <i class="req">*</i></span>
            <textarea name="cuerpo_html" rows="18" style="font-family:ui-monospace,Consolas,monospace;font-size:12.5px"><?= $v('cuerpo_html') ?></textarea>
        </label>
        <label class="fld" style="margin-top:1rem"><span>Versión en texto plano (opcional)</span>
            <textarea name="cuerpo_texto" rows="8"><?= $v('cuerpo_texto') ?></textarea>
            <i class="hint">Si la dejás vacía se genera sola a partir del HTML. Mandar las dos versiones reduce el riesgo de caer en spam.</i>
        </label>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar cambios' : 'Crear plantilla' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('plantillas')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($id && trim((string)($p['cuerpo_html'] ?? '')) !== ''): ?>
<div class="card" style="margin-top:1rem">
    <div class="card-head"><h2>Vista previa</h2><span class="small muted">Con datos de ejemplo</span></div>
    <div style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:1rem;background:#fff;overflow-x:auto">
        <?= render_plantilla((string)$p['cuerpo_html'], [
            'nombre' => 'Juan', 'apellido' => 'Pérez', 'empresa' => 'ACME S.A.',
            'cargo' => 'Gerente de Sistemas', 'email' => 'juan@acme.com',
            'remitente' => auth_user()['nombre'] ?? '', 'agenda' => '#', 'baja' => '#',
        ]) ?>
    </div>
</div>
<?php endif; ?>

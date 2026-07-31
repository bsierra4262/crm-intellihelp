<?php /** @var ?array $l @var int $id @var array $errores @var array $categorias */ ?>
<div class="page-head">
    <div><h1><?= $id ? 'Editar lista' : 'Nueva lista' ?></h1></div>
    <div class="head-actions"><a class="btn btn-ghost" href="<?= e(url('listas')) ?>">Volver</a></div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error"><?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>
    <div class="card form-card">
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre <i class="req">*</i></span>
                <input name="nombre" value="<?= e($l['nombre'] ?? '') ?>" maxlength="160" required autofocus>
                <i class="hint">Para reconocerla al armar una campaña. Por ejemplo «Petroleras Neuquén» o «Contactos de la feria».</i>
            </label>
            <label class="fld"><span>Segmento</span>
                <select name="categoria_id">
                    <option value="">— Sin segmento —</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= (string)($l['categoria_id'] ?? '') === (string)$cat['id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Agrupa las listas por rubro: RRHH, petroleras, bancos…</i>
            </label>
            <label class="fld full"><span>Descripción</span>
                <textarea name="descripcion" rows="3"><?= e($l['descripcion'] ?? '') ?></textarea>
                <i class="hint">De dónde salieron estos contactos y para qué sirve la lista. Es interno.</i>
            </label>
        </div>
    </div>
    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar' : 'Crear lista' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('listas')) ?>">Cancelar</a>
    </div>
</form>

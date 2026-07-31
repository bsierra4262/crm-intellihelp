<?php
/** @var ?array $p @var int $id @var array $errores @var array $datos @var string $vista
 *  @var string $asuntoVista @var array $chequeo @var array $preajustes @var array $imagenes */
$v = fn(string $k, $def = '') => e($p[$k] ?? $def);
?>
<div class="page-head">
    <div>
        <h1><?= $id ? 'Editar plantilla' : 'Nueva plantilla' ?></h1>
        <p class="muted">Armá el correo con bloques. Lo que ves es exactamente lo que se manda.</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('plantillas')) ?>">Volver</a>
        <button form="formPlantilla" class="btn btn-primary"><?= icon('ok', 16) ?>Guardar</button>
    </div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error">Revisá los campos marcados: <?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<form method="post" id="formPlantilla" class="form-wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="tipo" value="visual">
    <input type="hidden" name="preajuste" value="<?= $v('preajuste') ?>">
    <input type="hidden" name="bloques" id="campoBloques" value="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>">

    <div class="card form-card">
        <div class="grid-fields">
            <label class="fld wide"><span>Nombre de la plantilla <i class="req">*</i></span>
                <input name="nombre" value="<?= $v('nombre') ?>" maxlength="160" required>
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
            <label class="fld full"><span>Asunto del correo <i class="req">*</i></span>
                <input name="asunto" id="campoAsunto" value="<?= $v('asunto') ?>" maxlength="255" required>
                <i class="hint">Se puede personalizar con variables. Lo importante, al principio: en el celular se corta cerca de los 45 caracteres.</i>
            </label>
        </div>
    </div>
</form>

<div class="editor">
    <!-- ---------- Paleta ---------- -->
    <aside class="ed-paleta card">
        <h2 class="sec">Agregar bloque</h2>
        <?php foreach (bloques_tipos() as $clave => $t): ?>
            <button type="button" class="ed-add" data-agregar="<?= e($clave) ?>" title="<?= e($t['ayuda']) ?>">
                <?= icon($t['icono'], 16) ?>
                <span><strong><?= e($t['nombre']) ?></strong><small><?= e($t['ayuda']) ?></small></span>
            </button>
        <?php endforeach; ?>

        <h2 class="sec" style="margin-top:1.4rem">Variables</h2>
        <p class="muted small" style="margin:-.4rem 0 .6rem">Tocá una para copiarla.</p>
        <div class="ed-vars">
            <?php foreach (variables_plantilla() as $var => $desc): ?>
                <button type="button" class="chip ed-var" data-var="<?= e($var) ?>" title="<?= e($desc) ?>"><?= e($var) ?></button>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- ---------- Lienzo ---------- -->
    <section class="ed-lienzo card">
        <div class="card-head">
            <h2>Bloques del correo</h2>
            <span class="small muted">Arrastrá para reordenar</span>
        </div>
        <div id="lienzo" class="ed-lista"></div>
        <p class="muted small" id="lienzoVacio" hidden>
            Todavía no hay bloques. Agregá uno desde la izquierda.
        </p>
    </section>

    <!-- ---------- Propiedades ---------- -->
    <aside class="ed-props card">
        <div class="card-head"><h2 id="propTitulo">Ajustes del correo</h2></div>
        <div id="propCuerpo"></div>
    </aside>
</div>

<!-- ---------- Vista previa + control anti-spam ---------- -->
<div class="rep-grid" style="margin-top:1rem;align-items:start">
    <div class="card">
        <div class="card-head">
            <h2>Vista previa</h2>
            <div class="seg">
                <button type="button" class="seg-item active" data-vista="escritorio">Escritorio</button>
                <button type="button" class="seg-item" data-vista="movil">Celular</button>
            </div>
        </div>
        <p class="small muted" style="margin:0 0 .6rem">
            <strong>Asunto:</strong> <span id="vistaAsunto"><?= e($asuntoVista) ?></span>
        </p>
        <div class="ed-preview" id="preview">
            <iframe id="previewFrame" title="Vista previa del correo"></iframe>
        </div>
        <p class="small muted" style="margin:.6rem 0 0">
            Se ve con datos de ejemplo (Juan Pérez, de ACME S.A.). En el envío real se reemplazan
            por los datos de cada destinatario.
        </p>
    </div>

    <div class="card" id="panelChequeo">
        <div class="card-head">
            <h2>Control de entregabilidad</h2>
            <span class="small muted" id="chequeoResumen"></span>
        </div>
        <div id="chequeoCuerpo"></div>
        <p class="small muted" style="margin-top:.8rem">
            Estas reglas no son opiniones: son los límites reales de los clientes de correo
            y de los filtros de spam. Un correo que las respeta llega; uno que no, se pierde.
        </p>
    </div>
</div>

<script>
window.__editor = {
    datos: <?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    tipos: <?= json_encode(bloques_tipos(), JSON_UNESCAPED_UNICODE) ?>,
    imagenes: <?= json_encode(array_map(fn($i) => [
        'url' => url_abs('img?a=' . $i['archivo']), 'nombre' => $i['nombre']], $imagenes), JSON_UNESCAPED_SLASHES) ?>,
    urlVista: <?= json_encode(url('plantilla-vista')) ?>,
    urlImagenes: <?= json_encode(url('imagenes')) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    chequeo: <?= json_encode($chequeo, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script defer src="<?= e(url('assets/js/editor.js')) ?>?v=<?= ASSETS_V ?>"></script>

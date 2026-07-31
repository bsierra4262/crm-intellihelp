<?php
/** @var ?array $a @var int $id @var array $errores @var array $usuarios
 *  @var array $contactos @var array $cuentas @var array $negocios */
$v = fn(string $k, $def = '') => e($a[$k] ?? $def);
// Los datetime-local necesitan "Y-m-dTH:i".
$dt = function (?string $f) {
    if (!$f) { return ''; }
    $t = strtotime($f);
    return $t ? date('Y-m-d\TH:i', $t) : '';
};
$tipo = $a['tipo'] ?? 'tarea';
?>
<div class="page-head">
    <div><h1><?= $id ? 'Editar actividad' : 'Nueva actividad' ?></h1></div>
    <div class="head-actions"><a class="btn btn-ghost" href="<?= e(url('actividades')) ?>">Cancelar</a></div>
</div>

<?php if ($errores): ?>
    <div class="flash flash-error">Revisá los campos marcados: <?= e(implode(' ', $errores)) ?></div>
<?php endif; ?>

<form method="post" class="form-wrap">
    <?= csrf_field() ?>

    <div class="card form-card">
        <h2 class="sec">Qué hay que hacer</h2>
        <div class="grid-fields">
            <label class="fld"><span>Tipo</span>
                <select name="tipo" id="tipoActividad">
                    <?php foreach (tipos_actividad() as $k => $et): ?>
                        <option value="<?= e($k) ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= e($et) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Una tarea tiene vencimiento; una llamada y una reunión tienen día y hora.</i>
            </label>
            <label class="fld wide"><span>Asunto <i class="req">*</i></span>
                <input name="asunto" value="<?= $v('asunto') ?>" maxlength="200" required autofocus>
                <?php if (isset($errores['asunto'])): ?><i class="hint bad"><?= e($errores['asunto']) ?></i><?php endif; ?>
            </label>
            <label class="fld"><span>Responsable</span>
                <select name="propietario_id">
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)($a['propietario_id'] ?? current_user_id()) === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Quién la tiene que hacer.</i>
            </label>

            <label class="fld" data-solo="tarea"><span>Vence el</span>
                <input type="datetime-local" name="vence_el" value="<?= e($dt($a['vence_el'] ?? null)) ?>">
            </label>
            <label class="fld" data-solo="llamada reunion"><span>Empieza</span>
                <input type="datetime-local" name="inicia_el" value="<?= e($dt($a['inicia_el'] ?? null)) ?>">
            </label>
            <label class="fld" data-solo="reunion"><span>Termina</span>
                <input type="datetime-local" name="termina_el" value="<?= e($dt($a['termina_el'] ?? null)) ?>">
                <i class="hint">Si lo dejás vacío se toman 30 minutos.</i>
            </label>
            <label class="fld" data-solo="reunion"><span>Lugar</span>
                <input name="lugar" value="<?= $v('lugar') ?>" maxlength="200" placeholder="Oficina, Google Meet…">
            </label>
            <label class="fld" data-solo="llamada"><span>Sentido</span>
                <select name="sentido">
                    <option value="">—</option>
                    <option value="saliente" <?= ($a['sentido'] ?? '') === 'saliente' ? 'selected' : '' ?>>Saliente</option>
                    <option value="entrante" <?= ($a['sentido'] ?? '') === 'entrante' ? 'selected' : '' ?>>Entrante</option>
                </select>
            </label>
            <label class="fld" data-solo="llamada"><span>Resultado</span>
                <select name="resultado">
                    <option value="">— Sin resultado —</option>
                    <?php foreach (resultados_llamada() as $r): ?>
                        <option value="<?= e($r) ?>" <?= ($a['resultado'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld" data-solo="llamada reunion"><span>Duración (min)</span>
                <input type="number" name="duracion_min" value="<?= $v('duracion_min') ?>" min="1" step="1">
            </label>

            <label class="fld"><span>Estado</span>
                <select name="estado">
                    <?php foreach (estados_actividad() as $x): ?>
                        <option value="<?= e($x) ?>" <?= ($a['estado'] ?? 'No iniciada') === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">En qué punto está. Al marcarla «Completada» sale de las pendientes y del contador de vencidas.</i>
            </label>
            <label class="fld"><span>Prioridad</span>
                <select name="prioridad">
                    <?php foreach (prioridades() as $x): ?>
                        <option value="<?= e($x) ?>" <?= ($a['prioridad'] ?? 'Normal') === $x ? 'selected' : '' ?>><?= e($x) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Para ordenar qué se hace primero cuando hay varias del mismo día.</i>
            </label>
            <label class="fld"><span>Recordar (min antes)</span>
                <input type="number" name="recordar_min" value="<?= $v('recordar_min') ?>" min="0" step="5" placeholder="60">
                <i class="hint">Cuántos minutos antes avisar. Dejalo vacío para no recibir aviso.</i>
            </label>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="sec">Con quién</h2>
        <div class="grid-fields">
            <label class="fld wide"><span>Contacto</span>
                <select name="contacto_id">
                    <option value="">— Ninguno —</option>
                    <?php foreach ($contactos as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (string)($a['contacto_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre_completo']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">Con quién es. Al elegirlo, la actividad aparece en la ficha de esa persona.</i>
            </label>
            <label class="fld wide"><span>Empresa</span>
                <select name="cuenta_id">
                    <option value="">— Ninguna —</option>
                    <?php foreach ($cuentas as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (string)($a['cuenta_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">La empresa, si la actividad no es con una persona en particular.</i>
            </label>
            <label class="fld wide"><span>Negocio</span>
                <select name="negocio_id">
                    <option value="">— Ninguno —</option>
                    <?php foreach ($negocios as $n): ?>
                        <option value="<?= (int)$n['id'] ?>" <?= (string)($a['negocio_id'] ?? '') === (string)$n['id'] ? 'selected' : '' ?>><?= e($n['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="hint">El negocio al que empuja esta actividad, si aplica.</i>
            </label>
            <input type="hidden" name="posible_cliente_id" value="<?= $v('posible_cliente_id') ?>">
            <label class="fld full"><span>Detalle</span>
                <textarea name="descripcion" rows="4"><?= $v('descripcion') ?></textarea>
                <i class="hint">De qué se trata, o qué salió. Es interno.</i>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= icon('ok', 16) ?><?= $id ? 'Guardar cambios' : 'Crear actividad' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('actividades')) ?>">Cancelar</a>
    </div>
</form>

<script>
// Mostrar sólo los campos que aplican al tipo elegido: una tarea no tiene
// duración ni resultado, y una llamada no tiene fecha de fin.
(function () {
    var sel = document.getElementById('tipoActividad');
    function aplicar() {
        document.querySelectorAll('[data-solo]').forEach(function (el) {
            el.style.display = el.getAttribute('data-solo').split(' ').indexOf(sel.value) >= 0 ? '' : 'none';
        });
    }
    sel.addEventListener('change', aplicar);
    aplicar();
})();
</script>

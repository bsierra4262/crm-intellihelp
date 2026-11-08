<?php /** @var ?array $r @var ?array $tipo @var bool $ok @var ?string $error @var string $formToken */
$tipoZona = $tipo ?? [];
?>
<div class="pub-wrap angosta">
    <div class="pub-card">
        <div class="pub-head">
            <img src="<?= e(url('assets/img/ITH-logo-139x75.png')) ?>" alt="INTELLIHELP">
            <div><h1>Cancelar la reunión</h1></div>
        </div>
        <div class="pub-pad">
            <?php if (!$r): ?>
                <div class="flash flash-error">El enlace no es válido.</div>
            <?php elseif ($ok || $r['estado'] === 'cancelada'): ?>
                <div class="flash flash-success">La reunión quedó cancelada. Te mandamos la confirmación por correo.</div>
            <?php elseif ($r['estado'] !== 'confirmada'): ?>
                <div class="flash flash-info">Esta reunión ya no está activa.</div>
            <?php else: $local = agenda_local(strtotime((string)$r['inicia_el']), $tipoZona); ?>
                <?php if (!empty($error)): ?>
                    <div class="flash flash-error mb-3" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <p>Vas a cancelar la reunión del
                    <strong><?= e(fecha_larga($local)) ?>
                    a las <?= e(substr($local, 11, 5)) ?> h</strong>
                    <span class="muted small">(<?= e(mb_strtolower(agenda_zona_etiqueta($tipoZona))) ?>)</span>.</p>
                <p class="small muted">
                    ¿Preferís otro día? <a href="<?= e(url_abs('r/mover?t=' . $r['token'])) ?>">Cambiá el horario</a>
                    en lugar de cancelar.
                </p>
                <form method="post" class="mt-4">
                    <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
                    <label class="fld"><span>Motivo (opcional)</span>
                        <textarea name="motivo" rows="3" maxlength="1000" placeholder="Nos ayuda a mejorar"></textarea>
                        <i class="hint">Si nos contás por qué, podemos proponerte otro horario.</i>
                    </label>
                    <button class="btn btn-danger btn-block mt-4">Cancelar la reunión</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

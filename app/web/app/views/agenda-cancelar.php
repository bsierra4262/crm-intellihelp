<?php /** @var ?array $r @var ?array $tipo @var bool $ok @var bool $avisado @var ?string $error @var string $formToken */
// ============================================================
//  Página pública /r/cancelar?t=<token> (sin login; la dibuja pages/r.php).
//
//  Recibe:
//    · $r ......... la reserva vigente de la cadena del token, o null si el token no existe
//    · $tipo ...... su tipo con quien la atiende (null si el tipo se borró)
//    · $ok ........ true si se acaba de cancelar en este pedido
//    · $avisado ... true si en este pedido salió el correo de la cancelación
//    · $error ..... aviso del token de formulario o del límite de intentos
//    · $formToken . token firmado de un solo uso para el POST ('cancelar')
//
//  Abrir el enlace (GET) no cancela nada: hace falta confirmar con el botón,
//  así un antivirus o un programa que abre los enlaces del correo no da de
//  baja la reunión. El formulario se manda a la misma URL (con el token).
//  Si $tipo es null, la hora se muestra en la zona por defecto.
// ============================================================
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
            <?php // El correo sólo se promete si salió en este pedido. ?>
            <?php elseif ($ok): ?>
                <div class="flash flash-success">La reunión quedó cancelada.<?= !empty($avisado) ? ' Te mandamos la confirmación por correo.' : '' ?></div>
            <?php elseif ($r['estado'] === 'cancelada'): ?>
                <div class="flash flash-info">Esta reunión ya estaba cancelada. No tenés que hacer nada más.</div>
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

<?php
/**
 * Aviso de que Google Calendar no está respondiendo.
 *
 * Existe porque el problema real se dio así: la API de Calendar estaba
 * deshabilitada en el proyecto de Google Cloud, la agenda pública seguía
 * tomando reservas, al visitante le decíamos «quedó agendada» y en el
 * calendario no aparecía nada. El error quedaba sólo en la auditoría.
 * Ahora se ve donde se trabaja, con el paso exacto para resolverlo.
 */
$gcalFalla = gcal_falla_pendiente();
if (!$gcalFalla) { return; }
?>
<div class="flash flash-error">
    <strong>Google Calendar no está respondiendo.</strong>
    Las reuniones se siguen guardando en el CRM, pero <strong>no se crean en el calendario
    ni generan el Meet</strong>, y la agenda pública deja de ofrecer horarios para no
    encimarte reuniones.
    <?php if (!empty($gcalFalla['apagada'])): ?>
        <br>
        La causa es que <strong>la API de Google Calendar está apagada</strong> en el proyecto
        de Google Cloud. Se prende una sola vez:
        <?php if (!empty($gcalFalla['enlace'])): ?>
            <a href="<?= e($gcalFalla['enlace']) ?>" target="_blank" rel="noopener">abrí este enlace</a>
            y tocá <em>Habilitar</em>. Tarda unos minutos en tomar efecto.
        <?php else: ?>
            entrá a Google Cloud → APIs y servicios → Biblioteca → Google Calendar API → Habilitar.
        <?php endif; ?>
    <?php else: ?>
        <br>Google respondió: <em><?= e(recortar((string)($gcalFalla['mensaje'] ?? ''), 200)) ?></em>
    <?php endif; ?>
    <br><span class="small">Detectado el <?= e(fecha_hora((string)($gcalFalla['cuando'] ?? ''))) ?>.
    El aviso desaparece solo cuando Google vuelva a responder.</span>
</div>

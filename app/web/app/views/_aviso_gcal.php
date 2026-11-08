<?php
/**
 * Aviso de que Google Calendar no está respondiendo.
 *
 * Se muestra cuando Google Calendar no responde (por ejemplo, con la API
 * deshabilitada en el proyecto de Google Cloud). Sin este aviso la agenda
 * pública seguiría confirmando reservas que no llegan al calendario y el
 * error quedaría sólo en la auditoría. Indica el paso para resolverlo.
 */
$gcalFalla = gcal_falla_pendiente();
if (!$gcalFalla) { return; }

// La causa más común no es que Google esté caído, sino que esta cuenta perdió
// la conexión: pasa al cerrar sesión, al borrar la caché o después de reiniciar
// la instalación de pruebas. Se detecta por el 401 y por el texto que devuelve
// Google, y tiene arreglo de un clic: reconectar. Un aviso que sólo dice que
// algo anda mal, sin decir cómo salir, obliga a preguntar.
// Este archivo se incluye dentro de otra vista y comparte su ámbito: todas sus
// variables llevan el prefijo $gcal para no pisar las de la vista que lo incluye.
$gcalMsg = mb_strtolower((string)($gcalFalla['mensaje'] ?? ''));
$gcalDesconectado = str_contains($gcalMsg, 'no tiene su cuenta de google conectada')
                 || str_contains($gcalMsg, '401')
                 || str_contains($gcalMsg, 'invalid_grant');
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
    <?php elseif ($gcalDesconectado): ?>
        <br>
        La causa es que <strong>esta cuenta perdió la conexión con Google</strong>. Pasa al cerrar
        sesión, al borrar la caché del navegador o al entrar desde otra computadora.
        Se arregla en un clic:
        <a class="btn btn-sm btn-primary ml-2" href="<?= e(url('google-login?conectar=1')) ?>">
            <?= icon('refrescar', 15) ?>Reconectar con Google
        </a>
    <?php else: ?>
        <br>Google respondió: <em><?= e(recortar((string)($gcalFalla['mensaje'] ?? ''), 200)) ?></em>
    <?php endif; ?>
    <br><span class="small">Detectado el <?= e(fecha_hora((string)($gcalFalla['cuando'] ?? ''))) ?>.
    El aviso desaparece solo cuando Google vuelva a responder.</span>
</div>

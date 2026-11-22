<?php
/**
 * Aviso de que Google Calendar no está respondiendo.
 *
 * Se muestra cuando Google Calendar no responde (por ejemplo, con la API
 * deshabilitada en el proyecto de Google Cloud). Sin este aviso la agenda
 * pública seguiría confirmando reservas que no llegan al calendario y el
 * error quedaría sólo en la auditoría. Indica el paso para resolverlo.
 *
 * Se incluye (include) desde views/dashboard.php, views/agenda.php y
 * views/ajustes.php; no recibe variables, lee la falla con
 * gcal_falla_pendiente() (ajuste `gcal_falla_u<id>` de cada vendedor,
 * gcal.php). Si no hay falla termina con `return` y no pinta nada.
 *
 * La falla es de UN vendedor. Cada uno ve la suya; un administrador ve
 * además la de otro, con el nombre del afectado. El botón «Reconectar» sólo
 * aparece en la propia: conecta la cuenta de quien lo toca, así que en la
 * falla de otro conectaría la cuenta equivocada.
 */
$gcalFalla = gcal_falla_pendiente();
if (!$gcalFalla) { return; }

// La causa más común no es que Google esté caído, sino que la cuenta perdió
// la conexión: pasa al cerrar sesión, al borrar la caché, al revocar el
// permiso o después de reiniciar la instalación de pruebas. Se detecta por el
// código 401 que arma gcal_api() cuando no hay token y por el TEXTO del
// mensaje guardado: el de gcal_api(), un «invalid_grant», o el de permisos
// destildados al conectar (google-callback.php). Tiene arreglo de un clic:
// reconectar. Un aviso que sólo dice que algo anda mal, sin decir cómo salir,
// obliga a preguntar.
// Este archivo se incluye dentro de otra vista y comparte su ámbito: todas sus
// variables llevan el prefijo $gcal para no pisar las de la vista que lo incluye.
$gcalMsg = mb_strtolower((string)($gcalFalla['mensaje'] ?? ''));
$gcalDesconectado = (int)($gcalFalla['codigo'] ?? 0) === 401
                 || str_contains($gcalMsg, 'no tiene su cuenta de google conectada')
                 || str_contains($gcalMsg, 'invalid_grant')
                 || str_contains($gcalMsg, 'falta el permiso de google calendar');
$gcalPropia = !empty($gcalFalla['propia']);
$gcalQuien  = (string)($gcalFalla['usuario_nombre'] ?? '') !== '' ? (string)$gcalFalla['usuario_nombre'] : 'otro usuario';
?>
<div class="flash flash-error">
    <?php if ($gcalPropia): ?>
        <strong>Google Calendar no está respondiendo.</strong>
    <?php else: ?>
        <strong>El Google Calendar de <?= e($gcalQuien) ?> no está respondiendo.</strong>
    <?php endif; ?>
    Las reuniones se siguen guardando en el CRM, pero <strong>no se crean en el calendario
    ni generan el Meet</strong>, y la agenda pública deja de ofrecer horarios para no
    encimar reuniones.
    <?php if (!empty($gcalFalla['apagada'])): ?>
        <br>
        La causa es que <strong>la API de Google Calendar está apagada</strong> en el proyecto
        de Google Cloud. Se prende una sola vez:
        <?php // El enlace sale del mensaje de Google, pero sólo si apunta a console.cloud/developers.google.com (gcal_falla_pendiente). ?>
        <?php if (!empty($gcalFalla['enlace'])): ?>
            <a href="<?= e($gcalFalla['enlace']) ?>" target="_blank" rel="noopener">abrí este enlace</a>
            y tocá <em>Habilitar</em>. Tarda unos minutos en tomar efecto.
        <?php else: ?>
            entrá a Google Cloud → APIs y servicios → Biblioteca → Google Calendar API → Habilitar.
        <?php endif; ?>
    <?php elseif ($gcalDesconectado && $gcalPropia): ?>
        <br>
        La causa es que <strong>esta cuenta perdió la conexión con Google</strong>. Pasa al cerrar
        sesión, al borrar la caché del navegador, al entrar desde otra computadora o si al
        conectar se destildó el acceso al calendario. Se arregla en un clic:
        <a class="btn btn-sm btn-primary ml-2" href="<?= e(url('google-login?conectar=1')) ?>">
            <?= icon('refrescar', 15) ?>Reconectar con Google
        </a>
    <?php elseif ($gcalDesconectado): ?>
        <br>
        La causa es que <strong>la cuenta de <?= e($gcalQuien) ?> perdió la conexión con Google</strong>.
        Tiene que entrar al CRM con su usuario y reconectar desde la pantalla de la agenda.
    <?php else: ?>
        <br>Google respondió: <em><?= e(recortar((string)($gcalFalla['mensaje'] ?? ''), 200)) ?></em>
    <?php endif; ?>
    <br><span class="small">Detectado el <?= e(fecha_hora((string)($gcalFalla['cuando'] ?? ''))) ?>.
    El aviso desaparece solo cuando Google vuelva a responder.</span>
</div>

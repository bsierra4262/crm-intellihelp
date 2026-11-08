<?php
declare(strict_types=1);
require_login();

// La conversación con una persona: lo que escribió y lo que le contestamos.
// Se entra por correo y no por id de contacto: quien responde una campaña
// puede no estar cargado como contacto, y su respuesta no se puede perder por
// eso.

$email = normalizar_email((string)($_GET['email'] ?? $_POST['email'] ?? ''));

if (is_post()) {
    csrf_check();
    try {
        $id = (int)($_POST['mensaje_id'] ?? 0);
        mensaje_responder($id, (string)($_POST['texto'] ?? ''));
        flash('Respuesta enviada.', 'success');
    } catch (\Throwable $e) {
        flash('No se pudo enviar: ' . $e->getMessage(), 'error');
    }
    redirect('conversacion?email=' . urlencode($email));
}

if ($email === '' || !email_valido($email)) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Conversación inexistente',
        'mensaje' => 'Hace falta la dirección de correo de la persona.'], 'No encontrado');
    exit;
}

$mensajes = conversacion_de($email);
if (!$mensajes) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Todavía no hay conversación',
        'mensaje' => 'No hay ningún correo guardado con ' . $email . '.'], 'No encontrado');
    exit;
}

// Abrir la conversación la marca como leída: no hace falta un botón aparte.
conversacion_marcar_leida($email);

$contacto = q1('SELECT * FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$email]);

render('conversacion', [
    'email'    => $email,
    'mensajes' => $mensajes,
    'contacto' => $contacto,
    // El último que entró es al que se le contesta: encadena el hilo bien.
    'ultimo'   => array_values(array_filter($mensajes, fn($m) => $m['direccion'] === 'entrante'))[count(array_filter($mensajes, fn($m) => $m['direccion'] === 'entrante')) - 1] ?? null,
], 'Conversación con ' . ($contacto['nombre_completo'] ?? $email));

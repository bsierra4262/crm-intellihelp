<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta `conversacion?email=<correo>` (módulo Campañas; cualquier usuario
//  con sesión, sin chequeo de dueño de la campaña).
//
//  La conversación con una persona: lo que escribió y lo que le contestamos.
//  Se entra por correo y no por id de contacto: quien responde una campaña
//  puede no estar cargado como contacto, y su respuesta no se puede perder por
//  eso. Se llega desde las notificaciones de respuesta (marcar_respuesta) y
//  desde el detalle de la campaña.
//
//  GET: muestra el hilo (conversacion_de) y lo marca como leído.
//  POST: contesta el mensaje `mensaje_id` con `texto` (mensaje_responder) y
//  vuelve a la misma conversación con un aviso (patrón POST-redirect-GET).
//
//  Vista: views/conversacion.php, con $email, $mensajes, $contacto y $ultimo.
//  Lógica en app/mensajes.php.
// ============================================================

$email = normalizar_email((string)($_GET['email'] ?? $_POST['email'] ?? ''));

if (is_post()) {
    // Ojo: csrf_check() no está definida en ningún archivo (la del sistema es
    // verify_csrf()). Así como está, el POST termina en error fatal antes de
    // mandar nada.
    csrf_check();
    // El mensaje_id viene del formulario y no se comprueba que pertenezca a
    // esta conversación: la respuesta va al remitente de ESE mensaje.
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
    // Se filtran los entrantes y se toma el último; si no hay ninguno (sólo
    // salientes), queda null y la vista no muestra el formulario.
    'ultimo'   => array_values(array_filter($mensajes, fn($m) => $m['direccion'] === 'entrante'))[count(array_filter($mensajes, fn($m) => $m['direccion'] === 'entrante')) - 1] ?? null,
], 'Conversación con ' . ($contacto['nombre_completo'] ?? $email));

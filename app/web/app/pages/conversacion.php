<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta `conversacion?email=<correo>` (módulo Campañas; cualquier usuario
//  con sesión: la base se comparte en el equipo y cualquiera puede atender
//  una respuesta).
//
//  La conversación con una persona: lo que escribió y lo que le contestamos.
//  Se entra por correo y no por id de contacto: quien responde una campaña
//  puede no estar cargado como contacto, y su respuesta no se puede perder por
//  eso. Se llega desde las notificaciones de respuesta (marcar_respuesta) y
//  desde el detalle de la campaña.
//
//  GET: muestra el hilo (conversacion_de) y lo marca como leído.
//  POST: contesta el mensaje `mensaje_id` con `texto` (conversacion_responder,
//  que comprueba que el mensaje sea de esta conversación) y vuelve a la misma
//  conversación con un aviso (patrón POST-redirect-GET).
//
//  Vista: views/conversacion.php, con $email, $mensajes, $contacto, $ultimo y
//  $limite. Lógica en app/mensajes.php.
// ============================================================

$email = normalizar_email((string)($_GET['email'] ?? $_POST['email'] ?? ''));

if (is_post()) {
    verify_csrf();
    [$tipo, $texto] = conversacion_responder($_POST, $email);
    flash($texto, $tipo);
    redirect('conversacion?email=' . urlencode($email));
}

if ($email === '' || !email_valido($email)) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Conversación inexistente',
        'mensaje' => 'Hace falta la dirección de correo de la persona.'], 'No encontrado');
    exit;
}

// Los últimos $limite mensajes: en un hilo más largo se dejan afuera los
// primeros, nunca los que hay que contestar.
$limite   = 100;
$mensajes = conversacion_de($email, $limite);
if (!$mensajes) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Todavía no hay conversación',
        'mensaje' => 'No hay ningún correo guardado con ' . $email . '.'], 'No encontrado');
    exit;
}

// Abrir la conversación la marca como leída: no hace falta un botón aparte.
conversacion_marcar_leida($email);

$contacto = q1('SELECT * FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$email]);

// El último que entró es al que se le contesta: encadena el hilo bien. Si no
// hay ninguno entrante (sólo salientes), queda null y la vista no muestra el
// formulario.
$entrantes = array_values(array_filter($mensajes, fn($m) => $m['direccion'] === 'entrante'));

render('conversacion', [
    'email'    => $email,
    'mensajes' => $mensajes,
    'contacto' => $contacto,
    'ultimo'   => $entrantes ? $entrantes[count($entrantes) - 1] : null,
    'limite'   => $limite,
], 'Conversación con ' . ($contacto['nombre_completo'] ?? $email));

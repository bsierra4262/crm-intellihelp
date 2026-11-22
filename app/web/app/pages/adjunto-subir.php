<?php
declare(strict_types=1);

// ============================================================
//  Ruta: POST /adjunto-subir (requiere sesión + CSRF; módulo Adjuntos).
//
//  Dos acciones en un mismo endpoint, desde views/_adjuntos.php:
//    · accion=borrar + id: borra el archivo del disco (sin papelera);
//    · si no: sube `archivo` (multipart) para entidad + entidad_id.
//  Ninguna de las dos mira quién subió el archivo ni de quién es el registro.
//  Los errores (archivo grande, sin archivo) vuelven como aviso flash.
//  Vuelve a la página anterior según la cabecera Referer.
// ============================================================
require_login();

if (!is_post()) { redirect('dashboard'); }
verify_csrf();

try {
    if (($_POST['accion'] ?? '') === 'borrar') {
        adjunto_borrar((int)($_POST['id'] ?? 0));
        flash('Archivo borrado.', 'success');
    } else {
        $entidad = (string)($_POST['entidad'] ?? '');
        $id      = (int)($_POST['entidad_id'] ?? 0);
        if (!$id || !entidad_valida($entidad)) {
            throw new RuntimeException('No se pudo subir: registro desconocido.');
        }
        adjunto_guardar($entidad, $id, (array)($_FILES['archivo'] ?? []));
        flash('Archivo subido.', 'success');
    }
} catch (\Throwable $e) {
    // Se muestra el mensaje de la excepción tal cual: las de adjunto_guardar
    // están escritas para la persona, pero un error de base también llegaría
    // a la pantalla con su texto técnico.
    flash($e->getMessage(), 'error');
}

$volver = (string)($_SERVER['HTTP_REFERER'] ?? '');
header('Location: ' . ($volver !== '' ? $volver : url('dashboard')));
exit;

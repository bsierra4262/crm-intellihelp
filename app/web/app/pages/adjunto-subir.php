<?php
declare(strict_types=1);

// ============================================================
//  Ruta: POST /adjunto-subir (requiere sesión + CSRF; módulo Adjuntos).
//
//  Tres acciones en un mismo endpoint:
//    · accion=borrar + id: manda el archivo a la papelera (borrado lógico).
//      Sólo su autor o un administrador (puede_borrar_adjunto);
//    · accion=restaurar + id: lo saca de la papelera. Sólo administrador
//      (viene de la solapa Archivos de la papelera);
//    · si no: sube `archivo` (multipart) para entidad + entidad_id, que
//      tiene que existir, no estar en la papelera y ser de un módulo encendido.
//  Los errores (archivo grande, sin archivo) vuelven como aviso flash; un
//  error inesperado (de base, por ejemplo) se registra y se muestra un texto
//  claro, nunca el mensaje técnico.
//  Vuelve a la ficha del registro (o a la papelera, al restaurar); no usa la
//  cabecera Referer, que la escribe el navegador.
// ============================================================
require_login();

if (!is_post()) { redirect('dashboard'); }
verify_csrf();

$accion = (string)($_POST['accion'] ?? '');
$volver = url('dashboard');

try {
    if ($accion === 'borrar' || $accion === 'restaurar') {
        $a = q1('SELECT * FROM adjuntos WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if (!$a) {
            flash('Ese archivo ya no existe.', 'info');
        } elseif ($accion === 'restaurar') {
            require_admin();
            $volver = url('papelera?entidad=adjunto');
            $ok = adjunto_restaurar((int)$a['id']);
            flash($ok ? 'Archivo restaurado.' : 'No se pudo restaurar: el archivo ya no está en el servidor.',
                  $ok ? 'success' : 'error');
        } else {
            $volver = url_registro((string)$a['entidad'], (int)$a['entidad_id']);
            if (!puede_borrar_adjunto($a, current_user_id(), auth_is_admin())) {
                flash('Ese archivo lo subió otra persona: sólo ella o un administrador lo pueden borrar.', 'error');
            } else {
                adjunto_borrar((int)$a['id']);
                flash('Archivo enviado a la papelera. Un administrador puede restaurarlo.', 'success');
            }
        }
    } else {
        $entidad = (string)($_POST['entidad'] ?? '');
        $id      = (int)($_POST['entidad_id'] ?? 0);
        if (!$id || !entidad_valida($entidad) || !entidad_habilitada($entidad) || !registro_vivo($entidad, $id)) {
            throw new RuntimeException('No se pudo subir: el registro no existe o está en la papelera.');
        }
        $volver = url_registro($entidad, $id);
        adjunto_guardar($entidad, $id, (array)($_FILES['archivo'] ?? []));
        flash('Archivo subido.', 'success');
    }
} catch (\PDOException $e) {
    // PDOException hereda de RuntimeException: va primero para que su texto
    // técnico no llegue a la pantalla.
    log_error('adjunto.error', 'PDOException: ' . $e->getMessage(), $e->getFile(), $e->getLine());
    flash('No se pudo completar la acción con el archivo. El error quedó registrado; probá de nuevo.', 'error');
} catch (\RuntimeException $e) {
    // Las de adjunto_guardar y las de arriba están escritas para la persona.
    flash($e->getMessage(), 'error');
} catch (\Throwable $e) {
    log_error('adjunto.error', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    flash('No se pudo completar la acción con el archivo. El error quedó registrado; probá de nuevo.', 'error');
}

header('Location: ' . $volver);
exit;

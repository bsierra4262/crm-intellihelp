<?php
declare(strict_types=1);
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
    flash($e->getMessage(), 'error');
}

$volver = (string)($_SERVER['HTTP_REFERER'] ?? '');
header('Location: ' . ($volver !== '' ? $volver : url('dashboard')));
exit;

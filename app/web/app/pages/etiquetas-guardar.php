<?php
declare(strict_types=1);
require_login();

// Guarda las etiquetas de un registro. Las funciones existían desde el
// principio y no las llamaba ninguna pantalla.

if (!is_post()) { redirect('dashboard'); }
verify_csrf();

$entidad = (string)($_POST['entidad'] ?? '');
$id      = (int)($_POST['id'] ?? 0);

if (!$id || !entidad_valida($entidad)) {
    flash('No se pudo guardar: registro desconocido.', 'error');
} else {
    $nombres = preg_split('/\s*,\s*/', (string)($_POST['etiquetas'] ?? '')) ?: [];
    etiquetas_asignar($entidad, $id, $nombres);
    $n = count(array_filter(array_map('trim', $nombres)));
    log_app($entidad . '.etiquetas', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => $n . ' etiqueta(s)']);
    flash($n ? 'Etiquetas guardadas.' : 'Se quitaron todas las etiquetas.', 'success');
}

header('Location: ' . url_registro($entidad, $id));
exit;

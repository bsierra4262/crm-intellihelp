<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: POST /etiquetas-guardar (requiere sesión + CSRF).
//
//  Guarda las etiquetas de un registro. Las funciones existían desde el
//  principio y no las llamaba ninguna pantalla.
//
//  Campos: entidad, id y etiquetas (texto separado por comas, desde
//  views/_etiquetas.php). Reemplaza la lista completa: lo que no viene se
//  quita. El registro tiene que existir, no estar en la papelera y ser de un
//  módulo encendido. Vuelve a la ficha del registro.
// ============================================================

if (!is_post()) { redirect('dashboard'); }
verify_csrf();

$entidad = (string)($_POST['entidad'] ?? '');
$id      = (int)($_POST['id'] ?? 0);

if (!$id || !entidad_valida($entidad) || !entidad_habilitada($entidad) || !registro_vivo($entidad, $id)) {
    flash('No se pudo guardar: el registro no existe o está en la papelera.', 'error');
    redirect('dashboard');
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

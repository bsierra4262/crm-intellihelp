<?php
declare(strict_types=1);
require_login();

if (!is_post()) { redirect('dashboard'); }
verify_csrf();

$entidad = (string)($_POST['entidad'] ?? '');
$id      = (int)($_POST['entidad_id'] ?? 0);
$cuerpo  = trim((string)($_POST['cuerpo'] ?? ''));

if (!entidad_valida($entidad) || !$id) {
    flash('No se pudo guardar la nota.', 'error');
    redirect('dashboard');
}
if ($cuerpo === '') {
    flash('La nota estaba vacía.', 'error');
    redirect(ltrim(str_replace(BASE_PATH . '/', '', url_registro($entidad, $id)), '/'));
}

nota_crear($entidad, $id, $cuerpo);
flash('Nota agregada.', 'success');

header('Location: ' . url_registro($entidad, $id));
exit;

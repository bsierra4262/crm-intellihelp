<?php
declare(strict_types=1);

// ============================================================
//  Ruta: POST /nota-guardar (requiere sesión + CSRF). Agrega una nota.
//
//  Campos: entidad (lista blanca de registros.php), entidad_id, cuerpo.
//  Viene del formulario de views/_notas.php en las fichas y vuelve a la ficha
//  del registro. No comprueba que el registro exista ni que su módulo esté
//  encendido.
// ============================================================
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
    // url_registro() ya trae BASE_PATH y redirect() lo vuelve a poner: se le
    // quita antes para no duplicarlo.
    redirect(ltrim(str_replace(BASE_PATH . '/', '', url_registro($entidad, $id)), '/'));
}

nota_crear($entidad, $id, $cuerpo);
flash('Nota agregada.', 'success');

header('Location: ' . url_registro($entidad, $id));
exit;

<?php
declare(strict_types=1);

// ============================================================
//  Ruta: POST /nota-guardar (requiere sesión + CSRF). Agrega una nota.
//
//  Campos: entidad (lista blanca de registros.php), entidad_id, cuerpo.
//  Viene del formulario de views/_notas.php en las fichas y vuelve a la ficha
//  del registro, que tiene que existir, no estar en la papelera y ser de un
//  módulo encendido (la tabla de notas no tiene clave foránea que lo impida).
// ============================================================
require_login();

if (!is_post()) { redirect('dashboard'); }
verify_csrf();

$entidad = (string)($_POST['entidad'] ?? '');
$id      = (int)($_POST['entidad_id'] ?? 0);
$cuerpo  = trim((string)($_POST['cuerpo'] ?? ''));

if (!entidad_valida($entidad) || !$id || !entidad_habilitada($entidad) || !registro_vivo($entidad, $id)) {
    flash('No se pudo guardar la nota: el registro no existe o está en la papelera.', 'error');
    redirect('dashboard');
}
if ($cuerpo === '') {
    flash('La nota estaba vacía.', 'error');
    // url_registro() ya trae BASE_PATH: va directo a la cabecera, sin
    // redirect(), que se lo volvería a poner.
    header('Location: ' . url_registro($entidad, $id));
    exit;
}

nota_crear($entidad, $id, $cuerpo);
flash('Nota agregada.', 'success');

header('Location: ' . url_registro($entidad, $id));
exit;

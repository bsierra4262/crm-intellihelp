<?php
declare(strict_types=1);
require_login();

if (!is_post()) { redirect('dashboard'); }
verify_csrf();

$entidad = (string)($_POST['entidad'] ?? '');
$id      = (int)($_POST['id'] ?? 0);
// La ruta de vuelta puede traer parámetros («papelera?entidad=contactos»).
// El filtro anterior borraba el «?» y el «=», así que la dirección quedaba
// pegoteada y restaurar algo terminaba en una pantalla de 404.
$volver = (string)($_POST['volver'] ?? 'dashboard');
$volver = preg_replace('/[^a-z0-9\-_?=&]/i', '', $volver) ?: 'dashboard';
$accion  = ($_POST['accion'] ?? 'eliminar') === 'restaurar' ? 'restaurar' : 'eliminar';

if (!$id || !isset(tablas_papelera()[$entidad])) {
    flash('No se pudo completar la acción: registro desconocido.', 'error');
    redirect($volver ?: 'dashboard');
}

// Restaurar es potestad del administrador: es quien maneja la papelera.
if ($accion === 'restaurar') {
    require_admin();
    registro_restaurar($entidad, $id);
    flash('Registro restaurado.', 'success');
} else {
    registro_eliminar($entidad, $id);
    flash('Lo enviamos a la papelera. Un administrador puede restaurarlo.', 'success');
}

redirect($volver ?: 'dashboard');

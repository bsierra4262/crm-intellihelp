<?php
declare(strict_types=1);

// ============================================================
//  Ruta: POST /registro-eliminar (requiere sesión + CSRF).
//
//  Manda un registro a la papelera (borrado lógico: eliminado_en = NOW()) o,
//  con accion=restaurar, lo saca de ella. Campos: entidad (clave de
//  tablas_papelera()), id, accion, volver (ruta interna a la que regresar).
//
//  Permisos: restaurar es sólo de administradores; eliminar lo puede hacer
//  cualquier usuario con sesión, sobre cualquier registro (no se mira el
//  propietario ni si el módulo de esa entidad está encendido).
// ============================================================
require_login();

if (!is_post()) { redirect('dashboard'); }
verify_csrf();

$entidad = (string)($_POST['entidad'] ?? '');
$id      = (int)($_POST['id'] ?? 0);
// La ruta de vuelta puede traer parámetros («papelera?entidad=contactos»).
// El filtro anterior borraba el «?» y el «=», así que la dirección quedaba
// pegoteada y restaurar algo terminaba en una pantalla de 404.
$volver = (string)($_POST['volver'] ?? 'dashboard');
// Sin barras, puntos ni dos puntos: la ruta no puede salir de la aplicación
// (redirect() le antepone BASE_PATH).
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

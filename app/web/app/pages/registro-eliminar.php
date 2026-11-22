<?php
declare(strict_types=1);

// ============================================================
//  Ruta: POST /registro-eliminar (requiere sesión + CSRF).
//
//  Manda un registro a la papelera (borrado lógico: eliminado_en = NOW()) o,
//  con accion=restaurar, lo saca de ella. Campos: entidad (clave de
//  tablas_papelera()), id, accion, volver (ruta interna a la que regresar).
//
//  Permisos: restaurar es sólo de administradores. Eliminar lo puede hacer el
//  propietario del registro o un administrador; si el registro no tiene
//  propietario, cualquiera (puede_mandar_papelera en registros.php). En los
//  dos casos el módulo de la entidad tiene que estar encendido.
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

// Con el módulo apagado, la entidad no está en uso: no se toca, ni para
// borrar ni para restaurar.
if (!entidad_habilitada($entidad)) {
    flash('Esa parte del sistema no está en marcha.', 'error');
    redirect($volver ?: 'dashboard');
}

// Restaurar es potestad del administrador: es quien maneja la papelera.
if ($accion === 'restaurar') {
    require_admin();
    registro_restaurar($entidad, $id);
    flash('Registro restaurado.', 'success');
} else {
    $fila = registro_vivo($entidad, $id);
    if (!$fila) {
        flash('Ese registro ya no existe o ya está en la papelera.', 'info');
    } elseif (!puede_mandar_papelera($entidad, $fila, current_user_id(), auth_is_admin())) {
        flash('Este registro tiene otro propietario: sólo él o un administrador lo pueden mandar a la papelera.', 'error');
    } else {
        registro_eliminar($entidad, $id);
        flash('Lo enviamos a la papelera. Un administrador puede restaurarlo.', 'success');
    }
}

redirect($volver ?: 'dashboard');

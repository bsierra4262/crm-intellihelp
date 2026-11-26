<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta `lista-form` (alta) y `lista-form?id=<id>` (edición). Módulo
//  Campañas; cualquier usuario con sesión puede crear o editar cualquier
//  lista (no hay dueño).
//
//  Sólo nombre, segmento (categoría) y descripción: los miembros se cargan
//  en lista-ver. Al guardar se va a lista-ver de esa lista. Si falta el
//  nombre, se vuelve a mostrar el formulario con lo escrito.
//
//  Vista: views/lista-form.php, con $l, $id, $errores y $categorias.
//  Lógica en app/campanas.php (lista_get, lista_guardar) y
//  app/cuentas_correo.php (categorias_listar).
// ============================================================

$id = (int)($_GET['id'] ?? 0);
$l  = $id ? lista_get($id) : null;
// Lo de otro usuario no se muestra (salvo al administrador): como si no existiera.
if ($l && !lista_visible($l)) { $l = null; }
if ($id && !$l) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Lista inexistente', 'mensaje' => ''], 'No encontrado');
    exit;
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    if (trim((string)($d['nombre'] ?? '')) === '') { $errores['nombre'] = 'Poné un nombre.'; }
    if (!$errores) {
        $nuevoId = lista_guardar($d, $id ?: null);
        flash($id ? 'Lista actualizada.' : 'Lista creada. Ahora cargala con contactos.', 'success');
        redirect('lista-ver?id=' . $nuevoId);
    }
    // Con error, lo escrito pisa lo guardado para no perderlo al volver a pintar.
    $l = array_merge($l ?? [], $d);
}

render('lista-form', ['l' => $l, 'id' => $id, 'errores' => $errores,
                      'categorias' => categorias_listar()],
       $id ? 'Editar lista' : 'Nueva lista');

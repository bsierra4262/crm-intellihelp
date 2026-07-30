<?php
declare(strict_types=1);
require_login();

$id = (int)($_GET['id'] ?? 0);
$l  = $id ? lista_get($id) : null;
if (!$l) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Lista inexistente', 'mensaje' => ''], 'No encontrado');
    exit;
}

// Carga masiva desde el CRM con el mismo filtro del listado de contactos.
if (is_post() && ($_POST['accion'] ?? '') === 'cargar') {
    verify_csrf();
    $filtro = [
        'q'              => trim((string)($_POST['fq'] ?? '')),
        'propietario_id' => $_POST['fpropietario_id'] ?? '',
        'provincia'      => $_POST['fprovincia'] ?? '',
        'fuente'         => $_POST['ffuente'] ?? '',
        'con_email'      => '1',
    ];
    $n = lista_cargar_contactos($id, $filtro);
    flash($n > 0
        ? "Se agregaron $n contactos a la lista (los repetidos y los dados de baja no se cargan)."
        : 'No se agregó ninguno: o ya estaban en la lista, o el filtro no devolvió contactos con correo.',
        $n > 0 ? 'success' : 'info');
    redirect('lista-ver?id=' . $id);
}

// Quitar un miembro suelto.
if (is_post() && ($_POST['accion'] ?? '') === 'quitar') {
    verify_csrf();
    q('DELETE FROM lista_miembros WHERE id = ? AND lista_id = ?', [(int)($_POST['miembro_id'] ?? 0), $id]);
    log_app('lista.quitar', ['entidad' => 'lista', 'entidad_id' => $id, 'detalle' => 'Se quitó un destinatario']);
    flash('Destinatario quitado de la lista.', 'success');
    redirect('lista-ver?id=' . $id . query_con([]));
}

$f = ['q' => trim((string)($_GET['q'] ?? '')), 'page' => $_GET['page'] ?? 1];

render('lista-ver', [
    'l'        => $l,
    'data'     => lista_miembros($id, $f),
    'f'        => $f,
    'usuarios' => users_activos(),
], $l['nombre']);

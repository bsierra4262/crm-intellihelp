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

// Agregar correos escritos a mano. Es el caso «quiero mandarle a este, a este
// y a este otro» sin tener que armar un filtro ni cargarlos como contactos.
if (is_post() && ($_POST['accion'] ?? '') === 'sueltos') {
    verify_csrf();
    $texto = (string)($_POST['correos'] ?? '');
    $n = 0; $malos = 0;
    foreach (preg_split('/[\s,;]+/', $texto) ?: [] as $em) {
        $em = normalizar_email($em);
        if ($em === '') { continue; }
        if (!email_valido($em)) { $malos++; continue; }
        $ct = q1('SELECT id, nombre_completo FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$em]);
        $st = q('INSERT IGNORE INTO lista_miembros (lista_id, contacto_id, email, nombre)
                 VALUES (?, ?, ?, ?)',
                [$id, $ct ? (int)$ct['id'] : null, $em, $ct['nombre_completo'] ?? null]);
        $n += $st->rowCount();
    }
    log_app('lista.agregar_sueltos', ['entidad' => 'lista', 'entidad_id' => $id,
        'detalle' => $n . ' correos agregados a mano']);
    flash($n > 0
        ? "Se agregaron $n correos." . ($malos ? " $malos no eran válidos y se descartaron." : '')
        : 'No se agregó ninguno: o ya estaban en la lista, o no eran direcciones válidas.',
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

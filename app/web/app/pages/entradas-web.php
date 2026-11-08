<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Entradas desde la web: lo que llega de intellihelp.tech.
//
//  Cada consulta del formulario y cada reunión reservada en la landing, con la
//  página desde la que se mandó y el canal por el que llegó la persona. Arriba,
//  las tarjetas por canal con lo que la consultora mira cada mes: consultas,
//  reuniones agendadas, realizadas y canceladas.
//
//  Lo ve todo el equipo (la pantalla de reuniones lleva un botón hasta acá);
//  elegir quién atiende las consultas es de administración.
// ============================================================

if (!entradas_web_disponible()) {
    render('error', [
        'codigo'  => 503,
        'titulo'  => 'Falta actualizar la base',
        'mensaje' => 'Esta pantalla aparece cuando se aplica la migración 008 (entradas desde la web).',
    ], 'Entradas desde la web');
    return;
}

if (is_post()) {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'dueno') {
        if (!auth_is_admin()) {
            flash('Elegir quién atiende las consultas es de administración.', 'error');
            redirect('entradas-web');
        }
        $id = (int)($_POST['web_dueno_id'] ?? 0);
        if ($id > 0 && !qv('SELECT 1 FROM usuarios WHERE id = ? AND activo = 1', [$id])) {
            flash('Ese usuario no está activo.', 'error');
            redirect('entradas-web');
        }
        setting_set('web_dueno_id', $id > 0 ? (string)$id : '');
        $nombre = $id > 0 ? (string) qv('SELECT nombre FROM usuarios WHERE id = ?', [$id]) : 'automático';
        log_app('entradas_web.dueno', ['detalle' => 'Responsable de las consultas de la web: ' . $nombre]);
        flash('Listo: las consultas nuevas de la web van a ' . ($id > 0 ? $nombre : 'quien corresponda según la agenda') . '.', 'success');
    }
    redirect('entradas-web' . query_con([]));
}

$f = [
    'q'      => trim((string)($_GET['q'] ?? '')),
    'tipo'   => (string)($_GET['tipo'] ?? ''),
    'canal'  => (string)($_GET['canal'] ?? ''),
    'pagina' => (string)($_GET['pagina'] ?? ''),
    'estado' => (string)($_GET['estado'] ?? ''),
    'desde'  => (string)($_GET['desde'] ?? ''),
    'hasta'  => (string)($_GET['hasta'] ?? ''),
    'orden'  => (string)($_GET['orden'] ?? ''),
    'page'   => $_GET['page'] ?? 1,
];

// Exportación: la misma consulta, sin paginar, servida como CSV.
if (($_GET['exportar'] ?? '') === '1') {
    [$where, $p] = entradas_web_where($f);
    $rows = q('SELECT e.*, ct.nombre_completo AS contacto, cu.nombre AS cuenta,
                      r.inicia_el AS reserva_inicio, r.estado AS reserva_estado, u.nombre AS propietario '
            . entradas_web_from() . " $where ORDER BY " . entradas_web_orden($f['orden']), $p)->fetchAll();
    $tipos   = ['consulta' => 'Consulta', 'reserva' => 'Reunión'];
    $estados = ['procesando' => 'En proceso', 'ok' => 'Entró', 'error' => 'Con error', 'descartada' => 'Repetida'];
    foreach ($rows as &$r) {
        $r['tipo_txt']   = $tipos[$r['tipo']] ?? $r['tipo'];
        $r['estado_txt'] = $estados[$r['estado']] ?? $r['estado'];
        $r['novedades']  = $r['novedades'] ? 'Sí' : 'No';
    }
    unset($r);
    log_app('entradas_web.exportar', ['detalle' => count($rows) . ' entradas exportadas a CSV']);
    exportar_csv('entradas-web-' . date('Ymd-His') . '.csv', $rows, [
        'creado_en' => 'Llegó el', 'tipo_txt' => 'Qué', 'estado_txt' => 'Estado',
        'nombre' => 'Nombre', 'empresa' => 'Empresa', 'email' => 'Correo', 'telefono' => 'Teléfono',
        'servicio' => 'Servicio', 'canal' => 'Llegó por', 'origen_pagina' => 'Desde la página',
        'landing' => 'Entró por', 'utm_source' => 'Fuente', 'utm_medium' => 'Medio',
        'utm_campaign' => 'Campaña', 'utm_term' => 'Búsqueda', 'utm_content' => 'Anuncio',
        'referrer' => 'Venía de', 'visitas_landing' => 'Visitas a la landing',
        'reserva_inicio' => 'Reunión', 'reserva_estado' => 'Estado de la reunión',
        'propietario' => 'Responsable', 'novedades' => 'Acepta novedades',
        'consentimiento_en' => 'Consentimiento', 'consentimiento_version' => 'Versión del texto',
    ]);
}

render('entradas-web', [
    'f'        => $f,
    'data'     => entradas_web_listar($f),
    'canales'  => entradas_web_por_canal($f),
    'opCanal'  => entradas_web_opciones('canal'),
    'opPagina' => entradas_web_opciones('origen_pagina'),
    'usuarios' => users_activos(),
    'dueno'    => (int) setting_get('web_dueno_id', ''),
    'duenoAuto'=> entrada_web_dueno(),
    'esAdmin'  => auth_is_admin(),
], 'Entradas desde la web');

<?php
declare(strict_types=1);
// ============================================================
//  Ruta `negocio-form`: alta y edición de un negocio.
//
//  GET  negocio-form              → formulario vacío (cierre a 30 días, ARS).
//  GET  negocio-form?id=N         → edición del negocio N.
//  GET  negocio-form?cuenta_id=N  → alta precargada desde la ficha de una
//       empresa; ?contacto_id=N precarga el contacto y su empresa.
//  POST (mismo formulario)        → valida el nombre, guarda con
//       negocio_guardar() y redirige a la ficha (negocio-ver).
//
//  Módulo «negocios» (modulos.php). Cualquier usuario con sesión puede crear
//  o editar cualquier negocio (decisión del equipo: la base se comparte).
//  Vista: views/negocio-form.php.
//
//  Desplegables: las etapas son las del embudo del negocio (en un alta, las
//  del predeterminado) más la actual aunque esté desactivada, y el
//  propietario incluye al actual aunque esté dado de baja. Empresa, contacto
//  y campaña no traen la lista entera: se buscan al escribir (buscador.js
//  contra api-buscar) y el desplegable sólo lleva el valor ya vinculado, así
//  que guardar sin tocar nada nunca lo cambia.
// ============================================================
require_login();

$id = (int)($_GET['id'] ?? 0);
$n  = $id ? negocio_get($id) : null;
if ($id && !$n) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Negocio inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

// Alta desde la ficha de un contacto o de una empresa: vienen precargados.
if (!$id) {
    $n = [
        'cuenta_id'    => $_GET['cuenta_id'] ?? '',
        'contacto_id'  => $_GET['contacto_id'] ?? '',
        'fecha_cierre' => date('Y-m-d', strtotime('+30 days')),
        'moneda'       => 'ARS',
    ];
    if (!empty($n['contacto_id']) && empty($n['cuenta_id'])) {
        $ct = contacto_get((int)$n['contacto_id']);
        if ($ct) {
            $n['cuenta_id'] = $ct['cuenta_id'];
            $n['nombre']    = $ct['cuenta'] ?: $ct['nombre_completo'];
        }
    } elseif (!empty($n['cuenta_id'])) {
        $cu = cuenta_get((int)$n['cuenta_id']);
        if ($cu) { $n['nombre'] = $cu['nombre']; }
    }
}

$errores = [];

if (is_post()) {
    verify_csrf();
    // El POST entero va a negocio_guardar(), que toma sólo las claves que
    // conoce. Antes se verifica que la empresa, el contacto y la campaña
    // existan: un id inexistente haría fallar la clave foránea.
    $d = $_POST;
    if (trim((string)($d['nombre'] ?? '')) === '') { $errores['nombre'] = 'Poné un nombre al negocio.'; }
    $errores += negocio_validar_vinculos($d, $id ? negocio_get($id) : null);
    if (!$errores) {
        try {
            $nuevoId = negocio_guardar($d, $id ?: null);
            flash($id ? 'Negocio actualizado.' : 'Negocio creado.', 'success');
            redirect('negocio-ver?id=' . $nuevoId);
        } catch (\RuntimeException $e) {
            // Las RuntimeException de negocio_guardar() traen un mensaje pensado
            // para mostrar (no hay etapas configuradas).
            $errores['general'] = $e->getMessage();
        } catch (\Throwable $e) {
            log_error('negocio.guardar', $e->getMessage(), $e->getFile(), $e->getLine());
            $errores['general'] = 'No se pudo guardar el negocio. Probá de nuevo; si sigue pasando, avisale a un administrador.';
        }
    }
    // Con errores se vuelve a dibujar el formulario con lo que se escribió.
    $n = array_merge($n ?? [], $d);
}

// Las etapas son las del embudo del negocio: con las del predeterminado, editar
// un negocio de otro embudo lo mudaba de embudo sin que nadie lo pidiera.
$guardado = $id ? negocio_get($id) : null;
if ($guardado) {
    $etapas = etapas_para_negocio((int)$guardado['embudo_id'], (int)$guardado['etapa_id']);
} else {
    $emb    = embudo_predeterminado();
    $etapas = $emb ? etapas_de((int)$emb['id']) : [];
}

// El propietario actual va aunque esté dado de baja: si no, el desplegable
// marcaría otro y guardar reasignaría el negocio.
$usuarios = users_activos();
$propActual = (int)($n['propietario_id'] ?? 0);
if ($propActual && !in_array($propActual, array_map('intval', array_column($usuarios, 'id')), true)) {
    $u = q1('SELECT id, nombre FROM usuarios WHERE id = ?', [$propActual]);
    if ($u) { $u['nombre'] .= ' (dado de baja)'; $usuarios[] = $u; }
}

render('negocio-form', [
    'n'         => $n,
    'id'        => $id,
    'errores'   => $errores,
    'usuarios'  => $usuarios,
    'etapas'    => $etapas,
    // Sólo el valor vinculado: el resto se busca al escribir (buscador.js).
    'cuentas'   => buscar_opcion_actual('cuenta', $n['cuenta_id'] ?? null),
    'contactos' => buscar_opcion_actual('contacto', $n['contacto_id'] ?? null),
    'campanas'  => buscar_opcion_actual('campana', $n['campana_id'] ?? null),
], $id ? 'Editar negocio' : 'Nuevo negocio');

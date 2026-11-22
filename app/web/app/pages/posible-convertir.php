<?php
declare(strict_types=1);

// ============================================================
//  Página `posible-convertir?id=N`: convierte un posible cliente en empresa
//  + contacto (+ negocio opcional).
//
//  GET muestra qué va a pasar (views/posible-convertir.php): si la empresa
//  ya existe por nombre o el contacto por correo, avisa que se reutilizan.
//  POST llama a posible_convertir() (crm.php), que hace todo en una
//  transacción, y redirige al negocio creado o, si no hubo negocio, al
//  contacto.
//
//  Tablas (a través de posible_convertir): escribe `cuentas`, `contactos`,
//  `negocios`, `negocio_etapas`, `notas`, `actividades`, `adjuntos`,
//  `etiqueta_registro` y `posibles_clientes`.
//  Módulo: `posibles`. Con el módulo `negocios` apagado no se ofrece ni se
//  crea el negocio, y se vuelve siempre a la ficha del contacto.
//  Permisos: cualquier usuario con sesión, sea o no el propietario.
//
//  Doble conversión: posible_convertir() toma la fila con un UPDATE
//  condicional dentro de la transacción; el segundo envío (doble clic, otra
//  pestaña) recibe el código 409 y acá se lo manda a la ficha con un aviso.
// ============================================================

require_login();

$id = (int)($_GET['id'] ?? 0);
$p  = $id ? posible_get($id) : null;
if (!$p) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Posible cliente inexistente',
        'mensaje' => 'Puede que lo hayan enviado a la papelera.'], 'No encontrado');
    exit;
}
// Uno ya convertido no vuelve a ofrecer la pantalla: se manda a la ficha.
if ($p['convertido_en']) {
    flash('Este posible cliente ya se había convertido.', 'info');
    redirect('posible-ver?id=' . $id);
}

$verNegocios = ruta_habilitada('negocios');
// Sin embudo predeterminado con etapas activas el negocio no se puede crear:
// la vista lo avisa antes, en vez de descubrirlo después.
$hayEmbudo = $verNegocios && conversion_etapa_inicial() !== null;
$error   = null;
$errores = [];
$form    = [];

if (is_post()) {
    verify_csrf();
    // Lo que se escribió vuelve a la pantalla si algo falla.
    $form = [
        'crear_negocio'   => $hayEmbudo && !empty($_POST['crear_negocio']),
        'negocio_nombre'  => (string)($_POST['negocio_nombre'] ?? ''),
        'negocio_importe' => (string)($_POST['negocio_importe'] ?? ''),
        'negocio_moneda'  => (string)($_POST['negocio_moneda'] ?? 'ARS'),
        'negocio_cierre'  => (string)($_POST['negocio_cierre'] ?? ''),
    ];
    // Importe con miles y decimales a la argentina, moneda de la lista y
    // fecha real: conversion_opciones() (crm.php).
    [, $errores] = conversion_opciones($form);
    if (!$errores) {
        try {
            $r = posible_convertir($id, $form);
            flash(conversion_mensaje($r), $r['negocio_omitido'] ? 'warn' : 'success');
            redirect($r['negocio_id'] && $verNegocios ? 'negocio-ver?id=' . $r['negocio_id'] : 'contacto-ver?id=' . $r['contacto_id']);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) {
                // Otro envío lo convirtió mientras tanto (doble clic, otra pestaña).
                flash('Este posible cliente ya se había convertido.', 'info');
                redirect('posible-ver?id=' . $id);
            }
            // La transacción ya se deshizo adentro. El detalle técnico va al
            // registro de errores; en pantalla, un mensaje que se entienda.
            log_error('posible.convertir_error', $e->getMessage(), $e->getFile(), $e->getLine());
            $error = 'No se pudo convertir: no se guardó nada. Probá de nuevo y, si vuelve a pasar, avisale a un administrador.';
        } catch (\Throwable $e) {
            log_error('posible.convertir_error', $e->getMessage(), $e->getFile(), $e->getLine());
            $error = 'No se pudo convertir: no se guardó nada. Probá de nuevo y, si vuelve a pasar, avisale a un administrador.';
        }
    }
}

// ¿La empresa y el contacto ya existen? Se avisa para que nadie se sorprenda
// de que no se dupliquen. Son las mismas búsquedas que hace
// posible_convertir(): empresa por nombre exacto, contacto sólo por el correo
// principal (no mira email2 ni email3). Del contacto se trae su empresa, para
// decir si se enlaza a ésta o conserva la que tiene.
$cuentaExistente   = cuenta_por_nombre((string)$p['empresa']);
$contactoExistente = $p['email']
    ? q1('SELECT ct.id, ct.nombre_completo, ct.cuenta_id, cu.nombre AS cuenta
          FROM contactos ct LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
          WHERE ct.email = ? AND ct.eliminado_en IS NULL LIMIT 1', [$p['email']])
    : null;

render('posible-convertir', compact('p', 'error', 'errores', 'form', 'cuentaExistente', 'contactoExistente',
                                    'verNegocios', 'hayEmbudo'), 'Convertir posible cliente');

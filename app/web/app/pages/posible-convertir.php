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
//  `negocios`, `negocio_etapas`, `notas`, `actividades`, `adjuntos` y
//  `posibles_clientes`.
//  Módulo: `posibles`. El negocio se crea aunque el módulo `negocios` esté
//  apagado, y en ese caso la redirección final cae en una ruta bloqueada.
//  Permisos: cualquier usuario con sesión, sea o no el propietario.
//
//  Doble conversión: el chequeo de `convertido_en` (acá y dentro de
//  posible_convertir) se hace sin bloquear la fila, así que dos POST casi
//  simultáneos (doble clic, dos pestañas) pueden pasar los dos y duplicar
//  empresa, contacto y negocio.
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

$error = null;

if (is_post()) {
    verify_csrf();
    // El importe acepta coma decimal cambiándola por punto. Un importe con
    // separador de miles («1.234,56») queda «1.234.56» y el (float) de
    // posible_convertir lo lee como 1.234. Moneda y fecha de cierre no se
    // validan: una fecha inválida hace fallar el INSERT y se muestra el
    // mensaje de la base.
    try {
        $r = posible_convertir($id, [
            'crear_negocio'   => !empty($_POST['crear_negocio']),
            'negocio_nombre'  => $_POST['negocio_nombre'] ?? '',
            'negocio_importe' => str_replace(',', '.', (string)($_POST['negocio_importe'] ?? '0')),
            'negocio_moneda'  => $_POST['negocio_moneda'] ?? 'ARS',
            'negocio_cierre'  => $_POST['negocio_cierre'] ?? null,
        ]);
        // El mensaje dice «se creó» también cuando la empresa o el contacto se
        // reutilizaron. Si se pidió negocio pero no hay embudo predeterminado
        // con etapas activas, no se crea y no se avisa.
        flash('Convertido: se creó la empresa y el contacto' . ($r['negocio_id'] ? ' y el negocio' : '') . '.', 'success');
        redirect($r['negocio_id'] ? 'negocio-ver?id=' . $r['negocio_id'] : 'contacto-ver?id=' . $r['contacto_id']);
    } catch (\Throwable $e) {
        // La transacción ya se deshizo adentro de posible_convertir(). El
        // mensaje crudo de la excepción (puede ser un error de SQL) se muestra
        // en pantalla y queda en la auditoría.
        $error = $e->getMessage();
        log_app('posible.convertir_error', ['tipo' => 'error', 'entidad' => 'posible_cliente',
            'entidad_id' => $id, 'detalle' => $e->getMessage()]);
    }
}

// ¿La empresa y el contacto ya existen? Se avisa para que nadie se sorprenda
// de que no se dupliquen. Son las mismas búsquedas que hace
// posible_convertir(): empresa por nombre exacto, contacto sólo por el correo
// principal (no mira email2 ni email3).
$cuentaExistente   = cuenta_por_nombre((string)$p['empresa']);
$contactoExistente = $p['email']
    ? q1('SELECT id, nombre_completo FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$p['email']])
    : null;

render('posible-convertir', compact('p', 'error', 'cuentaExistente', 'contactoExistente'), 'Convertir posible cliente');

<?php
declare(strict_types=1);
require_admin();

// ============================================================
//  Ruta `cuentas-correo` (módulo Campañas; sólo administradores, también
//  marcado así en index.php).
//
//  Alta, edición, prueba y baja de las casillas desde las que salen las
//  campañas. Un solo POST con `accion`:
//    · 'probar'   → cuenta_probar($id): conecta SMTP e IMAP y anota el resultado;
//    · 'eliminar' → cuenta_correo_eliminar($id): baja lógica;
//    · otra cosa  → guardar (alta si id = 0; una dirección dada de baja se
//      reactiva) y probar enseguida.
//  Lo que sale bien redirige con un aviso (POST-redirect-GET): refrescar la
//  página no repite la prueba de red ni el guardado. Lo que no pasa la
//  validación se muestra en la misma respuesta, con el formulario cargado.
//
//  Vista: views/cuentas-correo.php, con $aviso ([tipo, texto] o null),
//  $rows, $editar (fila a editar, o el POST si falló la validación) y
//  $usuarios. Lógica en app/cuentas_correo.php.
// ============================================================

$aviso  = null;
$editar = null;

if (is_post()) {
    verify_csrf();
    $accion = (string)($_POST['accion'] ?? 'guardar');
    $id     = (int)($_POST['id'] ?? 0);

    if ($accion === 'probar') {
        $err = null;
        cuenta_probar($id, $err)
            ? flash('La cuenta conecta bien: ya puede mandar campañas.', 'success')
            : flash('No se pudo conectar: ' . $err, 'error');
        redirect('cuentas-correo');

    } elseif ($accion === 'eliminar') {
        cuenta_correo_eliminar($id);
        flash('Cuenta dada de baja.', 'success');
        redirect('cuentas-correo');

    } else {
        $d = $_POST;
        if (trim((string)($d['nombre'] ?? '')) === '' || !email_valido($d['remitente'] ?? '')) {
            $aviso = ['error', 'Hace falta un nombre y una dirección de correo válida.'];
            $editar = $d;
        } else {
            try {
                $nuevoId = cuenta_correo_guardar($d, $id ?: null);
            } catch (CuentaCorreoRepetida $e) {
                $aviso  = ['error', $e->getMessage()];
                $editar = $d;
                $nuevoId = 0;
            } catch (\Throwable $e) {
                log_error('cuenta_correo.guardar', $e->getMessage(), $e->getFile(), $e->getLine());
                $aviso  = ['error', 'No se pudo guardar la cuenta por un error interno. Quedó registrado para revisarlo.'];
                $editar = $d;
                $nuevoId = 0;
            }
            if ($nuevoId) {
                // Probar al guardar evita descubrir el problema recién cuando la
                // campaña ya arrancó y falló a mitad de camino.
                $err = null;
                cuenta_probar($nuevoId, $err)
                    ? flash('Cuenta guardada y verificada.', 'success')
                    : flash('Cuenta guardada, pero no se pudo conectar: ' . $err, 'warn');
                redirect('cuentas-correo');
            }
        }
    }
}

// `?editar=<id>` carga la cuenta en el formulario de abajo. La fila trae las
// contraseñas cifradas: la vista sólo mira si existen, no las muestra.
if (!$editar && ($_GET['editar'] ?? '') !== '') {
    $editar = cuenta_correo_get((int)$_GET['editar']);
}

render('cuentas-correo', [
    'aviso'    => $aviso,
    'rows'     => cuentas_correo_listar(),
    'editar'   => $editar,
    'usuarios' => users_activos(),
], 'Cuentas de correo');

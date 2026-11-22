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
//    · otra cosa  → guardar (alta si id = 0) y probar enseguida.
//  No redirige después del POST: el resultado se muestra en la misma
//  respuesta con $aviso (refrescar la página reenvía el formulario).
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
        $aviso = cuenta_probar($id, $err)
            ? ['success', 'La cuenta conecta bien: ya puede mandar campañas.']
            : ['error', 'No se pudo conectar: ' . $err];

    } elseif ($accion === 'eliminar') {
        cuenta_correo_eliminar($id);
        $aviso = ['success', 'Cuenta dada de baja.'];

    } else {
        $d = $_POST;
        if (trim((string)($d['nombre'] ?? '')) === '' || !email_valido($d['remitente'] ?? '')) {
            $aviso = ['error', 'Hace falta un nombre y una dirección de correo válida.'];
            $editar = $d;
        } else {
            // Un remitente repetido (aun de una cuenta dada de baja) hace
            // lanzar PDOException por la clave única; acá no se ataja.
            $nuevoId = cuenta_correo_guardar($d, $id ?: null);
            // Probar al guardar evita descubrir el problema recién cuando la
            // campaña ya arrancó y falló a mitad de camino.
            $err = null;
            $aviso = cuenta_probar($nuevoId, $err)
                ? ['success', 'Cuenta guardada y verificada.']
                : ['warn', 'Cuenta guardada, pero no se pudo conectar: ' . $err];
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

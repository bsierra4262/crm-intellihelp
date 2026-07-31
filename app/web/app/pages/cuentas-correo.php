<?php
declare(strict_types=1);
require_admin();

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

if (!$editar && ($_GET['editar'] ?? '') !== '') {
    $editar = cuenta_correo_get((int)$_GET['editar']);
}

render('cuentas-correo', [
    'aviso'    => $aviso,
    'rows'     => cuentas_correo_listar(),
    'editar'   => $editar,
    'usuarios' => users_activos(),
], 'Cuentas de correo');

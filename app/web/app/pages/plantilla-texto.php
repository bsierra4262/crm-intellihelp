<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: /plantilla-texto[?id=N]  (con sesión; módulo «campanas»).
//  Plantilla en texto plano: el correo sin diseño, como los que mandaba
//  Pulsemail. Se escribe el asunto y el cuerpo tal cual; sale sólo como
//  text/plain, sin HTML ni píxel (campana_armar_mensaje).
//    · GET: formulario, con la vista previa armada con datos de ejemplo.
//    · POST: valida nombre, asunto y cuerpo; guarda con plantilla_guardar()
//      (tipo 'texto') y vuelve al mismo formulario.
//  Una plantilla visual que llegue acá con ?id se manda a su editor.
// ============================================================

$id = (int)($_GET['id'] ?? 0);
$p  = $id ? plantilla_get($id) : null;
if ($id && !$p) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Plantilla inexistente', 'mensaje' => ''], 'No encontrado');
    exit;
}
if ($p && ($p['tipo'] ?? '') !== 'texto') {
    redirect('plantilla-form?id=' . $id);
}
$p ??= ['activo' => 1, 'tipo' => 'texto', 'nombre' => '', 'asunto' => '', 'carpeta' => '',
        'cuerpo_texto' => "¡Hola {{nombre}}!\n\n"];

$errores = [];
if (is_post()) {
    verify_csrf();
    $d = ['tipo' => 'texto'] + $_POST;
    if (trim((string)($d['nombre'] ?? '')) === '') { $errores['nombre'] = 'Poné un nombre.'; }
    if (trim((string)($d['asunto'] ?? '')) === '') { $errores['asunto'] = 'Poné un asunto.'; }
    if (trim((string)($d['cuerpo_texto'] ?? '')) === '') { $errores['cuerpo_texto'] = 'Escribí el correo.'; }
    if (!$errores) {
        $nuevoId = plantilla_guardar($d, $id ?: null);
        flash($id ? 'Plantilla actualizada.' : 'Plantilla creada.', 'success');
        redirect('plantilla-texto?id=' . $nuevoId);
    }
    $p = array_merge($p, $d);
}

// Vista previa con datos de ejemplo: exactamente lo que va a recibir una
// persona, con el renglón de baja que se agrega al final.
$ejemplo = [
    'nombre' => 'Juan', 'apellido' => 'Pérez', 'empresa' => 'ACME S.A.',
    'cargo' => 'Gerente de Sistemas', 'email' => 'juan@acme.com',
    'remitente' => auth_user()['nombre'] ?? '',
    'agenda' => url_abs('r/ejemplo/demo'), 'baja' => url_abs('baja?t=ejemplo'),
];
$cuerpo = plantilla_texto_normalizar((string)($p['cuerpo_texto'] ?? ''));
$vistaTexto = render_plantilla($cuerpo, $ejemplo);
if (stripos($vistaTexto, $ejemplo['baja']) === false) {
    $vistaTexto = rtrim($vistaTexto) . "\n\n--\n" . CAMPANA_PIE_TEXTO;
}

render('plantilla-texto', [
    'p'           => $p,
    'id'          => $id,
    'errores'     => $errores,
    'asuntoVista' => render_plantilla((string)($p['asunto'] ?? ''), $ejemplo),
    'vistaTexto'  => $vistaTexto,
], $id ? 'Editar plantilla de texto' : 'Nueva plantilla de texto');

<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: /plantilla-form[?id=N][&desde=<preajuste>]  (con sesión; módulo
//  «campanas»). El editor visual de plantillas.
//    · GET sin id: plantilla nueva, vacía o copiada de un preajuste
//      (plantillas_preajustes(), en preajustes.php).
//    · POST: valida nombre y asunto, guarda con plantilla_guardar() (que
//      genera el HTML desde los bloques; un `tipo=html` que llegue se
//      ignora) y vuelve al mismo formulario.
//  Además arma una primera vista previa y el control de entregabilidad con
//  datos de ejemplo; después el editor (assets/js/editor.js) los actualiza
//  pidiéndoselos a plantilla-vista.
//  Cualquier usuario con sesión puede crear y editar plantillas.
// ============================================================

$id = (int)($_GET['id'] ?? 0);
$p  = $id ? plantilla_get($id) : null;
if ($id && !$p) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Plantilla inexistente', 'mensaje' => ''], 'No encontrado');
    exit;
}
// Las de texto plano tienen su propia pantalla.
if ($p && ($p['tipo'] ?? '') === 'texto') {
    redirect('plantilla-texto?id=' . $id);
}

// Plantilla nueva: se arranca desde un preajuste (o desde cero).
$preajustes = plantillas_preajustes();
if (!$id) {
    $clave = (string)($_GET['desde'] ?? '');
    $base  = $preajustes[$clave] ?? null;
    $p = [
        'activo'    => 1,
        'tipo'      => 'visual',
        'preajuste' => $base ? $clave : null,
        'nombre'    => $base ? $base['nombre'] : '',
        'asunto'    => $base ? $base['asunto'] : '',
        'bloques'   => json_encode([
            'bloques' => $base['bloques'] ?? [['tipo' => 'texto', 'texto' => 'Hola {{nombre}},']],
            'ajustes' => $base['ajustes'] ?? bloques_ajustes_defecto(),
        ], JSON_UNESCAPED_UNICODE),
    ];
}

$errores = [];

if (is_post()) {
    verify_csrf();
    $d = $_POST;
    if (trim((string)($d['nombre'] ?? '')) === '') { $errores['nombre'] = 'Poné un nombre.'; }
    if (trim((string)($d['asunto'] ?? '')) === '') { $errores['asunto'] = 'Poné un asunto.'; }
    if (!$errores) {
        $nuevoId = plantilla_guardar($d, $id ?: null);
        flash($id ? 'Plantilla actualizada.' : 'Plantilla creada.', 'success');
        redirect('plantilla-form?id=' . $nuevoId);
    }
    $p = array_merge($p ?? [], $d);
}

// Estado del editor: bloques + ajustes, siempre como estructura, nunca como HTML.
$datos = json_decode((string)($p['bloques'] ?? ''), true);
if (!is_array($datos) || !isset($datos['bloques'])) {
    $datos = ['bloques' => [], 'ajustes' => bloques_ajustes_defecto()];
}
$datos['ajustes'] = array_merge(bloques_ajustes_defecto(), (array)($datos['ajustes'] ?? []));

// Vista previa y verificación con datos de ejemplo, para que se vea como lo va
// a recibir una persona de verdad y no con las variables sin reemplazar.
// Se arma siempre desde los bloques, aunque la plantilla guardada sea de tipo
// 'html': en ese caso esta pantalla no muestra su HTML real.
$ctx     = contexto_marca();
$html    = bloques_render($datos['bloques'], $datos['ajustes'], $ctx);
$texto   = bloques_texto($datos['bloques'], $ctx);
$ejemplo = [
    'nombre' => 'Juan', 'apellido' => 'Pérez', 'empresa' => 'ACME S.A.',
    'cargo' => 'Gerente de Sistemas', 'email' => 'juan@acme.com',
    'remitente' => auth_user()['nombre'] ?? '', 'remitente_empresa' => $ctx['empresa'],
    'empresa_web' => $ctx['empresa_web'],
    'agenda' => url_abs('r/ejemplo/demo'), 'baja' => url_abs('baja?t=ejemplo'),
];
$vista  = render_plantilla($html, $ejemplo, true);
$asunto = render_plantilla((string)($p['asunto'] ?? ''), $ejemplo);
$chequeo = bloques_verificar($datos['bloques'], (string)($p['asunto'] ?? ''), $vista,
                             render_plantilla($texto, $ejemplo), contexto_marca());

render('plantilla-form', [
    'p'          => $p,
    'id'         => $id,
    'errores'    => $errores,
    'datos'      => $datos,
    'asuntoVista' => $asunto,
    'chequeo'    => $chequeo,
    'preajustes' => $preajustes,
    'imagenes'   => q('SELECT * FROM imagenes ORDER BY id DESC LIMIT 60')->fetchAll(),
], $id ? 'Editar plantilla' : 'Nueva plantilla');

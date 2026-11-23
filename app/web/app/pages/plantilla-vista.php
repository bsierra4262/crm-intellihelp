<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: /plantilla-vista  (con sesión; módulo «campanas»).
//  Lee `plantillas` (sólo en GET); no escribe nada.
//
//  El GET devuelve el `cuerpo_html` guardado como página del dominio del
//  CRM, así que va con la cabecera `Content-Security-Policy: sandbox`: el
//  navegador lo trata como de otro origen y sin scripts. Una plantilla vieja
//  de tipo 'html' (que se guardaba sin sanear) no puede correr nada con la
//  sesión de quien la abra. Las variables se reemplazan escapadas.
// ============================================================
//
// Dos usos:
//   POST  → el editor manda los bloques y recibe el HTML ya armado. Es el
//           mismo renderizador que usa el envío real, así que la vista previa
//           no puede diferir de lo que le llega al destinatario.
//   GET   → abre la plantilla guardada en una pestaña, para verla completa.

// Los mismos datos de ejemplo que usa plantilla-form; si se cambian acá hay
// que cambiarlos allá.
$ctx     = contexto_marca();
$ejemplo = [
    'nombre' => 'Juan', 'apellido' => 'Pérez', 'empresa' => 'ACME S.A.',
    'cargo' => 'Gerente de Sistemas', 'email' => 'juan@acme.com',
    'remitente' => auth_user()['nombre'] ?? '', 'remitente_empresa' => $ctx['empresa'],
    'empresa_web' => $ctx['empresa_web'],
    'agenda' => url_abs('r/ejemplo/demo'), 'baja' => url_abs('baja?t=ejemplo'),
];

if (is_post()) {
    verify_csrf();
    $datos = json_decode((string)($_POST['bloques'] ?? ''), true);
    $lista = is_array($datos['bloques'] ?? null) ? $datos['bloques'] : [];
    $aj    = is_array($datos['ajustes'] ?? null) ? $datos['ajustes'] : [];
    $asunto = (string)($_POST['asunto'] ?? '');

    $html  = render_plantilla(bloques_render($lista, $aj, $ctx), $ejemplo, true);
    $texto = render_plantilla(bloques_texto($lista, $ctx), $ejemplo);

    // json_out termina el pedido.
    json_out([
        'ok'      => true,
        'html'    => $html,
        'asunto'  => render_plantilla($asunto, $ejemplo),
        'chequeo' => bloques_verificar($lista, $asunto, $html, $texto, contexto_marca()),
    ]);
}

$id = (int)($_GET['id'] ?? 0);
$p  = $id ? plantilla_get($id) : null;
if (!$p) {
    http_response_code(404);
    exit('La plantilla no existe.');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
// Sin allow-scripts ni allow-same-origin: el correo se ve, pero no ejecuta
// nada ni puede leer la sesión del CRM.
header('Content-Security-Policy: sandbox');
echo render_plantilla((string)$p['cuerpo_html'], $ejemplo, true);

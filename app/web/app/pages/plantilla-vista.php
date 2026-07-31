<?php
declare(strict_types=1);
require_login();

// Dos usos:
//   POST  → el editor manda los bloques y recibe el HTML ya armado. Es el
//           mismo renderizador que usa el envío real, así que la vista previa
//           no puede diferir de lo que le llega al destinatario.
//   GET   → abre la plantilla guardada en una pestaña, para verla completa.

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

    $html  = render_plantilla(bloques_render($lista, $aj, $ctx), $ejemplo);
    $texto = render_plantilla(bloques_texto($lista, $ctx), $ejemplo);

    json_out([
        'ok'      => true,
        'html'    => $html,
        'asunto'  => render_plantilla($asunto, $ejemplo),
        'chequeo' => bloques_verificar($lista, $asunto, $html, $texto),
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
echo render_plantilla((string)$p['cuerpo_html'], $ejemplo);

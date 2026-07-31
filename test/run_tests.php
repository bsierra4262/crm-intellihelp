<?php
declare(strict_types=1);

// ============================================================
//  Batería de tests de regresión — CRM INTELLIHELP
//
//  Corre SIN base de datos ni red: prueba la lógica pura, que es donde
//  aparecen los bugs que se repiten.
//
//  Uso local:     php test/run_tests.php
//  Contra el server: CRM_SRC=/ruta/al/crm/app php run_tests.php
//
//  REGLA DEL PROYECTO: cada defecto que aparece suma un caso acá, para que
//  no pueda volver.
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('BASE_PATH')) { define('BASE_PATH', '/crm'); }
if (!defined('APP'))       { define('APP', __DIR__); }

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'intellihelp.tech';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$GLOBALS['CONFIG'] = [
    'app' => [
        'name'      => 'CRM Test',
        'canonical' => 'https://intellihelp.tech/crm',
        'timezone'  => 'America/Argentina/Buenos_Aires',
        'secret'    => 'test-secret',
    ],
    'db'     => ['name' => 'test', 'user' => 'test'],
    'google' => [
        'client_id'      => 'CID.apps.googleusercontent.com',
        'client_secret'  => 'SECRET',
        'allowed_domain' => 'intellihelp.tech',
    ],
];

date_default_timezone_set('America/Argentina/Buenos_Aires');

$SRC = getenv('CRM_SRC') ?: (__DIR__ . '/../app/web/app');
require $SRC . '/helpers.php';
require $SRC . '/crm.php';
require $SRC . '/registros.php';
require $SRC . '/actividad.php';
require $SRC . '/actividades.php';
require $SRC . '/envio.php';
require $SRC . '/bloques.php';
require $SRC . '/preajustes.php';
require $SRC . '/campanas.php';
require $SRC . '/imap.php';
require $SRC . '/google.php';
require $SRC . '/gcal.php';
require $SRC . '/agenda.php';

// ---- Andamiaje mínimo ----
$T = 0; $F = 0; $fallos = [];

function ok(string $nombre, bool $cond): void {
    global $T, $F, $fallos;
    $T++;
    if ($cond) { echo "  [OK] $nombre\n"; }
    else { $F++; $fallos[] = $nombre; echo "  [XX] $nombre\n"; }
}
function eq(string $nombre, $got, $exp): void {
    ok($nombre . '  [esperado=' . var_export($exp, true) . ' obtenido=' . var_export($got, true) . ']', $got === $exp);
}
function feq(string $nombre, $got, $exp): void {
    ok($nombre . "  [esperado=$exp obtenido=$got]", abs(((float)$got) - ((float)$exp)) < 0.001);
}
function contiene(string $nombre, string $pajar, string $aguja): void {
    ok($nombre, str_contains($pajar, $aguja));
}
function no_contiene(string $nombre, string $pajar, string $aguja): void {
    ok($nombre, !str_contains($pajar, $aguja));
}

echo "== Normalización de provincias (datos reales del CSV de Zoho) ==\n";
eq('CABA',                        normalizar_provincia('CABA'), 'Ciudad de Buenos Aires');
eq('CIUDAD DE BUENOS AIRES',      normalizar_provincia('CIUDAD DE BUENOS AIRES'), 'Ciudad de Buenos Aires');
eq('Ciudad de Buenos Aires',      normalizar_provincia('Ciudad de Buenos Aires'), 'Ciudad de Buenos Aires');
eq('C.A.B.A.',                    normalizar_provincia('C.A.B.A.'), 'Ciudad de Buenos Aires');
eq('Federal District (Zoho)',     normalizar_provincia('Federal District'), 'Ciudad de Buenos Aires');
eq('BUENOS AIRES',                normalizar_provincia('BUENOS AIRES'), 'Buenos Aires');
eq('SANTA FE',                    normalizar_provincia('SANTA FE'), 'Santa Fe');
eq('CORDOBA sin tilde',           normalizar_provincia('CORDOBA'), 'Córdoba');
eq('ENTRE RIOS sin tilde',        normalizar_provincia('ENTRE RIOS'), 'Entre Ríos');
eq('TIERRA DEL FUEGO',            normalizar_provincia('TIERRA DEL FUEGO'), 'Tierra del Fuego');
eq('SANTIAGO DEL ESTERO',         normalizar_provincia('SANTIAGO DEL ESTERO'), 'Santiago del Estero');
eq('vacío -> null',               normalizar_provincia(''), null);
eq('desconocida se conserva',     normalizar_provincia('São Paulo'), 'São Paulo');

echo "== Normalización de países ==\n";
eq('ARGENTINA',                   normalizar_pais('ARGENTINA'), 'Argentina');
eq('Argentina',                   normalizar_pais('Argentina'), 'Argentina');
eq('Mexico -> México',            normalizar_pais('Mexico'), 'México');
eq('Brazil -> Brasil',            normalizar_pais('Brazil'), 'Brasil');
eq('URL en el campo país se descarta', normalizar_pais('http://www.pecanargentina.com/'), null);
eq('texto larguísimo se descarta', normalizar_pais('Team Leadership, Business Strategy, Negotiation and more'), null);

echo "== Limpieza de localidad (basura del CSV real) ==\n";
eq('localidad normal',            normalizar_localidad('9 DE JULIO'), '9 DE JULIO');
eq('aptitudes de LinkedIn se descartan',
   normalizar_localidad('Microsoft Word, Microsoft Excel, English, Estrategia empresarial, Contabilidad'), null);
eq('dirección completa se descarta',
   normalizar_localidad('Cerrito 1294, Piso 2. (C1010AAZ) Ciudad de Buenos Aires Argentina'), null);
eq('URL se descarta',             normalizar_localidad('https://carvajaltys.com.ar'), null);
eq('vacío -> null',               normalizar_localidad('   '), null);

echo "== CUIT (módulo 11) ==\n";
eq('válido 30-71420341-6',        validar_cuit('30714203416'), true);
eq('válido con guiones',          validar_cuit('30-71420341-6'), true);
eq('dígito verificador malo',     validar_cuit('30714203417'), false);
eq('10 dígitos',                  validar_cuit('3071420341'), false);
eq('normaliza',                   normalizar_cuit('30-71420341-6'), '30714203416');
eq('formatea',                    formatear_cuit('30714203416'), '30-71420341-6');

echo "== Formato de importes ==\n";
eq('ARS',                         money(1234.5), '$ 1.234,50');
eq('USD',                         money(1000, 'USD'), 'US$ 1.000,00');
eq('cero',                        money(0), '$ 0,00');

echo "== URLs (la dirección oficial es intellihelp.tech/crm) ==\n";
eq('url interna respeta BASE_PATH', url('contactos'), '/crm/contactos');
eq('url con barra inicial',       url('/contactos'), '/crm/contactos');
contiene('url_abs usa la canónica', url_abs('r/juan/demo'), 'https://intellihelp.tech/crm/r/juan/demo');
ok('redirect_uri de Google es HTTPS (evita redirect_uri_mismatch)',
   str_starts_with(google_redirect_uri(), 'https://'));
eq('redirect_uri exacto',         google_redirect_uri(), 'https://intellihelp.tech/crm/google-callback');

echo "== Paginación ==\n";
$p = paginar(0, 1, 25);
eq('sin registros hay 1 página',  $p['paginas'], 1);
$p = paginar(51, 3, 25);
eq('offset de la página 3',       $p['offset'], 50);
$p = paginar(51, 99, 25);
eq('página fuera de rango se acota', $p['page'], 3);

echo "== Plantillas de campaña ==\n";
$datos = ['nombre' => 'Juan', 'apellido' => 'Pérez', 'empresa' => 'ACME', 'email' => 'j@acme.com',
          'remitente' => 'Carlos', 'agenda' => 'https://x/agenda', 'baja' => 'https://x/baja'];
eq('reemplaza nombre',            render_plantilla('Hola {{nombre}},', $datos), 'Hola Juan,');
eq('reemplaza empresa',           render_plantilla('{{empresa}}', $datos), 'ACME');
eq('nombre vacío cae en «Hola»',  render_plantilla('{{nombre}}', ['nombre' => '']), 'Hola');
eq('nombre completo',             render_plantilla('{{nombre_completo}}', $datos), 'Juan Pérez');
contiene('deja el enlace de baja', render_plantilla('{{baja}}', $datos), 'https://x/baja');
eq('variable inexistente queda como está', render_plantilla('{{no_existe}}', $datos), '{{no_existe}}');

echo "== Detección de rebotes (lógica de Pulsemail) ==\n";
ok('mailer-daemon de Gmail',      es_rebote('mailer-daemon@googlemail.com', 'Delivery Status Notification'));
ok('postmaster de Exchange',      es_rebote('postmaster@empresa.com', 'Undeliverable'));
ok('MAILER-DAEMON en mayúsculas', es_rebote('MAILER-DAEMON@yahoo.com', 'failure notice'));
ok('mail-daemon con guion',       es_rebote('mail-daemon@dominio.com', 'x'));
ok('por asunto aunque el remitente no lo diga',
   es_rebote('noreply@server.com', 'Undelivered Mail Returned to Sender'));
ok('asunto en castellano',        es_rebote('info@server.com', 'Correo no entregado'));
ok('un cliente normal NO es rebote', !es_rebote('juan@acme.com', 'Re: propuesta comercial'));

echo "== Extracción del destinatario que rebotó ==\n";
$cuerpo = "Delivery incomplete\r\nFinal-Recipient: rfc822; juan@acme.com\r\nAction: failed\r\n";
eq('usa Final-Recipient',         extraer_destinatario_rebote($cuerpo)[0] ?? '', 'juan@acme.com');
$cuerpo2 = "No such user\r\nOriginal-Recipient: rfc822;MARIA@ACME.COM\r\n";
eq('normaliza a minúsculas',      extraer_destinatario_rebote($cuerpo2)[0] ?? '', 'maria@acme.com');
$cuerpo3 = 'El mensaje para pedro@acme.com no pudo entregarse.';
ok('fallback: busca cualquier dirección', in_array('pedro@acme.com', extraer_destinatario_rebote($cuerpo3), true));

echo "== Motor de bloques: el HTML tiene que sobrevivir en Outlook y en Gmail ==\n";
$pre  = plantillas_preajustes()['pulsemail'];
$ctxE = ['empresa' => 'INTELLIHELP', 'empresa_web' => 'intellihelp.tech', 'logo' => 'https://x/logo.png'];
$htmlP = bloques_render($pre['bloques'], $pre['ajustes'], $ctxE);
contiene('arma el documento completo',       $htmlP, '<!DOCTYPE html');
contiene('maqueta con tablas',               $htmlP, '<table role="presentation"');
contiene('ancho fijo de 600 px',             $htmlP, 'max-width:600px');
contiene('estilos en línea',                 $htmlP, 'style="');
contiene('respaldo VML para el botón de Outlook', $htmlP, 'v:roundrect');
contiene('media query para el celular',      $htmlP, '@media only screen and (max-width:620px)');
no_contiene('sin flexbox',                   $htmlP, 'display:flex');
no_contiene('sin grid',                      $htmlP, 'display:grid');
no_contiene('sin imágenes de fondo',         $htmlP, 'background-image');
no_contiene('sin JavaScript',                $htmlP, '<script');
no_contiene('sin formularios',               $htmlP, '<form');
ok('pesa bastante menos que el límite de Gmail', strlen($htmlP) < 102000,
   '(' . round(strlen($htmlP) / 1024, 1) . ' KB)');
ok('los cuatro preajustes están completos',
   count(plantillas_preajustes()) === 4
   && !array_filter(plantillas_preajustes(), fn($p) => empty($p['bloques']) || empty($p['asunto'])));

echo "== Botón de WhatsApp (formato oficial) ==\n";
$wa = bloques_render([['tipo' => 'whatsapp', 'telefono' => '54 9 11 5027-4827',
                       'mensaje' => 'Hola, me interesa conocer más sobre sus soluciones.',
                       'texto' => 'Escribinos']], [], $ctxE);
contiene('usa api.whatsapp.com/send',  $wa, 'api.whatsapp.com/send/?phone=');
contiene('deja el número sin símbolos', $wa, 'phone=5491150274827');
contiene('codifica el mensaje',        $wa, 'text=Hola%2C+me+interesa');
contiene('lleva los parámetros oficiales', $wa, 'type=phone_number&amp;app_absent=0');
contiene('el texto del botón se respeta', $wa, 'Escribinos');
// Sin número el bloque no se dibuja (el documento sigue existiendo, vacío).
no_contiene('sin número no dibuja el botón',
            bloques_render([['tipo' => 'whatsapp']], [], []), 'whatsapp.com');
$waCtx = bloques_render([['tipo' => 'whatsapp']], [], ['empresa_whatsapp' => '5491150274827']);
contiene('si no le ponen número usa el de la empresa', $waCtx, 'phone=5491150274827');

echo "== Botón de agenda ==\n";
$ag = bloques_render([['tipo' => 'agenda', 'texto' => 'Agendar reunión']], [], $ctxE);
contiene('deja la variable para resolver al enviar', $ag, '{{agenda}}');
contiene('respeta el texto del botón',               $ag, 'Agendar reunión');
contiene('se dibuja como botón, con respaldo Outlook', $ag, 'v:roundrect');

echo "== Escapado dentro del correo (no se puede inyectar HTML) ==\n";
$sucio = bloques_render([['tipo' => 'texto', 'texto' => '<script>alert(1)</script> & "comillas"']], [], $ctxE);
no_contiene('el HTML del usuario se escapa',  $sucio, '<script>alert');
contiene('queda como texto',                  $sucio, '&lt;script&gt;');
// Regresión BUG #11 — los esquemas peligrosos pasaban con un `https://` pegado
// adelante en vez de descartarse.
foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)",
          'data:text/html,<script>x</script>', 'vbscript:msgbox'] as $malo) {
    $url = bloques_render([['tipo' => 'boton', 'texto' => 'X', 'enlace' => $malo]], [], $ctxE);
    ok('descarta el enlace «' . recortar($malo, 22) . '»',
       !str_contains($url, 'href') || !preg_match('/javascript|vbscript|data:text/i', $url));
}
$buena = bloques_render([['tipo' => 'boton', 'texto' => 'X', 'enlace' => 'intellihelp.tech/agenda']], [], $ctxE);
contiene('completa el https:// en un enlace suelto', $buena, 'https://intellihelp.tech/agenda');
$varia = bloques_render([['tipo' => 'boton', 'texto' => 'X', 'enlace' => '{{agenda}}']], [], $ctxE);
contiene('deja pasar las variables de plantilla',    $varia, '{{agenda}}');

echo "== Versión en texto plano ==\n";
$txt = bloques_texto($pre['bloques'], $ctxE);
ok('genera texto plano',                      strlen($txt) > 100);
no_contiene('el texto plano no lleva etiquetas', $txt, '<');

echo "== Verificador de entregabilidad ==\n";
$okChk = bloques_verificar($pre['bloques'], 'Una propuesta para {{empresa}}', $htmlP, $txt);
ok('un correo sano no da errores',            count($okChk['errores']) === 0,
   '→ ' . json_encode(array_column($okChk['errores'], 0), JSON_UNESCAPED_UNICODE));
$malChk = bloques_verificar([], 'GRATIS OFERTA LIMITADA ACTÚA AHORA', '<html><script>x</script></html>', '');
ok('detecta el asunto en mayúsculas y las palabras riesgosas', count($malChk['errores']) >= 2);
$conJs = bloques_verificar([], 'Hola', '<html><script>x</script>{{baja}}</html>', 'hola');
ok('detecta JavaScript en el correo',
   (bool) array_filter($conJs['errores'], fn($x) => str_contains($x[0], 'JavaScript')));
$pesado = bloques_verificar([], 'Hola', str_repeat('<p>a</p>', 20000), 'hola');
ok('detecta que Gmail lo va a recortar',
   (bool) array_filter($pesado['errores'], fn($x) => str_contains($x[0], 'pesa')));
$sinTexto = bloques_verificar([], 'Hola', '<html><img src="a"><img src="b"><img src="c"></html>', 'hola');
ok('detecta demasiada imagen y poco texto',
   (bool) array_filter($sinTexto['errores'], fn($x) => str_contains($x[0], 'poco texto')));

echo "== Rebote definitivo vs. pasajero (decide si se archiva el contacto) ==\n";
// Definitivos: la casilla no existe o el dominio no existe. Archivan el contacto.
ok('DSN con estado 5.x.x',        es_rebote_duro("Action: failed\r\nStatus: 5.1.1\r\nDiagnostic-Code: smtp; 550"));
ok('«user unknown»',              es_rebote_duro('550 5.1.1 <x@y.com>: Recipient address rejected: User unknown'));
ok('«does not exist»',            es_rebote_duro('The email account that you tried to reach does not exist.'));
ok('dominio inexistente',         es_rebote_duro('Host or domain name not found. Name service error'));
ok('en castellano',               es_rebote_duro('La dirección no existe en este servidor'));
// Pasajeros: se arreglan solos. NO se archiva a nadie.
ok('buzón lleno NO es definitivo', !es_rebote_duro('552 5.2.2 Mailbox full / quota exceeded'));
ok('DSN con estado 4.x.x',        !es_rebote_duro("Action: delayed\r\nStatus: 4.4.1\r\nWill retry"));
ok('greylisting',                 !es_rebote_duro('451 4.7.1 Greylisted, try again later'));
ok('ante la duda, pasajero',      !es_rebote_duro('Algo raro pasó con este mensaje'));

echo "== Correos ==\n";
ok('válido',                      email_valido('juan.perez@acme.com.ar'));
ok('sin arroba no es válido',     !email_valido('juan.perez'));
eq('se normaliza',                normalizar_email('  Juan@ACME.com  '), 'juan@acme.com');

echo "== Slugs de la agenda pública ==\n";
eq('acentos y espacios',          slugificar('Reunión de presentación'), 'reunion-de-presentacion');
eq('30 min',                      slugificar('Demo 45 min'), 'demo-45-min');
eq('vacío tiene respaldo',        slugificar('   '), 'reunion');
eq('símbolos',                    slugificar('¡Café & Charla!'), 'cafe-charla');

echo "== Vencimiento de actividades ==\n";
[$txt, $cls] = actividad_vencimiento(['vence_el' => date('Y-m-d H:i:s', strtotime('-2 days')),
                                      'inicia_el' => null, 'completada_en' => null]);
eq('vencida se marca en rojo',    $cls, 'badge-rej');
[$txt, $cls] = actividad_vencimiento(['vence_el' => date('Y-m-d H:i:s', strtotime('+2 days')),
                                      'inicia_el' => null, 'completada_en' => null]);
eq('futura es informativa',       $cls, 'badge-info');
[$txt, $cls] = actividad_vencimiento(['vence_el' => date('Y-m-d 23:00:00'),
                                      'inicia_el' => null, 'completada_en' => null]);
eq('la de hoy se destaca',        $cls, 'badge-pend');
[$txt, $cls] = actividad_vencimiento(['vence_el' => date('Y-m-d H:i:s', strtotime('-2 days')),
                                      'inicia_el' => null, 'completada_en' => date('Y-m-d H:i:s')]);
eq('completada aunque venciera',  $cls, 'badge-ok');

echo "== Escapado y formato ==\n";
eq('escapa HTML',                 e('<script>'), '&lt;script&gt;');
eq('escapa comillas',             e('a"b'), 'a&quot;b');
eq('recorta con puntos',          recortar('abcdefghij', 5), 'abcd…');
eq('no recorta si entra',         recortar('abc', 5), 'abc');
eq('iniciales de dos palabras',   iniciales('Juan Pérez'), 'JP');
eq('iniciales de una',            iniciales('Cargill'), 'C');
eq('iniciales de vacío',          iniciales(''), '?');
eq('fecha corta',                 fecha_corta('2026-07-30'), '30/07/2026');
eq('fecha vacía',                 fecha_corta(null), '—');
contiene('fecha larga en castellano', fecha_larga('2026-07-30'), 'de julio de 2026');

echo "== Badges ==\n";
contiene('envío enviado',         badge_envio('enviado'), 'badge-ok');
contiene('envío rebotado',        badge_envio('rebotado'), 'badge-rej');
contiene('campaña enviando',      badge_campana('enviando'), 'badge-ok');
no_contiene('nunca un badge pelado', badge_envio('pendiente'), 'class="badge "');

echo "== Filtros del CRON (regresión BUG #1) ==\n";
// BUG #1 — el listado del CRON hace JOIN con `usuarios`, que también tiene
// `creado_en`: sin calificar las columnas, MySQL rechazaba la consulta por
// ambigüedad y la pantalla no abría.
[$where, $params] = actividad_where(['canal' => 'app', 'q' => 'hola', 'desde' => '2026-01-01']);
contiene('la fecha va calificada',   $where, 'a.creado_en');
contiene('el canal va calificado',   $where, 'a.canal');
contiene('la búsqueda va calificada', $where, 'a.actor LIKE');
ok('ninguna columna queda sin alias',
   !preg_match('/(?<![a-z.])(creado_en|canal|tipo|actor|accion|detalle|ip)\s*(>=|<=|=|LIKE)/', $where));
// canal + desde + los cuatro LIKE de la búsqueda
eq('los parámetros acompañan al WHERE', count($params), 6);

echo "== Cifrado de los tokens de Google ==\n";
$claro = 'refresh-token-de-prueba-1234';
$cifr  = cifrar($claro);
ok('el cifrado no deja el texto en claro', !str_contains($cifr, $claro));
eq('descifra correctamente',      descifrar($cifr), $claro);
eq('blob corrupto no revienta',   descifrar('no-es-base64-valido'), '');

echo "== Invitación de calendario (el botón de aceptar del correo) ==\n";
// El archivo de calendario es lo que hace que Gmail muestre «Sí / No / Quizás».
// Si se pasa del ancho o corta un acento al medio, el cliente lo descarta y el
// correo vuelve a quedar sin forma de aceptar la reunión.
$corta = ics_plegar(['SUMMARY:Reunión corta']);
eq('una línea corta no se toca', $corta, "SUMMARY:Reunión corta\r\n");

$largo  = 'DESCRIPTION:' . str_repeat('Coordinación de la reunión con INTELLIHELP. ', 6);
$plegado = ics_plegar([$largo]);
$fisicas = explode("\r\n", rtrim($plegado, "\r\n"));
ok('una línea larga se corta en varias', count($fisicas) > 1, '→ ' . count($fisicas));
$anchas = array_filter($fisicas, fn($l) => strlen($l) > 75);
eq('ninguna línea pasa los 75 octetos', count($anchas), 0);
ok('las continuaciones arrancan con un espacio',
   count(array_filter(array_slice($fisicas, 1), fn($l) => $l[0] === ' ')) === count($fisicas) - 1);
// El corte se cuenta en octetos, así que puede caer justo en el medio de un
// carácter de dos bytes. Al volver a unir, el texto tiene que quedar igual.
$unido = str_replace("\r\n ", '', $plegado);
eq('al desplegarla vuelve a ser la misma', rtrim($unido, "\r\n"), $largo);
ok('los acentos quedan intactos', preg_match('//u', $plegado) === 1);

// Peor caso: un acento justo en el límite del corte.
$borde = 'X:' . str_repeat('a', 72) . 'ñññññ';
$pl    = ics_plegar([$borde]);
ok('un acento sobre el corte no se parte', preg_match('//u', $pl) === 1);
eq('y el texto se recupera entero', rtrim(str_replace("\r\n ", '', $pl), "\r\n"), $borde);

echo "\n";
echo str_repeat('=', 56) . "\n";
printf("  %d pruebas · %d correctas · %d fallidas\n", $T, $T - $F, $F);
if ($fallos) {
    echo "\n  Fallaron:\n";
    foreach ($fallos as $f) { echo "   - $f\n"; }
}
echo str_repeat('=', 56) . "\n";
exit($F > 0 ? 1 : 0);

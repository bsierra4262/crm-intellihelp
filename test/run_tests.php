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

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'crm.intellihelp.tech';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$GLOBALS['CONFIG'] = [
    'app' => [
        'name'      => 'CRM Test',
        'canonical' => 'https://crm.intellihelp.tech',
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
require $SRC . '/actividades.php';
require $SRC . '/envio.php';
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

echo "== URLs (subdominio y subcarpeta conviven) ==\n";
eq('url interna respeta BASE_PATH', url('contactos'), '/crm/contactos');
eq('url con barra inicial',       url('/contactos'), '/crm/contactos');
contiene('url_abs usa la canónica', url_abs('r/juan/demo'), 'https://crm.intellihelp.tech/r/juan/demo');
ok('redirect_uri de Google es HTTPS (evita redirect_uri_mismatch)',
   str_starts_with(google_redirect_uri(), 'https://'));
eq('redirect_uri exacto',         google_redirect_uri(), 'https://crm.intellihelp.tech/google-callback');

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

echo "== Cifrado de los tokens de Google ==\n";
$claro = 'refresh-token-de-prueba-1234';
$cifr  = cifrar($claro);
ok('el cifrado no deja el texto en claro', !str_contains($cifr, $claro));
eq('descifra correctamente',      descifrar($cifr), $claro);
eq('blob corrupto no revienta',   descifrar('no-es-base64-valido'), '');

echo "\n";
echo str_repeat('=', 56) . "\n";
printf("  %d pruebas · %d correctas · %d fallidas\n", $T, $T - $F, $F);
if ($fallos) {
    echo "\n  Fallaron:\n";
    foreach ($fallos as $f) { echo "   - $f\n"; }
}
echo str_repeat('=', 56) . "\n";
exit($F > 0 ? 1 : 0);

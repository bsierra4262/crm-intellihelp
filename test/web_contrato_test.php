<?php
declare(strict_types=1);

// ============================================================
//  Contrato entre la web y el CRM — se corre en la máquina local.
//
//  Los dos lados arman por separado el texto que se firma, el hash de la IP
//  del visitante y las reglas de validación. Si uno cambia y el otro no, en
//  producción aparece un 401 o un 422 que nadie entiende. Este test carga
//  las dos implementaciones y compara.
//
//  No toca ninguna base ni la red. La web no se publica desde acá: sólo se
//  leen sus archivos.
//
//  Uso (desde la raíz del repo; la web es otro repositorio, NEW-PAGE-ITH,
//  que se espera al lado de este):
//      WEB_SRC=../NEW-PAGE-ITH/TEMPLATE-NICO/api php test/web_contrato_test.php
//  La web en vivo es TEMPLATE-NICO (la vieja WEB/public/api ya no existe).
//  Sin WEB_SRC busca ../../NEW-PAGE-ITH/TEMPLATE-NICO/api relativo a test/ (la
//  misma carpeta). CRM_SRC permite apuntar a otra copia de app/web/app.
//  Si no encuentra _crm.php de la web sale con código 2.
//
//  Contra qué base: ninguna. Sólo crea una carpeta temporal en el directorio
//  temporal del sistema para el token y el límite por IP de la web, y la
//  borra al terminar. Necesita mbstring.
//  No lo lanza `deploy.py test`: se corre a mano antes de publicar la web.
//  Salida: 0 todo bien, 1 alguna falla.

$WEB = rtrim((string)(getenv('WEB_SRC') ?: (__DIR__ . '/../../NEW-PAGE-ITH/TEMPLATE-NICO/api')), '/\\');
$SRC = rtrim((string)(getenv('CRM_SRC') ?: (__DIR__ . '/../app/web/app')), '/\\');
if (!is_file($WEB . '/_crm.php')) {
    fwrite(STDERR, "No encuentro la web en $WEB (WEB_SRC).\n");
    exit(2);
}

// Config mínima del CRM: sólo el secreto, del que sale crm_secret().
$GLOBALS['CONFIG'] = ['app' => ['secret' => str_repeat('s', 40)]];
require $SRC . '/helpers.php';
require $SRC . '/crm.php';
require $SRC . '/api.php';
// La biblioteca de la web lee su configuración de ITH_CRM_CONFIG: se la
// apunta a un archivo que no existe para que no levante la de un entorno
// real.
// La biblioteca de la web no responde nada cuando se la incluye.
putenv('ITH_CRM_CONFIG=' . __DIR__ . '/no-existe.php');
require $WEB . '/_crm.php';

// Contadores globales: $T total, $F fallidas.
$T = 0; $F = 0;
/** Registra una comprobación e imprime [OK] o [XX]. */
function ok(string $n, bool $c): void
{
    global $T, $F;
    $T++;
    if (!$c) { $F++; echo "  [XX] $n\n"; } else { echo "  [OK] $n\n"; }
}

// ---- Firma: crm_firmar() de la web contra api_firma() del CRM, y los
// mismos vectores fijos que usa run_tests.php ----
echo "== Firma ==\n";
$sec  = 'secreto-de-prueba-0123456789abcdef';
$ts   = '1793709000';
$idem = 'web-0123456789abcdef0123456789abcdef01234567';
$iph  = str_repeat('ab', 32);
$body = '{"tipo":"diagnostico"}';
ok('POST: la web firma igual que el CRM',
   crm_firmar($sec, $ts, 'POST', '/crm-pruebas/api/v1/reserva', $idem, $iph, $body)
   === api_firma($sec, $ts, 'POST', '/crm-pruebas/api/v1/reserva', $idem, $iph, $body));
ok('POST: el vector fijo de run_tests.php',
   crm_firmar($sec, $ts, 'POST', '/crm-pruebas/api/v1/reserva', $idem, $iph, $body)
   === '47924e27c99926de0277e17806be3d16f4e83f5241b26535469e4c4d829e511c');
$rutaGet = '/crm/api/v1/disponibilidad?tipo=diagnostico&vendedor=bsierra&desde=2026-11-02&hasta=2026-11-15';
ok('GET: igual, con las cabeceras de escritura vacías',
   crm_firmar($sec, $ts, 'GET', $rutaGet, '', '', '') === api_firma($sec, $ts, 'GET', $rutaGet, '', '', ''));
ok('GET: el vector fijo de run_tests.php',
   crm_firmar($sec, $ts, 'GET', $rutaGet, '', '', '') === 'fce30eb9840e1593f0efd48f878fdc96487f69b7ffe28928eae4808226562b8a');

// ---- IP: misma red (/64 en IPv6) de los dos lados y hash con sal ----
echo "== IP del visitante ==\n";
foreach (['203.0.113.7', '2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::1'] as $ip) {
    ok("la red de $ip es la misma de los dos lados", crm_ip_red($ip) === ip_red($ip));
}
$cfg = ['ip_sal' => str_repeat('x', 40)];
ok('dos direcciones del mismo /64 dan el mismo hash',
   crm_ip_hash('2001:db8:1:2::1', $cfg) === crm_ip_hash('2001:db8:1:2:ffff::9', $cfg));
ok('el hash lleva sal (no es el sha256 de la IP)', crm_ip_hash('203.0.113.7', $cfg) !== hash('sha256', '203.0.113.7'));

// ---- Validación: lo que la web rechaza en su formulario, el CRM también
// (y lo que acepta, también) ----
// $persona() simula un POST del formulario de la web y devuelve lo que arma
// crm_persona_del_formulario(): [datos, mensaje de error].
echo "== Validación de la persona ==\n";
$persona = static function (array $post): array {
    $_POST = $post + ['nombre' => 'Juana Prueba', 'empresa' => 'Empresa de Prueba SRL',
                      'mail' => 'bsierra@intellihelp.tech', 'telefono' => '', 'necesidad' => '', 'mensaje' => ''];
    return crm_persona_del_formulario();
};
foreach ([
    ['nombre' => 'Juan, verificá en https://evil.example/login'],
    ['nombre' => 'Ver www.evil.example'],
    ['empresa' => 'ACME https://evil.example'],
    ['nombre' => str_repeat('a', 121)],
] as $caso) {
    [$d, $error] = $persona($caso);
    [, $errCrm] = api_validar_entrada(['persona' => crm_persona($d)], false);
    $campo = array_key_first($caso);
    ok('la web rechaza lo mismo que el CRM: ' . $campo . ' «' . mb_substr(reset($caso), 0, 30) . '»',
       $error !== '' && isset($errCrm[$campo]));
}
[$d, $error] = $persona([]);
[, $errCrm] = api_validar_entrada(['persona' => crm_persona($d)], false);
ok('y acepta lo mismo', $error === '' && !array_intersect(['nombre', 'empresa', 'email'], array_keys($errCrm)));

// ---- Token del formulario de la web (sirve para hasta tres reservas
// distintas y sus reintentos) y límite por IP en archivos ----
echo "== Token del formulario y límite de la web ==\n";
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ith-contrato-' . bin2hex(random_bytes(4));
$cfgW = ['secreto' => str_repeat('c', 64), 'cache' => $tmp, 'ip_sal' => str_repeat('x', 40)];
$m = null;
ok('token recién generado: demasiado rápido', !crm_form_token_valido($cfgW, crm_form_token($cfgW), 'idem-1', $m) && $m === 'rapido');
$viejo = (string)(time() - 10);
$nonce = bin2hex(random_bytes(8));
$tok = $viejo . '.' . $nonce . '.' . hash_hmac('sha256', 'agenda-web|' . $viejo . '|' . $nonce, $cfgW['secreto']);
ok('token de hace 10 s: válido', crm_form_token_valido($cfgW, $tok, 'idem-1', $m));
ok('el reintento de la misma reserva: también', crm_form_token_valido($cfgW, $tok, 'idem-1', $m));
ok('hasta tres reservas distintas con el mismo token',
   crm_form_token_valido($cfgW, $tok, 'idem-2', $m) && crm_form_token_valido($cfgW, $tok, 'idem-3', $m));
ok('la cuarta no', !crm_form_token_valido($cfgW, $tok, 'idem-4', $m) && $m === 'usado');
// Se altera el último carácter cambiándolo por «0». Si ya terminaba en «0»
// el token queda igual (pasa a ser el quinto uso y da «usado», no
// «invalido»): la comprobación falla una vez cada 16 corridas.
ok('un token alterado no', !crm_form_token_valido($cfgW, substr($tok, 0, -1) . '0', 'idem-9', $m) && $m === 'invalido');
@mkdir($tmp . '/lim', 0700, true);
for ($i = 0; $i < 3; $i++) { limite_ip('prueba', '203.0.113.7', 3, 600, $tmp . '/lim'); }
ok('el límite frena el cuarto envío', limite_ip('prueba', '203.0.113.7', 3, 600, $tmp . '/lim'));
$archivos = glob($tmp . '/lim/ith-prueba-*.json') ?: [];
ok('el contador no lleva el sha256 pelado de la IP',
   count($archivos) === 1 && !str_contains($archivos[0], hash('sha256', '203.0.113.7')));
// Limpieza de la carpeta temporal (incluidos los archivos ocultos).
array_map('unlink', array_filter(array_merge(glob($tmp . '/lim/{,.}*', GLOB_BRACE) ?: [], glob($tmp . '/*.json') ?: []), 'is_file'));
@rmdir($tmp . '/lim');
@rmdir($tmp);

// ---- Publicación: el .htaccess de /api de la web sólo deja pasar
// contact.php y agenda.php; _crm.php queda negado ----
echo "== Publicación ==\n";
$ht = (string) file_get_contents($WEB . '/.htaccess');
// La lista de permitidos puede crecer (busquedas.php, postulaciones): alcanza
// con que contact y agenda estén entre los que se dejan pasar.
$permitidos = preg_match('/FilesMatch "\^\(([a-z|]+)\)\\\\\.php\$"/', $ht, $mPerm) ? explode('|', $mPerm[1]) : [];
ok('el .htaccess de /api deja pasar contact.php y agenda.php',
   in_array('contact', $permitidos, true) && in_array('agenda', $permitidos, true));
ok('y sigue negando todo lo demás (_crm.php incluido)', str_contains($ht, 'Require all denied')
   && !preg_match('/_crm/', preg_replace('/^#.*$/m', '', $ht)));

// ---- Resumen y código de salida (0 = todo bien, 1 = alguna falla) ----
echo "\n  $T pruebas · " . ($T - $F) . " correctas · $F fallidas\n";
exit($F > 0 ? 1 : 0);

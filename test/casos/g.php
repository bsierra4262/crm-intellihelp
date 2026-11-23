<?php
declare(strict_types=1);

// ============================================================
//  Casos del cierre, sin base: qué pedidos del píxel son de una máquina
//  (apertura_automatica de campanas.php). Los carga run_tests.php.
// ============================================================

echo "== Cierre · Aperturas automáticas del píxel ==\n";
$gUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36';
$gHace = fn(int $s) => date('Y-m-d H:i:s', time() - $s);
eq('una persona en su navegador cuenta', apertura_automatica('190.2.3.4', $gUA, $gHace(3600)), null);
ok('Apple Mail (IPv4 17/8) no cuenta', str_starts_with((string) apertura_automatica('17.58.1.2', 'Mozilla/5.0', $gHace(3600)), 'Apple Mail'));
ok('Apple Mail por IPv6 no cuenta', str_starts_with((string) apertura_automatica('2620:149:a:b::1', 'Mozilla/5.0', $gHace(3600)), 'Apple Mail'));
eq('otra IPv6 cualquiera cuenta', apertura_automatica('2800:810::1', $gUA, $gHace(3600)), null);
eq('un 17 que no está al principio no es Apple', apertura_automatica('190.17.1.2', $gUA, $gHace(3600)), null);
eq('el proxy de Gmail es una persona abriendo', apertura_automatica('66.249.1.1', 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', $gHace(3600)), null);
eq('un filtro corporativo no cuenta', apertura_automatica('200.1.1.1', 'Mimecast-Url-Checker/2.0', $gHace(3600)), 'Antivirus o filtro de correo');
eq('sin User-Agent no cuenta', apertura_automatica('200.1.1.1', '', $gHace(3600)), 'Antivirus o filtro de correo');
ok('a los 3 segundos de la entrega no cuenta', apertura_automatica('200.1.1.1', $gUA, $gHace(3)) !== null);
eq('a los 30 segundos sí', apertura_automatica('200.1.1.1', $gUA, $gHace(30)), null);
eq('sin fecha de envío, decide el resto', apertura_automatica('200.1.1.1', $gUA, null), null);

$gPx = (string) file_get_contents((getenv('CRM_SRC') ?: __DIR__ . '/../../app/web/app') . '/pages/px.php');
ok('el píxel consulta apertura_automatica', str_contains($gPx, 'apertura_automatica('));
ok('la automática no llena abierto_en', (bool) preg_match('/if \(\$auto !== null\) \{(?:(?!abierto_en = NOW).)*\} elseif/s', $gPx));

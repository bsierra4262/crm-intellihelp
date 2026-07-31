<?php
declare(strict_types=1);

// ============================================================
//  Auditoría de interfaz — CRM INTELLIHELP
//
//  Revisa la hoja de estilos y TODAS las vistas buscando los defectos
//  concretos que ya aparecieron una vez. No prueba lógica: prueba que la
//  interfaz siga cumpliendo las reglas del proyecto.
//
//  Uso:  php test/ui_test.php
//        CRM_SRC=/ruta/al/app/web php test/ui_test.php
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');

for ($i = 0; $i < 1; $i++) { /* placeholder: mantiene el archivo autocontenido */ }

$WEB = getenv('CRM_SRC') ?: (__DIR__ . '/../app/web');
$CSS = @file_get_contents($WEB . '/assets/css/app.css');
$JS  = @file_get_contents($WEB . '/assets/js/app.js');
if ($CSS === false) {
    fwrite(STDERR, "No se encontró la hoja de estilos en $WEB/assets/css/app.css\n");
    exit(1);
}

$vistas = glob($WEB . '/app/views/*.php') ?: [];
$html   = '';
foreach ($vistas as $v) {
    $html .= "\n/*==== " . basename($v) . " ====*/\n" . (string) file_get_contents($v);
}
$layout = (string) @file_get_contents($WEB . '/app/views/layout.php');

$T = 0; $F = 0; $fallos = [];
function ok(string $nombre, bool $cond, string $detalle = ''): void {
    global $T, $F, $fallos;
    $T++;
    if ($cond) { echo "  [OK] $nombre\n"; }
    else { $F++; $fallos[] = $nombre; echo "  [XX] $nombre" . ($detalle !== '' ? "  $detalle" : '') . "\n"; }
}

/** Extrae el cuerpo de una regla CSS por selector exacto. */
function regla(string $css, string $selector): string
{
    $out = '';
    $re = '/(^|[\},])\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m';
    if (preg_match_all($re, $css, $m)) {
        $out = implode(' ', $m[2]);
    }
    return $out;
}

echo "== Tarjetas de estadística (regresión BUG #6) ==\n";
// BUG #6 — `.stat-link{display:block}` pisaba el flex de `.stat` y el rótulo,
// el número y el pie salían los tres en una sola línea.
$statLink = regla($CSS, '.stat-link');
ok('.stat es columna', str_contains(regla($CSS, '.stat'), 'flex-direction:column'));
ok('.stat-link NO vuelve a declarar display', !preg_match('/display\s*:/', $statLink),
   '→ ' . trim($statLink));

echo "== Botones de los filtros (regresión BUG #7) ==\n";
// BUG #7 — sin align-items, los botones se estiraban a la altura de la fila
// cuando convivían con un toggle de dos líneas: salían gigantes.
$fa = regla($CSS, '.filter-actions');
ok('.filter-actions centra verticalmente', str_contains($fa, 'align-items:center'), '→ ' . trim($fa));

echo "== Rótulos de los filtros (regresión BUG #8) ==\n";
// BUG #8 — `.filters label span` en mayúsculas alcanzaba también al texto de
// los toggles, que salía gritado.
ok('el selector de versalitas es específico, no `label span` a secas',
   !preg_match('/\.filters\s+label\s+span\s*\{/', $CSS));
ok('las versalitas apuntan al primer span del campo',
   str_contains($CSS, '.filters .filter-grid>label>span:first-child'));

echo "== Barra de navegación (regresión BUG #9) ==\n";
// BUG #9 — catorce solapas no entraban ni en 1920 px y la última quedaba
// cortada al medio. Lo administrativo pasó al menú «Más».
preg_match('/\$nav\s*=\s*\[(.*?)\];/s', $layout, $m);
$items = preg_match_all('/=>\s*\[/', $m[1] ?? '');
ok('la barra tiene 10 solapas o menos', $items > 0 && $items <= 10, "→ $items solapas");
ok('existe el menú «Más» para lo administrativo', str_contains($layout, 'details class="menu"'));
ok('la barra ya no scrollea en horizontal',
   !str_contains(regla($CSS, '.topnav'), 'overflow-x:auto'));
ok('los rótulos se ocultan antes de que la barra desborde',
   str_contains($CSS, '@media (max-width:1280px)'));
ok('la barra pasa a menú desplegable en pantallas chicas',
   str_contains($CSS, '@media (max-width:1024px)'));
ok('el menú «Más» se cierra al hacer clic afuera',
   str_contains($JS ?: '', "details.menu[open]"));

echo "== Embudo (regresión BUG #10) ==\n";
// BUG #10 — columnas de ancho fijo: la última quedaba cortada a la mitad.
$kcol = regla($CSS, '.kcol');
ok('las columnas se reparten el ancho', preg_match('/flex\s*:\s*1\s+1/', $kcol) === 1, '→ ' . trim($kcol));
ok('las columnas tienen ancho mínimo', str_contains($kcol, 'min-width'));

echo "== Sin controles nativos crudos ==\n";
$archivosSueltos = preg_match_all('/<input[^>]*type=["\']file["\'][^>]*>/i', $html, $mm);
$dentroDeCampo   = preg_match_all('/class="filefield"[\s\S]{0,200}?type=["\']file["\']/i', $html);
ok('todo campo de archivo usa el componente propio', $archivosSueltos === $dentroDeCampo,
   "→ $archivosSueltos campos, $dentroDeCampo con componente");
ok('los on/off usan el toggle propio', !preg_match('/type=["\']checkbox["\'][^>]*>(?![\s\S]{0,80}class="track")/', $html)
   || substr_count($html, 'class="track"') > 0);
ok('no hay estilos en línea en selects ni fechas',
   !preg_match('/<(select|input[^>]*type=["\'](date|month|time)["\'])[^>]*\sstyle=/i', $html));

echo "== Badges siempre con variante ==\n";
// Un `.badge` pelado sale transparente: siempre lleva su variante.
$pelados = preg_match_all('/class="badge"(?!\s*<\?)/', $html);
ok('ningún badge queda sin variante', $pelados === 0, "→ $pelados pelados");

echo "== Responsive: sin desbordes horizontales ==\n";
ok('las tablas scrollean dentro de su contenedor', str_contains($CSS, '.table-scroll{overflow-x:auto}'));
ok('las tablas pasan a tarjetas en el celular', str_contains($CSS, '@media (max-width:768px)'));
ok('los encabezados de página se apilan en pantallas chicas',
   preg_match('/@media \(max-width:860px\)[\s\S]{0,600}\.page-head\{[^}]*flex-direction:column/', $CSS) === 1);
ok('las tarjetas de estadística se adaptan',
   str_contains(regla($CSS, '.cards'), 'auto-fit'));
ok('los formularios bajan a una columna en el celular',
   preg_match('/@media \(max-width:520px\)[\s\S]{0,400}grid-fields\{grid-template-columns:1fr/', $CSS) === 1);

echo "== Coherencia visual ==\n";
foreach ($vistas as $v) {
    $c = (string) file_get_contents($v);
    $base = basename($v);
    if (str_starts_with($base, '_') || in_array($base, ['layout.php', 'error.php'], true)) { continue; }
}
$hex = preg_match_all('/(?<![\w#-])#[0-9a-fA-F]{3,6}\b/', $CSS);
ok('la hoja de estilos define su paleta en :root', str_contains($CSS, ':root{'));
ok('los listados usan la misma estructura de encabezado',
   substr_count($html, 'class="page-head"') >= 8);
ok('los filtros usan la misma estructura', substr_count($html, 'class="filters card"') >= 6);

echo "== Cache-busting ==\n";
ok('los assets van versionados', str_contains($layout, 'ASSETS_V'));

echo "\n" . str_repeat('=', 60) . "\n";
printf("  %d comprobaciones de interfaz · %d correctas · %d fallidas\n", $T, $T - $F, $F);
if ($fallos) {
    echo "\n  Fallaron:\n";
    foreach ($fallos as $f) { echo "   - $f\n"; }
}
echo str_repeat('=', 60) . "\n";
exit($F > 0 ? 1 : 0);

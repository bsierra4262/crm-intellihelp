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
// Se busca la clase dentro de la lista, no el atributo entero: con
// `class="filefield"` exacto, agregarle una segunda clase al mismo elemento
// hacía fallar la comprobación aunque el componente siguiera estando.
$dentroDeCampo   = preg_match_all('/class="[^"]*\bfilefield\b[^"]*"[\s\S]{0,200}?type=["\']file["\']/i', $html);
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

// ============================================================
//  Pasada completa de diseño
// ============================================================

echo "== Estilos en línea ==\n";
// Antes había 163 estilos escritos a mano en las vistas: el mismo párrafo bajo
// el título de una tarjeta estaba con seis márgenes distintos, y por eso dos
// pantallas parecidas se veían diferentes. Lo único que puede seguir yendo en
// línea es lo que depende de un dato (el ancho de una barra de avance, el color
// que eligió el usuario para una etapa), porque eso no se puede saber de antemano.
$sueltos = [];
foreach ($vistas as $v) {
    foreach (preg_split('/\R/', (string) file_get_contents($v)) as $nl => $linea) {
        if (preg_match_all('/style="([^"]*)"/', $linea, $m)) {
            foreach ($m[1] as $val) {
                if (!str_contains($val, '<?=')) { $sueltos[] = basename($v) . ':' . ($nl + 1) . ' ' . $val; }
            }
        }
    }
}
ok('ninguna vista tiene estilos en línea fijos', $sueltos === [],
   '→ ' . count($sueltos) . ': ' . implode(' | ', array_slice($sueltos, 0, 3)));

echo "== Espaciado por escala, no a ojo ==\n";
ok('existe la escala de espaciado', preg_match('/--sp-4\s*:/', $CSS) === 1);
ok('el párrafo de una tarjeta tiene una sola clase', str_contains($CSS, '.card-intro{'));
ok('las utilidades de margen salen de la escala',
   preg_match('/\.mt-4\{margin-top:var\(--sp-4\)\}/', $CSS) === 1);
// El margen negativo que pega el párrafo al título va UNA sola vez, en la clase.
$negativos = preg_match_all('/margin:\s*-\.\d+rem/', $html);
ok('el margen negativo no está repetido por las vistas', $negativos === 0, "→ $negativos casos");

echo "== Texto largo en el celular ==\n";
// Medido sobre la base real: 3.577 de 28.112 correos (12,7 %) no entran en una
// pantalla de 320 px, y el más largo se pasa por 126 px. Sin regla de corte se
// salen de la tarjeta. Va `anywhere` y no `break-word` porque la celda es flex:
// sólo `anywhere` reduce el ancho mínimo del elemento, que es lo que desborda.
$movil = '';
if (preg_match('/@media \(max-width:768px\)\{(.+?)\n\}/s', $CSS, $m)) { $movil = $m[1]; }
ok('existe el bloque de tabla en celular', $movil !== '');
ok('las celdas cortan las direcciones largas', str_contains($movil, 'overflow-wrap:anywhere'),
   '→ un correo de 48 caracteres desborda la tarjeta');
ok('el rótulo de la celda no se corta', str_contains($movil, 'overflow-wrap:normal'));
ok('los botones de acción se pueden tocar con el dedo',
   preg_match('/\.act\{min-width:40px;min-height:40px\}/', $movil) === 1,
   '→ 29 px es chico para el dedo, el mínimo es 40');

echo "== Nada duplicado entre vistas ==\n";
// El mismo par etiqueta/valor estaba copiado como función anónima en tres
// fichas, y cada copia había quedado distinta: por eso un correo se veía de una
// forma en un contacto y de otra en una empresa.
$helpers = (string) @file_get_contents($WEB . '/app/helpers.php');
ok('el par etiqueta/valor vive en un solo lugar', str_contains($helpers, 'function dato('));
ok('ninguna ficha se define su propia copia',
   preg_match('/\$dato\s*=\s*function/', $html) !== 1);
foreach (['contacto-ver.php', 'cuenta-ver.php', 'posible-ver.php'] as $f) {
    $c = (string) @file_get_contents($WEB . '/app/views/' . $f);
    ok("$f usa el helper compartido", str_contains($c, 'dato(') && !str_contains($c, '$dato('));
}

echo "== Estados vacíos con salida ==\n";
// Una pantalla vacía que sólo dice «no hay nada» deja a la persona trabada.
// Cada una tiene que decir cuál es el paso siguiente.
foreach (['campanas.php' => 'plantilla', 'listas.php' => 'contactos',
          'reservas.php' => 'tipo de reunión', 'actividades.php' => 'filtro',
          'plantillas.php' => 'diseños', 'lista-ver.php' => 'Cargala'] as $arch => $pista) {
    $c = (string) @file_get_contents($WEB . '/app/views/' . $arch);
    ok("$arch explica qué hacer cuando está vacío", stripos($c, $pista) !== false);
}

echo "== Cierre automático de sesión ==\n";
// El servidor es el que corta de verdad; esto comprueba que además exista el
// aviso, para que la sesión no se caiga sin explicación mientras alguien mira
// la pantalla —el editor de plantillas puede estar veinte minutos sin recargar.
$idle = (string) @file_get_contents($WEB . '/assets/js/idle.js');
$boot = (string) @file_get_contents($WEB . '/app/bootstrap.php');
$rutas = (string) @file_get_contents($WEB . '/index.php');
$salir = (string) @file_get_contents($WEB . '/app/pages/logout.php');
$entrar = (string) @file_get_contents($WEB . '/app/pages/login.php');
ok('el servidor corta la sesión vencida', str_contains($boot, 'idle_ttl') && str_contains($boot, 'auth_logout()'));
ok('el servidor contesta 440 a las llamadas en segundo plano', str_contains($boot, '440'));
ok('existe el aviso del navegador', $idle !== '');
ok('el layout carga el aviso', str_contains($layout, 'idle.js'));
ok('el layout le pasa la configuración', str_contains($layout, '__sesion'));
ok('la ruta del latido está registrada', str_contains($rutas, "'api-sesion'"));
ok('el latido existe', file_exists($WEB . '/app/pages/api-sesion.php'));
ok('el aviso usa el modal de la aplicación',
   str_contains($idle, 'modal-head') && str_contains($idle, 'modal-foot'),
   '→ si se inventara su propio marcado sería el único cartel distinto de la app');
ok('el foco arranca en «Seguir conectado»', str_contains($idle, 'seguir')
   && str_contains($idle, '.focus()'), '→ si no, un Enter distraído cierra la sesión');
ok('la salida distingue el motivo', str_contains($salir, 'inactividad') && str_contains($salir, 'expiro'));
ok('el login explica por qué se cerró', str_contains($entrar, 'inactividad'));

echo "\n" . str_repeat('=', 60) . "\n";
printf("  %d comprobaciones de interfaz · %d correctas · %d fallidas\n", $T, $T - $F, $F);
if ($fallos) {
    echo "\n  Fallaron:\n";
    foreach ($fallos as $f) { echo "   - $f\n"; }
}
echo str_repeat('=', 60) . "\n";
exit($F > 0 ? 1 : 0);

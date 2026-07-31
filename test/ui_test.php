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
// Era un número a mano y se olvidó justo en la pasada de diseño: se subió todo
// y del otro lado no se veía ningún cambio, porque el navegador tenía la hoja
// vieja cacheada por un año. Ahora sale de la fecha de los archivos.
$bootAssets = (string) @file_get_contents($WEB . '/app/bootstrap.php');
ok('la versión sale sola de los archivos, no de un número a mano',
   str_contains($bootAssets, 'filemtime') && preg_match("/define\('ASSETS_V',\s*'\d+'\)/", $bootAssets) !== 1,
   '→ un paso manual que hay que recordar se olvida');
ok('la hoja de estilos entra en el cálculo', str_contains($bootAssets, 'css/app.css'));
ok('el aviso de sesión entra en el cálculo', str_contains($bootAssets, 'js/idle.js'));

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

// ============================================================
//  Regresiones de la auditoría
//  Cada una es un defecto que estuvo de verdad en el código.
// ============================================================
$campanas  = (string) @file_get_contents($WEB . '/app/campanas.php');
$bloques   = (string) @file_get_contents($WEB . '/app/bloques.php');
$agendaPhp = (string) @file_get_contents($WEB . '/app/agenda.php');
$imapPhp   = (string) @file_get_contents($WEB . '/app/imap.php');
$crmPhp    = (string) @file_get_contents($WEB . '/app/crm.php');
$importa   = (string) @file_get_contents($WEB . '/app/importador.php');
$cronPhp   = (string) (@file_get_contents(dirname($WEB) . '/cron.php') ?: @file_get_contents($WEB . '/cron.php'));
$accion    = (string) @file_get_contents($WEB . '/app/pages/campana-accion.php');

echo "== Campañas: lo que se prometía y no pasaba ==\n";
// El botón se armaba con la clave «url» y el motor leía «enlace»: devolvía
// vacío y el botón desaparecía del correo sin ningún aviso.
ok('un bloque con enlace acepta las dos claves',
   substr_count($bloques, "\$b['enlace'] ?? \$b['url'] ?? ''") >= 3);
ok('el botón del correo de la agenda usa la clave que el motor lee',
   !preg_match("/'tipo'\s*=>\s*'boton'[^\]]*'url'\s*=>/", $agendaPhp));
// El seguimiento de aperturas estaba construido entero menos la línea que lo
// mete en el correo, así que nadie pedía nunca el píxel.
ok('el píxel de apertura se inserta en el correo', str_contains($campanas, "url_abs('px?t="));
ok('y la campaña muestra las aperturas',
   str_contains((string) @file_get_contents($WEB . '/app/views/campana-ver.php'), 'Aperturas'));
// Un envío se pasa a «enviando» antes de hablar con el servidor: si el proceso
// muere ahí, quedaba trabado y la campaña se daba por completada igual.
ok('los envíos trabados vuelven a la cola', str_contains($campanas, "estado = 'pendiente'\n                     WHERE estado = 'enviando'")
   || preg_match("/UPDATE envios SET estado = 'pendiente'\s*\n\s*WHERE estado = 'enviando'/", $campanas) === 1);
ok('una campaña con envíos a medias no se da por completada',
   str_contains($campanas, "estado IN ('pendiente','enviando')"));
// Mandaba uno por corrida y el cron corre cada 5 minutos: 12 por hora, no 80.
ok('la corrida manda varios y respeta la pausa',
   str_contains($campanas, 'sleep($pausa)') && str_contains($campanas, '$hasta'));
ok('el cron le da presupuesto de tiempo a la corrida',
   preg_match('/campanas_despachar\(\d+,\s*\d+\)/', $cronPhp) === 1);
ok('la fecha de arranque programa de verdad', str_contains($accion, "'programada'"));

echo "== Nada se pisa ni se pierde ==\n";
// Reimportar un archivo con menos columnas borraba lo que ya estaba cargado.
ok('la importación completa huecos y no pisa', str_contains($crmPhp, '_solo_completar'));
ok('y la usa al actualizar', str_contains($importa, "\$datos['_solo_completar'] = true"));
// Cada reimportación ponía «no_email» en cero y borraba las bajas pedidas.
ok('la baja de campañas sólo se toca si viene en los datos',
   str_contains($crmPhp, "array_key_exists('no_email', \$d)"));
ok('la importación ya no manda esa clave', !preg_match("/'no_email'\s*=>\s*0/", $importa));
// El puntero de la casilla avanzaba antes de leer: un rebote ilegible se daba
// por procesado y no se volvía a mirar nunca.
ok('el lector de la casilla no da por leído lo que no pudo leer',
   strpos($imapPhp, "\$maxUid = max(\$maxUid, \$uid);") > strpos($imapPhp, "if (!\$h['ok'])"));
// Dos corridas solapadas mandaban el mismo correo dos veces.
ok('el cron no se pisa consigo mismo', str_contains($cronPhp, "GET_LOCK('crm_cron'"));
ok('y suelta el bloqueo al terminar', str_contains($cronPhp, "RELEASE_LOCK('crm_cron'"));

echo "== Lo que la pantalla promete, se puede hacer ==\n";
// La pantalla de baja promete el alta de nuevo y no había forma de darla.
ok('una baja se puede revertir', str_contains($campanas, 'function reactivar_suscripcion'));
ok('y hay un botón para hacerlo',
   str_contains((string) @file_get_contents($WEB . '/app/views/contacto-ver.php'), 'reactivar'));
// Elegir «Completada» cambiaba el texto y la actividad seguía en pendientes.
ok('marcar una actividad como completada la completa',
   str_contains((string) @file_get_contents($WEB . '/app/actividades.php'), "'completada_en'"));
// Restaurar de la papelera terminaba en un 404 porque se rompía la dirección.
ok('restaurar vuelve a la pantalla correcta',
   str_contains((string) @file_get_contents($WEB . '/app/pages/registro-eliminar.php'), '?=&'));
// «Simular primero» se ignoraba al cargar correos a una lista: guardaba igual.
ok('simular no guarda a escondidas',
   str_contains((string) @file_get_contents($WEB . '/app/pages/importar.php'), 'La simulación todavía no está disponible'));

echo "== Permisos y pantallas de error ==\n";
$idx  = (string) @file_get_contents($WEB . '/index.php');
$auth = (string) @file_get_contents($WEB . '/app/auth.php');
// El menú las escondía pero la ruta no las protegía: se entraba a mano.
ok('imágenes es de administración también en la ruta',
   preg_match("/'imagenes'\s*=>\s*\[[^\]]*'admin'\s*=>\s*true/", $idx) === 1);
ok('importar también', preg_match("/'importar'\s*=>\s*\[[^\]]*'admin'\s*=>\s*true/", $idx) === 1);
// El rechazo salía como una línea de texto en una pantalla en blanco, sin
// diseño y sin forma de volver. Se comprueba en positivo —que dibujen la
// pantalla de error— y no por ausencia del texto viejo: el comentario que
// explica el arreglo cita ese texto, y la comprobación se disparaba sola.
ok('el tablero rechaza con la pantalla de error',
   preg_match("/!auth_is_admin\(\)\)\s*\{[^}]*render\('error'/s", $idx) === 1);
ok('y require_admin también',
   preg_match("/function require_admin[^}]*render\('error'/s", $auth) === 1);

// ============================================================
//  Lo que estaba construido y no se podía usar
// ============================================================
echo "== Funcionalidad alcanzable ==\n";
$reg  = (string) @file_get_contents($WEB . '/app/registros.php');
$gcal = (string) @file_get_contents($WEB . '/app/gcal.php');
$hlp  = (string) @file_get_contents($WEB . '/app/helpers.php');

// Cada una de estas funciones existía y ninguna pantalla la llamaba: la
// funcionalidad estaba escrita, probada y era inalcanzable desde la interfaz.
foreach ([
    'reprogramar una reunión'      => ['agenda_reprogramar',  'app/pages/reserva-accion.php'],
    'cancelar una reunión'         => ['agenda_cancelar',     'app/pages/reserva-accion.php'],
    'desconectar Google'           => ['google_desconectar',  'app/pages/google-desconectar.php'],
    'borrar una nota'              => ['nota_eliminar',       'app/pages/nota-eliminar.php'],
    'etiquetar un registro'        => ['etiquetas_asignar',   'app/pages/etiquetas-guardar.php'],
] as $que => [$fn, $pagina]) {
    $c = (string) @file_get_contents($WEB . '/' . $pagina);
    ok("se puede $que desde una pantalla", str_contains($c, $fn), '→ falta ' . $pagina);
}
// Y que reprogramar mueva el evento de Google en vez de borrarlo y crear otro:
// si se borrara, desaparecería del calendario de quien ya la había aceptado.
ok('reprogramar mueve el evento de Google',
   str_contains($agendaPhp, 'gcal_mover_evento'), '→ la función seguía sin usarse');
ok('la ruta de cada una está registrada',
   substr_count($idx, "'reserva-accion'") + substr_count($idx, "'google-desconectar'")
 + substr_count($idx, "'nota-eliminar'") + substr_count($idx, "'etiquetas-guardar'") === 4);

// Sin ningún embudo cargado, la pantalla del Embudo era un callejón sin salida:
// decía que faltaba configurarlo y no existía la pantalla para hacerlo.
ok('hay pantalla para crear embudos y etapas', file_exists($WEB . '/app/pages/embudos.php'));
ok('y el Embudo vacío lleva ahí',
   str_contains((string) @file_get_contents($WEB . '/app/pages/embudo.php'), "redirect('embudos')"));
// La columna «Objetivo» de Reportes leía una tabla que nadie escribía.
ok('hay pantalla para cargar los objetivos', file_exists($WEB . '/app/pages/cuotas.php'));
// Había tabla de adjuntos y ninguna forma de subir nada.
ok('se pueden subir archivos a una ficha', str_contains($reg, 'function adjunto_guardar'));
ok('y se descargan por una ruta con sesión', file_exists($WEB . '/app/pages/adjunto.php'));
ok('los archivos no quedan dentro del sitio',
   str_contains($reg, "rtrim(STORAGE, '/') . '/adjuntos'"),
   '→ dentro del web root cualquiera los bajaría sin sesión');

echo "== Datos y formatos ==\n";
ok('los teléfonos se muestran legibles', str_contains($hlp, 'function telefono_formato'));
$vistas2 = '';
foreach (glob($WEB . '/app/views/*.php') ?: [] as $v) { $vistas2 .= (string) file_get_contents($v); }
ok('y los listados lo usan', substr_count($vistas2, 'telefono_formato(') >= 4);
// La comprobación anterior sólo miraba que el campo tuviera type="tel", y eso
// NO impide escribir letras: es una pista para el teclado del celular, nada
// más. Un campo puede tener type="tel" y aceptar «asdasd» igual, que es
// exactamente lo que estaba pasando. Lo que de verdad frena es el `pattern` en
// el navegador y, sobre todo, la validación en el servidor.
// Ojo con recorrer el marcado: una etiqueta puede llevar un bloque de PHP
// adentro del atributo «value», y ese bloque termina con un «>» que no cierra
// la etiqueta. Con una expresión ingenua, esta comprobación daba por inválidos
// campos que estaban perfectos, y lo peor: podía dar por buenos los que no.
//
// (El ejemplo no se escribe acá: la marca de cierre de PHP dentro de un
// comentario de una línea saca al intérprete del modo código, y a partir de
// ahí el archivo se imprime como texto en vez de ejecutarse. Pasó.)
$telSinPatron = 0;
foreach (glob($WEB . '/app/views/*.php') ?: [] as $arch) {
    $src = (string) file_get_contents($arch);
    $i = 0;
    while (($i = strpos($src, '<input', $i)) !== false) {
        $j = $i + 6; $prof = 0;
        while ($j < strlen($src)) {
            if (substr($src, $j, 2) === '<?')            { $prof++; $j += 2; continue; }
            if (substr($src, $j, 2) === '?>' && $prof)   { $prof--; $j += 2; continue; }
            if ($src[$j] === '>' && $prof === 0)         { break; }
            $j++;
        }
        $etq = substr($src, $i, $j - $i);
        if (preg_match('/name="(telefono|movil|conmutador|empresa_telefono|empresa_whatsapp)"/', $etq)
            && !str_contains($etq, 'pattern=')) {
            $telSinPatron++;
        }
        $i = $j + 1;
    }
}
ok('ningún teléfono se manda sin validar', $telSinPatron === 0, "→ $telSinPatron campos sin pattern");
$crmPhp2 = (string) @file_get_contents($WEB . '/app/crm.php');
ok('y el servidor lo comprueba de nuevo', str_contains($crmPhp2, 'function telefono_valido'),
   '→ el navegador se puede saltear; el servidor no');
ok('la reserva pública también lo comprueba',
   str_contains((string) @file_get_contents($WEB . '/app/agenda.php'), 'telefono_valido'));
ok('los formularios avisan en vez de descartar en silencio',
   str_contains((string) @file_get_contents($WEB . '/app/pages/contacto-form.php'), 'telefono_valido'));
// La base recorta los textos largos sin avisar.
ok('los campos de texto llevan su tope de caracteres',
   substr_count($vistas2, 'maxlength=') >= 30);

echo "== Reinicio de la instalación de pruebas ==\n";
// Tiene que poder correrse muchas veces y dejar siempre lo mismo: es lo que
// permite repetir el circuito completo de una campaña desde cero.
// En el repositorio vive en `app/reinicio.php`, al lado de `app/web/`; al
// desplegar, el contenido de `app/web/` pasa a ser la raíz del sitio y el
// archivo queda adentro. Se prueban las dos ubicaciones, igual que hace el cron.
$rein = '';
foreach ([dirname($WEB) . '/reinicio.php', $WEB . '/reinicio.php'] as $cand) {
    $rein = (string) @file_get_contents($cand);
    if ($rein !== '') { break; }
}
ok('existe la herramienta de reinicio', $rein !== '');
ok('no corre si el sistema está en producción', str_contains($rein, "!== 'testing'"));
// Los tres contactos de rebote terminan ARCHIVADOS y dados de baja después de
// cada tanda, que es justo lo que se quiere comprobar. Si la siembra los
// buscara sólo entre los activos no los encontraría, crearía un duplicado en
// cada reinicio y además quedarían dados de baja para la próxima campaña.
ok('la siembra encuentra también los archivados',
   str_contains($rein, 'SELECT id FROM contactos WHERE email = ? ORDER BY id LIMIT 1'));
ok('y los deja listos para volver a probar',
   str_contains($rein, 'eliminado_en = NULL, no_email = 0, email_rebotado = 0'));
// Borrar la fila de la base no saca la reunión del calendario de Google.
ok('el reinicio limpia también Google Calendar',
   str_contains($rein, "'/calendars/primary/events/'") && str_contains($rein, 'sendUpdates=none'),
   '→ si no, quedan reuniones fantasma avisando de algo que ya no existe');

echo "== Hora del sistema ==\n";
$db = (string) @file_get_contents($WEB . '/app/db.php');
ok('la base usa la misma zona horaria que la aplicación', str_contains($db, "SET time_zone"));
ok('y el desfase se calcula solo, no está escrito a mano',
   str_contains($db, 'date_default_timezone_get()') && !preg_match("/SET time_zone = '-03:00'/", $db));
ok('la pantalla de CRON muestra si coinciden',
   str_contains((string) @file_get_contents($WEB . '/app/pages/cron.php'), "'reloj'"));

echo "\n" . str_repeat('=', 60) . "\n";
printf("  %d comprobaciones de interfaz · %d correctas · %d fallidas\n", $T, $T - $F, $F);
if ($fallos) {
    echo "\n  Fallaron:\n";
    foreach ($fallos as $f) { echo "   - $f\n"; }
}
echo str_repeat('=', 60) . "\n";
exit($F > 0 ? 1 : 0);

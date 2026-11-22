<?php
declare(strict_types=1);

// ============================================================
//  Test del refresco automático — CRM INTELLIHELP
//
//  Se prueba por HTTP, con una sesión real:
//
//   1. que el refresco traiga sólo el cuerpo (si trajera el layout, cada
//      vuelta metería un menú entero adentro de la tabla);
//   2. que cuando no cambió nada la respuesta vuelva vacía, sin repintar la
//      pantalla ni perder la posición de scroll;
//   3. que cuando cambia un dato, el refresco lo traiga;
//   4. que el refresco NO cuente como actividad. Esto es lo importante: el
//      navegador pide solo cada pocos segundos, con la pantalla abierta y
//      nadie delante. Si contara, una pantalla olvidada mantendría la sesión
//      viva para siempre y el cierre automático no cerraría nunca nada.
//
//  Uso:  php test/vivo_test.php [--produccion]
//  Necesita la base y salir a la web (usa la configuración desplegada).
//
//  Contra qué instalación: carga el bootstrap de CRM_WEB y, si no está
//  definida, el de PRUEBAS (public_html/crm-pruebas); los pedidos HTTP van a
//  la URL canónica de esa configuración. Producción sólo con el argumento
//  --produccion: sin él, un webroot de producción (carpeta «crm») se rechaza
//  con código 9 antes de abrir la base. Se corre en el servidor, porque lee y
//  reescribe a mano el archivo de sesión de PHP (tiene que ser el mismo
//  directorio de sesiones que usa el sitio). No lo lanza `deploy.py test`.
//
//  ESCRIBE EN LA BASE de esa instalación:
//    · crea el usuario administrador vivo.test@intellihelp.tech y una sesión
//      real para él (archivo sess_<id>);
//    · le cambia el nombre al primer contacto vivo por «Zzz<número>» y
//      después se lo devuelve (con su actualizado_en original).
//  La limpieza (devolver el nombre, borrar el usuario, su actividad y el
//  archivo de sesión) corre en un finally y, por si el proceso muere con un
//  error fatal, también en un handler de shutdown; se ejecuta una sola vez.
//
//  Necesita la extensión curl. Salida: 0 todo bien, 1 alguna falla.
// ============================================================

const VIVO_USUARIO = 'vivo.test@intellihelp.tech';

// --produccion habilita el webroot real; sin él, el de pruebas.
$produccion = in_array('--produccion', array_slice($argv, 1), true);
$RAIZ = rtrim(getenv('CRM_WEB') ?: ($produccion
    ? '/home/u566451014/domains/intellihelp.tech/public_html/crm'
    : '/home/u566451014/domains/intellihelp.tech/public_html/crm-pruebas'), '/');
// Freno de producción: se mira el nombre de la carpeta, también resuelto.
if (!$produccion && (basename($RAIZ) === 'crm' || basename(realpath($RAIZ) ?: $RAIZ) === 'crm')) {
    fwrite(STDERR, "$RAIZ es PRODUCCIÓN: este test escribe en la base. Para correrlo igual, agregar --produccion.\n");
    exit(9);
}
require $RAIZ . '/app/bootstrap.php';

// URL base para los pedidos: la canónica de la instalación cargada.
$BASE = rtrim((string)($GLOBALS['CONFIG']['app']['canonical'] ?? ''), '/');
if ($BASE === '') { fwrite(STDERR, "Falta la URL canónica en la configuración.\n"); exit(1); }

// Contadores globales de comprobaciones correctas y fallidas.
$ok = 0; $mal = 0;
/**
 * Registra una comprobación e imprime [OK] o [XX]; si falla y hay $detalle,
 * lo imprime debajo.
 */
function ok(string $q, bool $bien, string $detalle = ''): void
{
    global $ok, $mal;
    if ($bien) { $ok++; echo "  [OK] $q\n"; }
    else       { $mal++; echo "  [XX] $q" . ($detalle ? "\n       → $detalle" : '') . "\n"; }
}

// Lo que hay que deshacer al terminar. $contactoOriginal se llena justo
// antes de renombrar el contacto; $archivoSesion, al abrir la sesión.
$contactoOriginal = null;   // ['id' => int, 'nombre' => string, 'actualizado_en' => string]
$archivoSesion    = '';

/**
 * Deja la base como estaba: devuelve el nombre del contacto, borra el
 * usuario de prueba con su actividad y el archivo de sesión. Idempotente:
 * la llaman el finally y el handler de shutdown, y corre una sola vez. Cada
 * paso va por separado para que una falla no impida los siguientes.
 */
function vivo_limpiar(): void
{
    global $contactoOriginal, $archivoSesion;
    static $hecho = false;
    if ($hecho) { return; }
    $hecho = true;
    $pasos = [
        'devolver el nombre del contacto' => static function () use (&$contactoOriginal): void {
            if ($contactoOriginal === null) { return; }
            // Se fija actualizado_en a mano para que el ON UPDATE no deje la
            // marca de hoy en un contacto real que nadie tocó.
            q('UPDATE contactos SET nombre = ?, actualizado_en = ? WHERE id = ?',
              [$contactoOriginal['nombre'], $contactoOriginal['actualizado_en'], $contactoOriginal['id']]);
            $contactoOriginal = null;
        },
        'borrar la actividad del usuario de prueba' => static function (): void {
            q('DELETE FROM actividad WHERE usuario_id IN (SELECT id FROM usuarios WHERE email = ?)', [VIVO_USUARIO]);
        },
        'borrar el usuario de prueba' => static function (): void {
            q('DELETE FROM usuarios WHERE email = ?', [VIVO_USUARIO]);
        },
        'borrar el archivo de sesión' => static function () use ($archivoSesion): void {
            if ($archivoSesion !== '') { @unlink($archivoSesion); }
        },
    ];
    foreach ($pasos as $que => $paso) {
        try {
            $paso();
        } catch (\Throwable $e) {
            fwrite(STDERR, "LIMPIEZA: no se pudo $que: " . $e->getMessage() . "\n");
        }
    }
}
register_shutdown_function('vivo_limpiar');

try {

// ------------------------------------------------------------
//  Una sesión de verdad, escrita como la escribiría el sistema.
// ------------------------------------------------------------
$u = q1('SELECT id FROM usuarios WHERE email = ?', [VIVO_USUARIO]);
if (!$u) {
    q("INSERT INTO usuarios (nombre, email, rol, activo) VALUES ('Vivo Test', ?, 'admin', 1)", [VIVO_USUARIO]);
    $uid = last_id();
} else {
    $uid = (int)$u['id'];
}

// Se abre una sesión de PHP con el mismo nombre de cookie que el sitio
// (ith_crm), se escribe como la escribiría el login y se cierra para que el
// archivo quede en disco. Los pedidos HTTP mandan esa cookie.
session_name('ith_crm');
session_start();
$sid  = session_id();
$_SESSION = ['uid' => $uid, 'login_en' => time(), 'ultima_actividad' => time(),
             '_csrf' => str_repeat('b', 64)];
session_write_close();

// Ruta del archivo de sesión: se lee y se reescribe a mano más abajo para
// atrasar la marca de actividad sin pasar por PHP.
$archivoSesion = rtrim(session_save_path() ?: sys_get_temp_dir(), '/') . '/sess_' . $sid;

/** Lee `ultima_actividad` del archivo de sesión, sin pasar por PHP. */
function marca_sesion(string $archivo): int
{
    $s = (string) @file_get_contents($archivo);
    return preg_match('/ultima_actividad\|i:(\d+);/', $s, $m) ? (int)$m[1] : 0;
}

/** Pide una URL con la sesión puesta. Devuelve [codigo, cabeceras, cuerpo]. */
function pedir(string $url, array $cab = []): array
{
    global $sid;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIE         => 'ith_crm=' . $sid,
        CURLOPT_HTTPHEADER     => array_merge(['X-Requested-With: XMLHttpRequest'], $cab),
    ]);
    $r    = (string) curl_exec($ch);
    $cod  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $corte = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$cod, substr($r, 0, $corte), substr($r, $corte)];
}

// ---- 1. El refresco (?__vivo=1) trae sólo el cuerpo, con su huella ----
echo "== El refresco trae el cuerpo, no la pantalla entera ==\n";

$url  = $BASE . '/contactos';
[$c1, $h1, $b1] = pedir($url);
ok('la pantalla normal responde y trae el layout',
   $c1 === 200 && stripos($b1, '</html>') !== false, "código $c1");

[$c2, $h2, $b2] = pedir($url . '?__vivo=1');
ok('el refresco responde', $c2 === 200, "código $c2");
// Se busca la hoja de estilos y el menú, que los escribe sólo el layout: hay
// pantallas cuyo contenido incluye un documento HTML entero (la vista previa
// del correo), así que buscar «</html>» daría un falso positivo.
ok('y NO trae el layout: si lo trajera, metería un menú adentro de la tabla',
   !str_contains($b2, 'assets/css/app.css') && stripos($b2, '<nav') === false);
ok('trae las zonas marcadas, que es lo único que se actualiza',
   str_contains($b2, 'data-vivo="tabla"') && str_contains($b2, 'data-vivo="cabecera"'));
ok('el refresco pesa bastante menos que la pantalla entera',
   strlen($b2) > 200 && strlen($b2) < strlen($b1),
   strlen($b2) . ' bytes contra ' . strlen($b1));

// La huella viaja DENTRO del HTML, no en una cabecera: la CDN del hosting se
// come las cabeceras condicionales (comprobado: `If-None-Match` no llega a
// PHP), así que por ahí el servidor nunca se enteraba de que el navegador ya
// tenía esa versión y le mandaba la pantalla entera cada vez.
preg_match('/^<!--vivo:([0-9a-f]{32})-->/', $b2, $m);
$huella = $m[1] ?? '';
ok('el cuerpo trae la huella adentro, que es lo que evita mandar de más',
   $huella !== '', 'no se encontró la marca al principio del HTML');

// Los parámetros del refresco no son de la pantalla: si se colaran a los
// enlaces, hacer clic en «página 2» devolvería la pantalla sin diseño.
ok('los enlaces de la pantalla no arrastran los parámetros del refresco',
   !str_contains($b2, '__vivo') && !str_contains(substr($b2, 60), '__h='),
   'los enlaces quedarían pidiendo el refresco en vez de la pantalla');

// ---- 2. Con la huella actual (&__h=) y sin cambios: 204 vacío ----
echo "\n== Si no cambió nada, no viaja nada ==\n";

[$c3, $h3, $b3] = pedir($url . '?__vivo=1&__h=' . $huella);
ok('la segunda consulta seguida contesta «sin cambios»', $c3 === 204, "código $c3");
ok('y viene vacía: la pantalla no se toca, no parpadea ni te mueve del lugar',
   trim($b3) === '', strlen($b3) . ' bytes');

// ---- 3. Un dato cambiado por la base aparece en el refresco ----
echo "\n== Cuando cambia un dato, el refresco lo trae ==\n";

// Se cambia el nombre de un contacto por la base, como si lo hubiera editado
// otra persona desde otra computadora, que es el caso real.
$ct = q1('SELECT id, nombre, actualizado_en FROM contactos WHERE eliminado_en IS NULL ORDER BY id LIMIT 1');
if (!$ct) {
    ok('hay un contacto para probar', false, 'no hay contactos en la base');
} else {
    // Se guarda lo original ANTES de tocar: desde acá, si algo corta la
    // batería, la limpieza lo devuelve.
    $contactoOriginal = ['id' => (int)$ct['id'], 'nombre' => (string)$ct['nombre'],
                         'actualizado_en' => (string)$ct['actualizado_en']];
    $marca    = 'Zzz' . substr((string)time(), -5);
    q('UPDATE contactos SET nombre = ? WHERE id = ?', [$marca, (int)$ct['id']]);

    // Se pide con la huella VIEJA, que es exactamente lo que hace el navegador.
    // Acá se juega algo delicado: si la CDN guardara la respuesta «sin cambios»
    // asociada a esa dirección, seguiría contestando «sin cambios» para
    // siempre y la pantalla quedaría congelada sin que nada lo indique.
    [$c4, $h4, $b4] = pedir($url . '?__vivo=1&__h=' . $huella);
    ok('con la huella vieja y el dato cambiado, contesta con contenido nuevo',
       $c4 === 200, "código $c4: si diera 204, la CDN estaría guardando la respuesta y la pantalla no se actualizaría nunca");
    ok('y adentro viene el dato cambiado, sin que nadie recargue la página',
       str_contains($b4, $marca));

    preg_match('/^<!--vivo:([0-9a-f]{32})-->/', $b4, $m2);
    ok('con una huella distinta a la anterior', ($m2[1] ?? '') !== $huella);

    // Se devuelve en seguida (no al final), con su actualizado_en original.
    q('UPDATE contactos SET nombre = ?, actualizado_en = ? WHERE id = ?',
      [$contactoOriginal['nombre'], $contactoOriginal['actualizado_en'], $contactoOriginal['id']]);
    $contactoOriginal = null;
}

// ---- 4. El refresco no cuenta como actividad (cierre por inactividad) ----
echo "\n== El refresco no mantiene viva una sesión olvidada ==\n";

// Se atrasa la marca a mano y se comprueba quién la mueve y quién no.
$viejo = time() - 300;
$s = (string) file_get_contents($archivoSesion);
file_put_contents($archivoSesion, preg_replace('/ultima_actividad\|i:\d+;/', 'ultima_actividad|i:' . $viejo . ';', $s));
ok('la marca de actividad quedó atrasada para la prueba', marca_sesion($archivoSesion) === $viejo);

pedir($url . '?__vivo=1');
$trasVivo = marca_sesion($archivoSesion);
ok('DESPUÉS DEL REFRESCO la marca sigue igual: la sesión se va a cerrar igual',
   $trasVivo === $viejo,
   'la marca pasó de ' . $viejo . ' a ' . $trasVivo . ': una pantalla abierta y olvidada no se cerraría nunca');

pedir($url);
$trasNormal = marca_sesion($archivoSesion);
ok('y cuando entrás de verdad a la pantalla, sí se actualiza',
   $trasNormal > $viejo, 'la marca quedó en ' . $trasNormal);

// ---- 5. El refresco no deja una línea «ver.contactos» en la bitácora ----
// Se cuentan sólo las filas del usuario de prueba: si alguien entra a
// Contactos en ese momento, no altera la cuenta.
echo "\n== La bitácora no se llena de ruido ==\n";

$cuentaVer = static fn(): int => (int) qv(
    "SELECT COUNT(*) FROM actividad WHERE accion = 'ver.contactos' AND usuario_id = ?", [$uid]);
$antes = $cuentaVer();
pedir($url . '?__vivo=1');
pedir($url . '?__vivo=1');
$despues = $cuentaVer();
ok('dos refrescos no dejan rastro: si no, taparían los movimientos reales',
   $despues === $antes, "la bitácora pasó de $antes a $despues");

} catch (\Throwable $e) {
    // Una excepción a mitad de camino cuenta como falla y se ve en el resumen.
    ok('la batería corrió hasta el final', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    // Se deshace todo aunque una comprobación haya tirado una excepción.
    vivo_limpiar();
}

// ---- Resumen y código de salida (0 = todo bien, 1 = alguna falla) ----
$total = $ok + $mal;
echo "\n  $total comprobaciones · $ok correctas · $mal fallidas\n";
exit($mal > 0 ? 1 : 0);

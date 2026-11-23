<?php
declare(strict_types=1);

// ============================================================
//  Reinicio de la instalación de pruebas.
//
//  Mientras el sistema está en modo prueba conviene poder dejarlo en cero
//  después de cada prueba: el registro del cron, las campañas de ensayo, los
//  envíos, las reuniones reservadas. Lo que NO se toca es lo que hace falta
//  para volver a probar. Lo que cargó una persona usando el sistema —contactos,
//  empresas, negocios, reuniones, plantillas— NO sobrevive: después de reiniciar
//  sólo quedan los datos de prueba que siembra este mismo script.
//
//  Uso:
//      php reinicio.php                     muestra qué borraría, sin borrar
//      php reinicio.php --hacerlo           borra (pide escribir el nombre de la base)
//      php reinicio.php --hacerlo --confirmar=<base>
//                                           borra sin preguntar (para scripts)
//      php reinicio.php --sembrar           sólo deja cargados los datos de prueba
//      php reinicio.php --hacerlo --conservar-base
//                                           borra el ruido pero NO toca la base de
//                                           contactos, empresas y posibles
//
//  PROTECCIONES (todas tienen que cumplirse, si no no hace nada, ni siquiera
//  el simulacro):
//    1. Tiene que ser la instalación de pruebas: o corre en la carpeta
//       crm-pruebas con su propia configuración (crm_config/crm-pruebas.php,
//       no la config.php de producción, que es a la que cae bootstrap.php si
//       falta la propia), o la base se llama *_pruebas.
//    2. El ajuste `app_entorno` de esa base tiene que decir 'testing'. Si la
//       fila falta, se toma como producción.
//    3. `--hacerlo` pide escribir el nombre de la base (o recibirlo en
//       `--confirmar=`). Sin argumentos es un simulacro que sólo cuenta filas.
//    · Por HTTP devuelve 404 (sólo línea de comandos).
//
//  Orden de lo que hace con --hacerlo: anota los eventos de Google y los
//  archivos adjuntos que hay, vacía las tablas de la lista (DELETE, no
//  TRUNCATE) dentro de una transacción —si algo falla, no se borra nada—,
//  recién entonces borra de Google Calendar los eventos y del disco los
//  adjuntos, limpia las marcas del cron y siembra los datos de prueba
//  (reinicio_sembrar()). Una tabla de la lista que no existe en la base (una
//  migración sin aplicar) se saltea con un aviso.
//
//  Los datos personales de los contactos de prueba (el correo externo y los
//  teléfonos) no están en el código: salen de la sección 'pruebas' de la
//  configuración de la instalación o de los ajustes `pruebas_<clave>` (ver
//  reinicio_dato() y config.example.php).
//
//  Depende de las funciones del núcleo: gcal_borrar_evento, cuenta_asegurar,
//  cuenta_guardar, contacto_guardar, agenda_tipo_guardar, plantilla_guardar,
//  plantillas_preajustes, datos_prueba_anotar, adjuntos_dir.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Esto se ejecuta por línea de comandos.\n");
}

// ------------------------------------------------------------
//  Funciones (sin efectos al incluirlas)
//
//  test/casos/e2.php define REINICIO_SOLO_FUNCIONES antes de incluir este
//  archivo: las funciones se declaran igual (PHP las registra al compilar) y
//  el script se corta antes de conectar a ninguna base.
// ------------------------------------------------------------

/**
 * ¿Se puede reiniciar esta instalación? Devuelve null si sí, o el motivo
 * por el que no.
 *
 * @param string      $carpeta       carpeta de la instalación (crm, crm-pruebas…)
 * @param string      $archivoConfig la configuración que cargó bootstrap.php
 * @param string      $base          nombre de la base conectada
 * @param string|null $entorno       ajuste `app_entorno`, null si la fila no está
 */
function reinicio_permitido(string $carpeta, string $archivoConfig, string $base, ?string $entorno): ?string
{
    // En la carpeta de pruebas, sólo con su propia config: si bootstrap.php
    // cayó a config.php es la de producción, y esa base no se vacía.
    $carpetaPruebas = $carpeta === 'crm-pruebas' && basename($archivoConfig) !== 'config.php';
    $basePruebas    = str_ends_with($base, '_pruebas');
    if (!$carpetaPruebas && !$basePruebas) {
        return "Esta no es la instalación de pruebas (carpeta «{$carpeta}», base «{$base}», configuración «"
             . basename($archivoConfig) . "»). Sólo se reinicia la carpeta crm-pruebas con su propia "
             . "configuración, o una base que se llame *_pruebas.";
    }
    if ($entorno === null) {
        return 'La base no tiene el ajuste app_entorno: se trata como PRODUCCIÓN y no se borra nada.';
    }
    if ($entorno !== 'testing') {
        return "El sistema está en PRODUCCIÓN. No se borra nada.\n"
             . 'Si de verdad hace falta, primero se pasa a modo prueba desde Ajustes.';
    }
    return null;
}

/**
 * ¿Se confirmó el borrado? Con `--confirmar=<base>` o escribiendo el nombre
 * de la base cuando se pregunta. Cualquier otra cosa (o nada) es «no».
 *
 * @param array<int,string> $args argumentos de la línea de comandos
 * @param string            $base nombre de la base que se va a vaciar
 * @param callable|null     $leer devuelve la línea que escribió la persona,
 *                                o null si no hay a quién preguntar
 */
function reinicio_confirmado(array $args, string $base, ?callable $leer): bool
{
    if ($base === '') { return false; }
    foreach ($args as $a) {
        if (str_starts_with($a, '--confirmar=')) {
            return substr($a, strlen('--confirmar=')) === $base;
        }
    }
    if ($leer === null) { return false; }
    $linea = $leer();
    return is_string($linea) && trim($linea) === $base;
}

/**
 * Un dato de los contactos de prueba que no va escrito en el código: la
 * sección 'pruebas' de la configuración manda; si no está, el ajuste
 * `pruebas_<clave>`; si tampoco, $def.
 *
 * Claves: correo_externo (una casilla de otro proveedor, para contestar y ver
 * que el CRM detecta la respuesta), correo_interno, telefono_externo,
 * telefono_interno, empresa_telefono, empresa_whatsapp, responsable (correo
 * del usuario dueño de los datos de prueba).
 */
function reinicio_dato(string $clave, string $def = ''): string
{
    $cfg = $GLOBALS['CONFIG']['pruebas'][$clave] ?? null;
    if (is_string($cfg) && trim($cfg) !== '') { return trim($cfg); }
    $aj = function_exists('setting_get') ? (string) setting_get('pruebas_' . $clave, '') : '';
    return trim($aj) !== '' ? trim($aj) : $def;
}

/**
 * Ruta en disco de un adjunto, o null si la `ruta` guardada no es una de las
 * que arma adjunto_guardar() (AAAA/MM/<hex>.<ext>): así nunca se borra nada
 * fuera de la carpeta de adjuntos.
 */
function reinicio_ruta_adjunto(string $dir, string $ruta): ?string
{
    if (!preg_match('#^\d{4}/\d{2}/[0-9a-f]{16,64}\.[a-z0-9]{1,8}$#', $ruta)) { return null; }
    return rtrim($dir, '/\\') . '/' . $ruta;
}

if (defined('REINICIO_SOLO_FUNCIONES')) { return; }

// En el repositorio el núcleo está en app/web/app/; en el servidor, este
// archivo queda en la raíz de la instalación, junto a app/.
$raiz = is_file(__DIR__ . '/web/app/bootstrap.php') ? __DIR__ . '/web' : __DIR__;
require $raiz . '/app/bootstrap.php';

// --hacerlo borra y además siembra; --sembrar sólo siembra (no borra nada,
// pero igual imprime el simulacro de lo que se borraría).
$args     = array_slice($argv, 1);
$hacerlo  = in_array('--hacerlo', $args, true);
$sembrar  = in_array('--sembrar', $args, true);
// La base de contactos se borra por defecto. Se conserva sólo si se pide,
// para cuando adentro hay una importación real.
$conservarBase = in_array('--conservar-base', $args, true)
              || in_array('--conservar-contactos', $args, true);

// Las barreras (ver el encabezado). Van antes de todo, así que también
// frenan el simulacro y --sembrar fuera de pruebas. `$configFile` es la
// configuración que eligió bootstrap.php.
$baseActual = (string) qv('SELECT DATABASE()');
$motivo = reinicio_permitido(basename(WEBROOT), (string) ($configFile ?? ''), $baseActual,
                             setting_get('app_entorno', null));
if ($motivo !== null) {
    fwrite(STDERR, $motivo . "\n");
    exit(1);
}

if ($hacerlo) {
    $interactivo = function_exists('stream_isatty') && @stream_isatty(STDIN);
    if (!array_filter($args, fn($a) => str_starts_with($a, '--confirmar=')) && $interactivo) {
        echo "Esto vacía la base «{$baseActual}». Para seguir, escribí su nombre: ";
    }
    $leer = $interactivo ? static fn() => fgets(STDIN) : null;
    if (!reinicio_confirmado($args, $baseActual, $leer)) {
        fwrite(STDERR, "No se confirmó: no se borró nada. Escribí el nombre exacto de la base o pasá "
                     . "--confirmar=$baseActual.\n");
        exit(1);
    }
}

/**
 * Lo que se borra: el ruido que dejan las pruebas.
 *
 * El orden importa por las claves foráneas: primero lo que depende de otra
 * cosa, después la cosa.
 */
$tablas = [
    // Lo que entró desde la web: datos de personas (aunque sean inventados)
    // y los contadores de límites. Van primero porque apuntan a contactos,
    // empresas, reuniones y reservas.
    'entradas_web'       => 'consultas y reservas que llegaron desde la web',
    'api_limites'        => 'contadores de límites de envíos de la API y de la agenda pública',
    'envio_eventos'      => 'aperturas y clics de los envíos',
    'envios'             => 'cola y resultado de los envíos',
    'campanas'           => 'campañas',
    'lista_miembros'     => 'destinatarios de las listas',
    'listas'             => 'listas de destinatarios',
    'actividad_invitados'=> 'invitados de las reuniones',
    'agenda_reservas'    => 'reuniones reservadas desde la agenda pública',
    'actividades'        => 'tareas, llamadas y reuniones',
    'notas'              => 'notas',
    'adjuntos'           => 'archivos adjuntos (también se borran los archivos del disco)',
    'desuscripciones'    => 'bajas de suscripción',
    'bajas_tokens'       => 'tokens de baja de campañas ya purgadas',
    'import_lotes'       => 'lotes de importación',
    'notificacion_lecturas' => 'quién leyó cada alerta del equipo',
    'notificaciones'     => 'alertas en pantalla y por correo',
    'mensajes'           => 'conversaciones: lo que respondieron y lo que se contestó',
    'imap_estado'        => 'marca de por dónde iba la lectura de cada casilla',
    'actividad'          => 'registro de auditoría y del cron',
];

// La base de trabajo se borra TAMBIÉN, y por defecto.
//
// Si se conservara, después de reiniciar seguirían apareciendo los contactos y
// las empresas cargados probando la agenda pública, mezclados con los de
// prueba, y también las siembras viejas que ya no están en el script: la lista
// de prueba crecería con gente que no tiene por qué estar.
//
// Estando en modo prueba, después de reiniciar no puede quedar NADA que haya
// cargado una persona usando el sistema: sólo los datos de prueba que siembra
// este mismo script. Con `--conservar-base` se puede pedir lo contrario, para
// cuando lo que hay adentro es una importación real que no se quiere rehacer.
$tablasBase = [
    'negocio_contactos'  => 'vínculos de negocios con contactos',
    'negocio_etapas'     => 'historial de etapas de los negocios',
    'negocios'           => 'negocios',
    'etiqueta_registro'  => 'etiquetas aplicadas',
    'etiquetas'          => 'las etiquetas creadas',
    'posibles_clientes'  => 'posibles clientes',
    'contactos'          => 'contactos',
    'cuentas'            => 'empresas',

    // Configuración que también carga el usuario y que se vuelve a sembrar
    // acá abajo: sin esto quedaban los tipos de reunión de prueba, los embudos
    // con etapas a medio armar y las plantillas que alguien hizo probando.
    'agenda_horarios'    => 'horarios de los tipos de reunión',
    'agenda_tipos'       => 'tipos de reunión (se vuelve a sembrar uno)',
    'etapas'             => 'etapas del embudo (se vuelve a sembrar)',
    'embudos'            => 'embudos (se vuelve a sembrar)',
    'plantillas'         => 'plantillas de campaña (se vuelven a sembrar todas)',
    'imagenes'           => 'imágenes subidas a la biblioteca (los archivos quedan: los usan correos ya enviados)',
    'api_tokens'         => 'claves de la API (si tenías una integración andando, hay que generar otra)',
    'cuotas'             => 'objetivos de venta cargados',
    // La conexión con Google también la hizo una persona. Se puede borrar sin
    // miedo: sin la fila, el próximo ingreso vuelve a pedir el consentimiento y
    // Google manda el token de nuevo, así que se reconecta sola al entrar.
    // Se vacía la última, después de borrar los eventos de Google, que
    // necesitan el token (ver más abajo).
    'google_tokens'      => 'la conexión con Google (se rehace sola al volver a entrar)',
];
// El ruido va PRIMERO y la base después: los envíos, los miembros de las listas
// y las actividades apuntan a contactos y empresas. Varias de esas referencias
// no tienen clave foránea declarada, así que borrar al revés no da error.
if (!$conservarBase) { $tablas = $tablas + $tablasBase; }

// Lo único que sobrevive es lo que NO se puede volver a generar solo.
$conserva = [
    'usuarios'       => 'los usuarios y sus permisos — sin esto nadie podría entrar',
    'cuentas_correo' => 'las casillas de correo. Las contraseñas son «de aplicación» de Google '
                      . 'y NO se pueden leer de ningún lado: borrarlas obligaría a generarlas a '
                      . 'mano en la cuenta de Google después de cada reinicio',
    'app_settings'   => 'los ajustes. Se vuelven a dejar con los datos de la empresa, pero la '
                      . 'tabla no se vacía: ahí vive la clave del cron, que está escrita en el '
                      . 'comando programado en hPanel y no se puede cambiar desde acá',
    'categorias'     => 'los segmentos: vienen de una migración y no hay pantalla para volver a '
                      . 'crearlos, así que borrarlos los perdería para siempre',
    'feriados'       => 'los feriados: los nacionales se vuelven a traer solos, pero los cargados '
                      . 'a mano se perderían',
];

echo $hacerlo ? "== Reinicio de la instalación de pruebas ({$baseActual}) ==\n"
              : "== Simulacro: NO se borra nada ({$baseActual}) ==\n";

// Qué tablas existen de verdad: una migración sin aplicar deja una tabla de
// la lista sin crear, y antes eso cortaba el script a mitad de camino.
$todas = q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$faltan = array_values(array_diff(array_keys($tablas), $todas));

// ------------------------------------------------------------
//  Google Calendar (se anota ahora, se borra después)
//
//  Las reuniones de prueba también están en el calendario de Google, y borrar
//  la fila de la base no las saca de ahí: quedaban avisando de reuniones que
//  ya no existen. Se juntan las de las reservas de la agenda y las de las
//  reuniones del CRM (`actividades.gcal_event_id`; una reserva y su reunión
//  comparten el evento, por eso se deduplica). Se borran en el calendario
//  'primary' del vendedor, que es donde los crea agenda.php.
// ------------------------------------------------------------
$conEvento = [];
$fuentes = [
    'agenda_reservas' => "SELECT usuario_id, gcal_event_id, invitado_nombre AS quien, inicia_el
                          FROM agenda_reservas WHERE gcal_event_id IS NOT NULL AND gcal_event_id <> ''",
    'actividades'     => "SELECT propietario_id AS usuario_id, gcal_event_id, asunto AS quien, inicia_el
                          FROM actividades
                          WHERE gcal_event_id IS NOT NULL AND gcal_event_id <> '' AND propietario_id IS NOT NULL",
];
foreach ($fuentes as $t => $sql) {
    if (!isset($tablas[$t]) || !in_array($t, $todas, true)) { continue; }
    foreach (q($sql)->fetchAll() as $r) {
        $conEvento[(int) $r['usuario_id'] . '|' . $r['gcal_event_id']] ??= $r;
    }
}
if ($conEvento) {
    echo "\nEn Google Calendar" . ($hacerlo ? " (se borran después de vaciar la base):\n" : ":\n");
    foreach ($conEvento as $r) {
        printf("  %s  %-28s %s\n", substr((string)$r['inicia_el'], 0, 16),
               recortar((string)$r['quien'], 27), $hacerlo ? '' : '(se borraría)');
    }
} elseif (!$hacerlo) {
    echo "\nEn Google Calendar: no hay ninguna reunión de prueba.\n";
}

// Los archivos de los adjuntos: la fila se borra en la transacción y el
// archivo, recién cuando la transacción se confirmó.
$archivosAdjuntos = [];
if (isset($tablas['adjuntos']) && in_array('adjuntos', $todas, true)) {
    $archivosAdjuntos = q('SELECT ruta FROM adjuntos')->fetchAll(PDO::FETCH_COLUMN);
}

// ------------------------------------------------------------
//  Las tablas
//
//  El nombre de la tabla va interpolado en el SQL, pero sale de las listas
//  escritas arriba, nunca de un argumento. DELETE fila por fila (y no
//  TRUNCATE) porque TRUNCATE falla en tablas referenciadas por claves
//  foráneas y no se puede deshacer; el costo es que no reinicia los
//  AUTO_INCREMENT. Todo va en una transacción: si un DELETE falla, se
//  deshace todo y no se toca Google ni el disco.
// ------------------------------------------------------------
echo "\nSe borra:\n";
$total = 0;
if ($hacerlo) { db()->beginTransaction(); }
try {
    foreach ($tablas as $t => $desc) {
        if (in_array($t, $faltan, true)) {
            printf("  %-22s %7s   %s\n", $t, '—', 'no existe en esta base (¿falta una migración?): se saltea');
            continue;
        }
        $n = (int) qv("SELECT COUNT(*) FROM `$t`");
        $total += $n;
        printf("  %-22s %7d   %s\n", $t, $n, $desc);
        // `google_tokens` se vacía al final, fuera de la transacción: para
        // borrar los eventos de Google hace falta el token.
        if ($hacerlo && $n > 0 && $t !== 'google_tokens') { q("DELETE FROM `$t`"); }
    }
    if ($hacerlo) { db()->commit(); }
} catch (\Throwable $e) {
    if ($hacerlo && db()->inTransaction()) { db()->rollBack(); }
    fwrite(STDERR, "\nFalló el vaciado: " . $e->getMessage() . "\nNo se borró nada de la base.\n");
    exit(1);
}

// Recién ahora, con la base ya vaciada, los eventos de Google. Un evento que
// no se pudo borrar se avisa para sacarlo a mano. Después, la conexión con
// Google, que hacía falta para esto.
if ($hacerlo && $conEvento) {
    foreach ($conEvento as $r) {
        try {
            if (!gcal_borrar_evento((int)$r['usuario_id'], (string)$r['gcal_event_id'], 'primary', 'none')) {
                echo '      no se pudo borrar de Google «' . recortar((string)$r['quien'], 40)
                   . "»: sacalo a mano del calendario\n";
            }
        } catch (\Throwable $e) {
            echo '      no se pudo borrar de Google «' . recortar((string)$r['quien'], 40) . '»: '
               . $e->getMessage() . "\n";
        }
    }
}
if ($hacerlo && isset($tablas['google_tokens']) && !in_array('google_tokens', $faltan, true)) {
    q('DELETE FROM `google_tokens`');
}

// Y los archivos de los adjuntos.
if ($hacerlo && $archivosAdjuntos && function_exists('adjuntos_dir')) {
    $sinBorrar = 0;
    foreach ($archivosAdjuntos as $ruta) {
        $archivo = reinicio_ruta_adjunto(adjuntos_dir(), (string) $ruta);
        if ($archivo !== null && is_file($archivo) && !@unlink($archivo)) { $sinBorrar++; }
    }
    echo '  ' . (count($archivosAdjuntos) - $sinBorrar) . " archivo(s) de adjuntos borrados del disco"
       . ($sinBorrar ? " ($sinBorrar no se pudieron borrar)" : '') . "\n";
}

if ($conservarBase) {
    echo "\n  (la base de contactos, empresas y posibles NO se toca: lo pediste con --conservar-base)\n";
} else {
    echo "\n  Se borra TODO lo que se haya cargado usando el sistema. Después de esto no queda\n"
       . "  ningún contacto, empresa, negocio ni reunión que no haya sembrado este script.\n";
}

echo "\nSe conserva:\n";
foreach ($conserva as $t => $desc) { printf("  %-22s %s\n", $t, $desc); }

// ------------------------------------------------------------
//  Control: que no se haya olvidado ninguna tabla
// ------------------------------------------------------------
// Una tabla nueva que no se suma acá sobrevive al reinicio con lo que haya
// cargado el usuario. Como esta lista se escribe a mano, el script se controla
// solo contra la base: cualquier tabla que no esté ni en lo que se borra ni en
// lo que se conserva es una que quedó sin decidir.
$decididas = array_merge(array_keys($tablas), array_keys($tablasBase), array_keys($conserva));
$olvidadas = array_diff($todas, $decididas);
if ($olvidadas) {
    echo "\n  ATENCIÓN: estas tablas no están ni en lo que se borra ni en lo que se conserva.\n"
       . "  Alguien las agregó y no decidió qué hacer con ellas, así que lo que tengan adentro\n"
       . "  va a sobrevivir al reinicio sin que nadie lo haya elegido:\n";
    foreach ($olvidadas as $t) {
        printf("      %-22s %d fila(s)\n", $t, (int) qv("SELECT COUNT(*) FROM `$t`"));
    }
}

if ($hacerlo) {
    // La marca del cron también se limpia, para que la próxima corrida arranque
    // como si fuera la primera. `imap_ultima_corrida` en vacío hace que la
    // próxima corrida lea las casillas sin esperar; `gcal_falla` borra el
    // último aviso de error de Google (el viejo, único, y los de cada vendedor,
    // `gcal_falla_u<id>`, con las marcas de permiso rechazado `google_invalido_u<id>`).
    foreach (['cron_ultima_corrida', 'imap_ultima_corrida', 'gcal_falla'] as $k) {
        setting_set($k, '');
    }
    q("UPDATE app_settings SET valor = '' WHERE clave LIKE 'gcal_falla_u%' OR clave LIKE 'google_invalido_u%'");
    setting_refrescar();
    echo "\nBorrados $total registros.\n";
} else {
    echo "\nSerían $total registros. Volvé a correrlo con --hacerlo para borrarlos de verdad.\n";
}

// ------------------------------------------------------------
//  Datos de prueba
// ------------------------------------------------------------
if ($sembrar || $hacerlo) {
    echo "\n== Datos de prueba ==\n";
    $n = reinicio_sembrar();
    foreach ($n as $linea) { echo "  $linea\n"; }
}

/**
 * Deja cargado lo mínimo para poder probar: la empresa, mis dos casillas como
 * contactos, los de rebote y una lista con todos para mandarse una campaña a
 * uno mismo.
 * Es idempotente: correrlo dos veces no duplica nada.
 *
 * Escribe en app_settings (datos de la empresa, `testing_destinatarios`),
 * cuentas, contactos, embudos/etapas y agenda_tipos (sólo si están vacíos),
 * plantillas, listas y lista_miembros, y anota lo sembrado con
 * datos_prueba_anotar() para poder sacarlo, y sólo eso, al pasar a
 * producción. No manda correos ni toca Google.
 *
 * Los datos de personas reales (el correo externo, la casilla interna y los
 * teléfonos) no están en el código: salen de reinicio_dato() (sección
 * 'pruebas' de la configuración o ajustes `pruebas_<clave>`). Sin correo
 * externo configurado, ese contacto no se siembra; sin correo interno, se
 * usa el del usuario dueño.
 *
 * @return array<int,string> una línea legible por cada cosa sembrada, o un
 *                           único aviso si todavía no hay usuarios
 */
function reinicio_sembrar(): array
{
    $out = [];

    // El dueño de los datos de prueba es, en este orden: el usuario activo
    // del correo `responsable` (reinicio_dato(); por defecto la cuenta del
    // responsable de sistemas), el primer administrador activo o, si no hay,
    // el primer usuario. No se puede dar por sentado que sea el usuario 1:
    // los usuarios se crean al entrar con Google, así que el número depende
    // del orden en que entraron.
    $duenio = (int) (qv('SELECT id FROM usuarios WHERE email = ? AND activo = 1',
                        [reinicio_dato('responsable', 'bsierra@intellihelp.tech')])
                  ?: qv("SELECT MIN(id) FROM usuarios WHERE rol = 'admin' AND activo = 1")
                  ?: qv('SELECT MIN(id) FROM usuarios'));
    if (!$duenio) {
        return ['No hay ningún usuario cargado todavía: entrá una vez con Google y volvé a correrlo.'];
    }
    $correoDuenio = (string) qv('SELECT email FROM usuarios WHERE id = ?', [$duenio]);

    // ---- Datos de la empresa ----
    // Son los que usan las plantillas: el bloque de contacto, el botón de
    // WhatsApp y las variables {{remitente_empresa}} y {{empresa_web}}. Se
    // dejan cargados para no tener que escribirlos cada vez que se reinicia.
    // Los teléfonos salen de la configuración (reinicio_dato); si no están,
    // queda lo que ya hubiera en Ajustes.
    $empresa = [
        'empresa_nombre'   => 'INTELLIHELP',
        'empresa_web'      => 'intellihelp.tech',
        'empresa_telefono' => reinicio_dato('empresa_telefono', (string) setting_get('empresa_telefono', '')),
        'empresa_whatsapp' => reinicio_dato('empresa_whatsapp', (string) setting_get('empresa_whatsapp', '')),
        'empresa_email'    => 'info@intellihelp.tech',
    ];
    foreach ($empresa as $k => $v) { setting_set($k, $v); }
    $out[] = 'Datos de la empresa: ' . $empresa['empresa_nombre'] . ' · ' . $empresa['empresa_web']
           . ($empresa['empresa_whatsapp'] !== ''
                ? ' · WhatsApp ' . telefono_formato($empresa['empresa_whatsapp'])
                : ' · sin WhatsApp (falta pruebas.empresa_whatsapp en la configuración)');

    $cuentaId = cuenta_asegurar('INTELLIHELP S.A.', [
        'sitio_web' => 'https://intellihelp.tech',
        'sector'    => 'Tecnología',
        'provincia' => 'Buenos Aires',
        'pais'      => 'Argentina',
    ], $duenio);
    // Se completa con todos los datos obligatorios. `cuenta_asegurar()`
    // sólo sabe crear a partir de un nombre, así que deja la empresa marcada
    // como pendiente de normalizar: los datos de prueba tienen que ser el
    // ejemplo de una ficha bien cargada, no el primer caso a corregir.
    cuenta_guardar([
        'nombre'        => 'INTELLIHELP S.A.',
        'tipo'          => 'Cliente',
        'sector'        => 'Tecnología',
        'cuit'          => '30-71234567-4',
        'condicion_iva' => 'Responsable Inscripto',
        'sitio_web'     => 'intellihelp.tech',
        'email'         => (string) setting_get('empresa_email', 'info@intellihelp.tech'),
        'telefono'      => (string) setting_get('empresa_telefono', ''),
        'calle'         => 'Dorrego 1789, piso 6 of. 604',
        'localidad'     => 'Ciudad de Buenos Aires',
        'provincia'     => 'Ciudad de Buenos Aires',
        'pais'          => 'Argentina',
        'propietario_id'=> $duenio,
        'empleados'     => '',
    ], (int)$cuentaId);
    $out[] = 'Empresa: INTELLIHELP S.A. (id ' . (int)$cuentaId . ', ficha completa)';

    // ---- Contactos de prueba ----
    //
    // La idea es poder mandar UNA campaña y ver el circuito completo: los que
    // llegan bien y los distintos motivos por los que un correo vuelve.
    //
    // Los dos ficticios usan alias con «+» de la casilla real. Google los
    // entrega en la misma bandeja, así que son contactos distintos en el CRM
    // —con su nombre, su empresa y su cargo— pero los correos llegan
    // y se pueden leer. Con direcciones inventadas no llegaría nada y no se
    // podría comprobar nada.
    //
    // Los de rebote apuntan a lugares que NO existen a propósito, cada uno con
    // un motivo distinto, que es lo que permite ver cómo reacciona el sistema:
    // el rebote definitivo archiva el contacto y lo da de baja; el pasajero
    // sólo lo marca y lo reintenta.
    $gente = [];
    // Un correo de otro proveedor, a propósito: llegando afuera se prueba lo
    // que no se puede probar mandándose correo a uno mismo —que salga bien,
    // que no caiga en spam— y, sobre todo, permite contestar desde una
    // dirección distinta a la que el sistema lee, que es la única forma de
    // comprobar que detecta las respuestas. Sale de la configuración: si no
    // está, ese contacto no se siembra.
    $externo = reinicio_dato('correo_externo');
    if ($externo !== '') {
        $gente[] = [
            'nombre' => 'Responsable', 'apellido' => 'Externo', 'email' => $externo,
            'cargo' => 'Responsable de Sistemas', 'telefono' => reinicio_dato('telefono_externo'),
            'nota' => 'Vos. Llega a tu casilla externa: desde ahí podés responder y ver que el CRM lo detecta.',
        ];
    } else {
        $out[] = 'Sin contacto externo: falta pruebas.correo_externo en la configuración (o el ajuste '
               . 'pruebas_correo_externo). Sin él no se puede probar que el CRM detecte las respuestas.';
    }
    $gente[] = [
        'nombre' => 'Responsable', 'apellido' => 'Interno',
        'email' => reinicio_dato('correo_interno', $correoDuenio),
        'cargo' => 'Responsable de Sistemas', 'telefono' => reinicio_dato('telefono_interno'),
        'nota' => 'LLEGA. Tu casilla interna. Sirve para ver el correo tal cual sale.',
    ];
    $llegan = count($gente);
    $gente = array_merge($gente, [

        // ---- A partir de acá, TODOS rebotan, y cada uno por un motivo ----
        // distinto. No alcanza con probar «que rebote»: cada motivo llega con
        // un código y un texto propios, y el sistema tiene que reconocerlos
        // todos. Si uno solo se le escapa, ese contacto sigue recibiendo
        // correo a una dirección muerta y eso es lo que arruina la reputación
        // del remitente.
        [
            'nombre' => 'Casilla', 'apellido' => 'Inexistente (dominio propio)',
            'email' => 'no-existe-esta-casilla-crm@intellihelp.tech',
            'cargo' => 'Prueba de rebote', 'telefono' => '',
            'nota' => 'REBOTE DEFINITIVO · el dominio existe pero la casilla no. '
                    . 'Google contesta «user unknown» (550 5.1.1). Es el rebote más común de todos: '
                    . 'la persona se fue de la empresa y le dieron de baja el correo.',
        ],
        [
            'nombre' => 'Casilla', 'apellido' => 'Inexistente (Gmail)',
            'email' => 'no-existe-esta-casilla-crm-ith-2026@gmail.com',
            'cargo' => 'Prueba de rebote', 'telefono' => '',
            'nota' => 'REBOTE DEFINITIVO · casilla inexistente en un proveedor grande. '
                    . 'Mismo motivo que el anterior pero rechazado por OTRO servidor, con otro '
                    . 'texto: sirve para comprobar que no se está reconociendo sólo el formato de Google Workspace.',
        ],
        [
            'nombre' => 'Dominio', 'apellido' => 'Inexistente',
            'email' => 'contacto@dominio-que-no-existe-crm-ith.com',
            'cargo' => 'Prueba de rebote', 'telefono' => '',
            'nota' => 'REBOTE DEFINITIVO · el dominio no existe: falla la resolución de DNS. '
                    . 'Es el típico error de tipeo en la dirección al cargar el contacto.',
        ],
        [
            'nombre' => 'Dominio', 'apellido' => 'Sin servidor de correo',
            'email' => 'alguien@example.com',
            'cargo' => 'Prueba de rebote', 'telefono' => '',
            'nota' => 'REBOTE DEFINITIVO · el dominio existe y resuelve, pero no acepta correo '
                    . '(no tiene servidor de entrada). Es un caso distinto del anterior y llega '
                    . 'con otro código.',
        ],
        [
            'nombre' => 'Dominio', 'apellido' => 'Reservado (.invalid)',
            'email' => 'prueba@no-existe-crm-ith.invalid',
            'cargo' => 'Prueba de rebote', 'telefono' => '',
            'nota' => 'REBOTE DEFINITIVO · «.invalid» es una terminación reservada por norma que '
                    . 'nunca puede existir. Garantiza el rebote, pase lo que pase con el DNS.',
        ],
        [
            'nombre' => 'Dominio', 'apellido' => 'Reservado (.test)',
            'email' => 'prueba@ejemplo-crm-ith.test',
            'cargo' => 'Prueba de rebote', 'telefono' => '',
            'nota' => 'REBOTE DEFINITIVO · «.test» también es una terminación reservada. Se incluye '
                    . 'para tener dos rebotes de DNS con textos distintos.',
        ],
    ]);

    $ids = [];
    foreach ($gente as $g) {
        // Se busca SIN filtrar por archivados. Los seis de rebote quedan
        // archivados y dados de baja después de cada prueba —que es
        // justo lo que se quiere comprobar—, así que buscando sólo entre los
        // activos no se encontraban y se creaba un duplicado en cada reinicio.
        $ya = q1('SELECT id FROM contactos WHERE email = ? ORDER BY id LIMIT 1', [$g['email']]);

        // Y se los deja listos para volver a probar: sin la marca de archivado,
        // sin la baja y sin la marca de rebote. Si no, la próxima campaña los
        // saltearía por dados de baja y no se podría repetir el circuito.
        if ($ya) {
            q('UPDATE contactos SET eliminado_en = NULL, no_email = 0, email_rebotado = 0 WHERE id = ?',
              [(int)$ya['id']]);
        }

        $ids[$g['email']] = contacto_guardar([
            'nombre'         => $g['nombre'],
            'apellido'       => $g['apellido'],
            'email'          => $g['email'],
            'cargo'          => $g['cargo'],
            'telefono'       => $g['telefono'],
            'cuenta_id'      => $cuentaId,
            'provincia'      => 'Buenos Aires',
            'pais'           => 'Argentina',
            'fuente'         => 'Otro',
            'propietario_id' => $duenio,
            'descripcion'    => $g['nota'],
        ], $ya ? (int)$ya['id'] : null);
    }
    $out[] = 'Contactos: ' . count($gente) . ' cargados (' . $llegan . ' que llegan, '
           . (count($gente) - $llegan) . ' que rebotan por motivos distintos)';

    // ---- Embudo ----
    // Se vuelve a sembrar porque el reinicio lo borra: si no, la pantalla del Embudo
    // queda vacía y los negocios no tienen dónde ubicarse.
    // Son 5 etapas, distintas de las 7 que siembra schema.sql: después de un
    // reinicio, pruebas no tiene el mismo embudo que una instalación nueva.
    // Con --conservar-base el embudo no se borra y esto no hace nada.
    if (!qv('SELECT COUNT(*) FROM embudos')) {
        q("INSERT INTO embudos (nombre, predeterminado, activo) VALUES ('Ventas', 1, 1)");
        $embudoId = last_id();
        foreach ([['Contacto inicial', 10, 'abierta', '#663399', 1],
                  ['Propuesta enviada', 50, 'abierta', '#1f6f9c', 2],
                  ['Negociación',       75, 'abierta', '#a8620a', 3],
                  ['Ganado',           100, 'ganada',  '#5f8a12', 4],
                  ['Perdido',            0, 'perdida', '#a12a1e', 5]] as [$n, $prob, $tipo, $color, $ord]) {
            q('INSERT INTO etapas (embudo_id, nombre, probabilidad, tipo, color, orden, activo)
               VALUES (?, ?, ?, ?, ?, ?, 1)', [$embudoId, $n, $prob, $tipo, $color, $ord]);
        }
        $out[] = 'Embudo: «Ventas» con sus 5 etapas';
    }

    // ---- Tipo de reunión ----
    // Es lo que hace funcionar el botón «Agendar una reunión» de las plantillas
    // y el enlace público. Sin esto, ese botón sale sin destino.
    if (!qv('SELECT COUNT(*) FROM agenda_tipos')) {
        $tipoId = agenda_tipo_guardar([
            'usuario_id' => $duenio,
            'nombre'     => 'Reunión de 30 minutos',
            'slug'       => 'demo-30',
            'duracion_min' => 30,
            'margen_min'   => 15,
            'ventana_dias' => 30,
            'aviso_min_horas' => 2,
            'descripcion' => 'Charlamos 30 minutos por videollamada para ver si podemos ayudarte.',
            'con_meet'   => 1,
            'activo'     => 1,
            'dias'       => [1, 2, 3, 4, 5],
            'desde'      => array_fill_keys([1, 2, 3, 4, 5], '09:00'),
            'hasta'      => array_fill_keys([1, 2, 3, 4, 5], '18:00'),
        ]);
        $out[] = 'Agenda: «Reunión de 30 minutos», de lunes a viernes de 9 a 18 (id ' . $tipoId . ')';
    }

    // ---- Reunión de diagnóstico ----
    // Es el tipo que usa la landing de la web (/diagnostico/): la web en local
    // se prueba contra esta instalación pidiendo los horarios de
    // `<vendedor>/diagnostico`. Sin él, la landing cae siempre al formulario
    // y no hay forma de probar la reserva de punta a punta.
    if (!qv("SELECT COUNT(*) FROM agenda_tipos WHERE usuario_id = ? AND slug = 'diagnostico'", [$duenio])) {
        $tipoId = agenda_tipo_guardar([
            'usuario_id'   => $duenio,
            'nombre'       => 'Reunión de diagnóstico',
            'slug'         => 'diagnostico',
            'duracion_min' => 30,
            'margen_min'   => 15,
            'ventana_dias' => 21,
            'aviso_min_horas' => 2,
            'descripcion'  => '30 minutos por videollamada para ver cómo está hoy la tecnología de tu empresa '
                            . 'y qué conviene resolver primero.',
            'con_meet'     => 1,
            'activo'       => 1,
            'dias'         => [1, 2, 3, 4, 5],
            'desde'        => array_fill_keys([1, 2, 3, 4, 5], '09:00'),
            'hasta'        => array_fill_keys([1, 2, 3, 4, 5], '18:00'),
        ]);
        $out[] = 'Agenda: «Reunión de diagnóstico» (la de la web), de lunes a viernes de 9 a 18 (id ' . $tipoId . ')';
    }

    // ---- Plantillas ----
    // Se dejan cargadas TODAS las prearmadas: una por cada servicio de la
    // empresa, más los tres formatos genéricos. Se reescriben desde los moldes
    // en cada reinicio: si un molde cambia de nombre, la fila de la base lo
    // sigue y no quedan plantillas creadas probando.
    $nPlantillas = 0;
    $plantillaIds = [];
    foreach (plantillas_preajustes() as $clave => $p) {
        $ya = q1('SELECT id FROM plantillas WHERE preajuste = ? AND eliminado_en IS NULL LIMIT 1', [$clave]);
        plantilla_guardar([
            'nombre'    => $p['nombre'],
            'asunto'    => $p['asunto'],
            'tipo'      => 'visual',
            'bloques'   => ['bloques' => $p['bloques'], 'ajustes' => $p['ajustes']],
            'preajuste' => $clave,
            'carpeta'   => isset($p['bloques'][1]['volanta']) ? 'Servicios' : 'Genéricas',
            'activo'    => 1,
        ], $ya ? (int)$ya['id'] : null);
        $plantillaIds[] = $ya ? (int)$ya['id'] : (int) qv('SELECT id FROM plantillas WHERE preajuste = ? ORDER BY id DESC LIMIT 1', [$clave]);
        $nPlantillas++;
    }
    $out[] = 'Plantillas: ' . $nPlantillas . ' cargadas, una por servicio más las genéricas';

    // ---- Lista de prueba ----
    // Se busca por nombre; INSERT IGNORE sobre (lista, email) hace que volver
    // a sembrar no repita destinatarios.
    $lista = q1("SELECT id FROM listas WHERE nombre = 'Prueba del circuito' AND eliminado_en IS NULL LIMIT 1");
    if (!$lista) {
        q("INSERT INTO listas (nombre, descripcion, creado_por)
           VALUES ('Prueba del circuito',
                   'Mandale una campaña a esta lista y vas a ver las tres cosas: los que llegan, los que rebotan y cómo se archivan solos.',
                   ?)", [$duenio]);
        $listaId = last_id();
    } else {
        $listaId = (int)$lista['id'];
    }
    foreach ($gente as $g) {
        q('INSERT IGNORE INTO lista_miembros (lista_id, contacto_id, email, nombre) VALUES (?, ?, ?, ?)',
          [$listaId, $ids[$g['email']], $g['email'], trim($g['nombre'] . ' ' . $g['apellido'])]);
    }
    $out[] = 'Lista: «Prueba del circuito» con ' . count($gente) . ' destinatarios (id ' . $listaId . ')';

    // Sin lista de direcciones autorizadas: el modo prueba manda igual
    // que producción. Lo que lo hace seguro es que estos datos son ficticios.
    setting_set('testing_destinatarios', '');

    // Queda anotado EXACTAMENTE qué se sembró. El día que se pase a producción
    // hay que poder sacar esto y nada más: adivinar cuáles eran, sobre la base
    // de alguien, es la peor idea posible.
    datos_prueba_anotar([
        'contactos'  => array_values(array_map('intval', $ids)),
        'cuentas'    => array_values(array_filter([(int)$cuentaId])),
        'listas'     => [(int)$listaId],
        'plantillas' => array_map('intval', $plantillaIds),
    ]);

    return $out;
}

<?php
declare(strict_types=1);

// ============================================================
//  Reinicio de la instalación de pruebas.
//
//  Mientras el sistema está en modo prueba conviene poder dejarlo en cero
//  después de cada tanda: el registro del cron, las campañas de ensayo, los
//  envíos, las reuniones reservadas. Lo que NO se toca es lo que hace falta
//  para volver a probar: las casillas de correo, los ajustes, las plantillas,
//  los tipos de reunión, los usuarios y la cuenta de Google conectada.
//
//  Uso:
//      php app/reinicio.php                 muestra qué borraría, sin borrar
//      php app/reinicio.php --hacerlo       borra
//      php app/reinicio.php --hacerlo --contactos   borra además la base de
//                                           contactos, empresas y posibles
//      php app/reinicio.php --sembrar       deja cargados los datos de prueba
//
//  PROTECCIÓN: si el sistema está en producción, no borra nada. El modo se
//  cambia en Ajustes → Modo de trabajo, y ahí queda registrado quién lo hizo.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Esto se ejecuta por línea de comandos.\n");
}

$raiz = is_file(__DIR__ . '/web/app/bootstrap.php') ? __DIR__ . '/web' : __DIR__;
require $raiz . '/app/bootstrap.php';

$args      = array_slice($argv, 1);
$hacerlo   = in_array('--hacerlo', $args, true);
$contactos = in_array('--contactos', $args, true);
$sembrar   = in_array('--sembrar', $args, true);

$entorno = (string) setting_get('app_entorno', 'testing');
if ($entorno !== 'testing') {
    fwrite(STDERR, "El sistema está en PRODUCCIÓN. No se borra nada.\n"
                 . "Si de verdad hace falta, primero se pasa a modo prueba desde Ajustes.\n");
    exit(1);
}

/**
 * Lo que se borra: el ruido que genera una tanda de pruebas.
 *
 * El orden importa por las claves foráneas: primero lo que depende de otra
 * cosa, después la cosa.
 */
$tablas = [
    'envio_eventos'      => 'aperturas y clics de los envíos',
    'envios'             => 'cola y resultado de los envíos',
    'campanas'           => 'campañas',
    'lista_miembros'     => 'destinatarios de las listas',
    'listas'             => 'listas de destinatarios',
    'actividad_invitados'=> 'invitados de las reuniones',
    'agenda_reservas'    => 'reuniones reservadas desde la agenda pública',
    'actividades'        => 'tareas, llamadas y reuniones',
    'notas'              => 'notas',
    'adjuntos'           => 'archivos adjuntos',
    'desuscripciones'    => 'bajas de suscripción',
    'import_lotes'       => 'lotes de importación',
    'imap_estado'        => 'marca de por dónde iba la lectura de cada casilla',
    'actividad'          => 'registro de auditoría y del cron',
];

// Con --contactos se borra también la base de trabajo. Va aparte porque no es
// ruido de pruebas: son los 29.000 contactos importados, y volver a cargarlos
// lleva su tiempo.
$tablasContactos = [
    'negocio_contactos'  => 'vínculos de negocios con contactos',
    'negocio_etapas'     => 'historial de etapas de los negocios',
    'negocios'           => 'negocios',
    'etiqueta_registro'  => 'etiquetas aplicadas',
    'posibles_clientes'  => 'posibles clientes',
    'contactos'          => 'contactos',
    'cuentas'            => 'empresas',
];
if ($contactos) { $tablas = $tablasContactos + $tablas; }

$conserva = [
    'usuarios'         => 'los usuarios y sus permisos',
    'google_tokens'    => 'la cuenta de Google conectada',
    'cuentas_correo'   => 'las casillas de correo, con su contraseña',
    'app_settings'     => 'los ajustes y los datos de la empresa',
    'plantillas'       => 'las plantillas de campaña',
    'agenda_tipos'     => 'los tipos de reunión y su horario',
    'categorias'       => 'los segmentos',
    'embudos / etapas' => 'la configuración del embudo',
    'imagenes'         => 'la biblioteca de imágenes',
];

echo $hacerlo ? "== Reinicio de la instalación de pruebas ==\n" : "== Simulacro: NO se borra nada ==\n";
echo "\nSe borra:\n";
$total = 0;
foreach ($tablas as $t => $desc) {
    $n = (int) qv("SELECT COUNT(*) FROM `$t`");
    $total += $n;
    printf("  %-22s %7d   %s\n", $t, $n, $desc);
    if ($hacerlo && $n > 0) { q("DELETE FROM `$t`"); }
}
if (!$contactos) {
    echo "\n  (la base de contactos, empresas y posibles NO se toca; para borrarla también: --contactos)\n";
}

echo "\nSe conserva:\n";
foreach ($conserva as $t => $desc) { printf("  %-22s %s\n", $t, $desc); }

if ($hacerlo) {
    // La marca del cron también se limpia, para que la próxima corrida arranque
    // como si fuera la primera.
    foreach (['cron_ultima_corrida', 'imap_ultima_corrida', 'gcal_falla'] as $k) {
        setting_set($k, '');
    }
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
 * Deja cargado lo mínimo para poder probar: la empresa y el contacto de Brian,
 * y una lista con esa dirección para mandarse una campaña a uno mismo.
 * Es idempotente: correrlo dos veces no duplica nada.
 */
function reinicio_sembrar(): array
{
    $out = [];

    // El dueño de los datos de prueba es el primer administrador activo. No se
    // puede dar por sentado que sea el usuario 1: los usuarios se crean al
    // entrar con Google, así que el número depende del orden en que entraron.
    $duenio = (int) (qv("SELECT id FROM usuarios WHERE email = 'bsierra@intellihelp.tech' AND activo = 1")
                  ?: qv("SELECT MIN(id) FROM usuarios WHERE rol = 'admin' AND activo = 1")
                  ?: qv('SELECT MIN(id) FROM usuarios'));
    if (!$duenio) {
        return ['No hay ningún usuario cargado todavía: entrá una vez con Google y volvé a correrlo.'];
    }

    $cuentaId = cuenta_asegurar('INTELLIHELP S.A.', [
        'sitio_web' => 'https://intellihelp.tech',
        'sector'    => 'Tecnología',
        'provincia' => 'Buenos Aires',
        'pais'      => 'Argentina',
    ], $duenio);
    $out[] = 'Empresa: INTELLIHELP S.A. (id ' . (int)$cuentaId . ')';

    $email = 'bsierra@intellihelp.tech';
    $ya = q1('SELECT id FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$email]);
    $datos = [
        'nombre'         => 'Brian',
        'apellido'       => 'Sierra',
        'email'          => $email,
        'cargo'          => 'Responsable de Sistemas',
        'telefono'       => '5491150274827',
        'cuenta_id'      => $cuentaId,
        'sitio_web'      => 'https://intellihelp.tech',
        'provincia'      => 'Buenos Aires',
        'pais'           => 'Argentina',
        'fuente'         => 'Otro',
        'propietario_id' => $duenio,
        'descripcion'    => 'Contacto de prueba: sirve para mandarse una campaña y ver cómo llega.',
    ];
    $contactoId = contacto_guardar($datos, $ya ? (int)$ya['id'] : null);
    $out[] = 'Contacto: Brian Sierra <' . $email . '> (id ' . $contactoId . ')';

    // Lista con ese único destinatario, para probar un envío de punta a punta.
    $lista = q1("SELECT id FROM listas WHERE nombre = 'Prueba interna' AND eliminado_en IS NULL LIMIT 1");
    if (!$lista) {
        q("INSERT INTO listas (nombre, descripcion, creado_por)
           VALUES ('Prueba interna', 'Una sola dirección, para probar cómo llega una campaña.', ?)", [$duenio]);
        $listaId = last_id();
    } else {
        $listaId = (int)$lista['id'];
    }
    q('INSERT IGNORE INTO lista_miembros (lista_id, contacto_id, email, nombre) VALUES (?, ?, ?, ?)',
      [$listaId, $contactoId, $email, 'Brian Sierra']);
    $out[] = 'Lista: «Prueba interna» con 1 destinatario (id ' . $listaId . ')';

    // Destinatarios permitidos en modo prueba: si esto queda vacío, el modo
    // prueba bloquea todo y no se puede probar nada.
    if (trim((string) setting_get('testing_destinatarios', '')) === '') {
        setting_set('testing_destinatarios', $email);
        $out[] = 'Modo prueba: se habilitó ' . $email . ' como destinatario';
    }

    return $out;
}

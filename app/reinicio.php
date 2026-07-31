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

    // ---- Datos de la empresa ----
    // Son los que usan las plantillas: el bloque de contacto, el botón de
    // WhatsApp y las variables {{remitente_empresa}} y {{empresa_web}}. Se
    // dejan cargados para no tener que escribirlos cada vez que se reinicia.
    $empresa = [
        'empresa_nombre'   => 'INTELLIHELP',
        'empresa_web'      => 'intellihelp.tech',
        'empresa_telefono' => '1177006868',
        'empresa_whatsapp' => '5491150274827',
        'empresa_email'    => 'info@intellihelp.tech',
    ];
    foreach ($empresa as $k => $v) { setting_set($k, $v); }
    $out[] = 'Datos de la empresa: ' . $empresa['empresa_nombre'] . ' · ' . $empresa['empresa_web']
           . ' · WhatsApp ' . telefono_formato($empresa['empresa_whatsapp']);

    $cuentaId = cuenta_asegurar('INTELLIHELP S.A.', [
        'sitio_web' => 'https://intellihelp.tech',
        'sector'    => 'Tecnología',
        'provincia' => 'Buenos Aires',
        'pais'      => 'Argentina',
    ], $duenio);
    $out[] = 'Empresa: INTELLIHELP S.A. (id ' . (int)$cuentaId . ')';

    // ---- Contactos de prueba ----
    //
    // La idea es poder mandar UNA campaña y ver el circuito completo: los que
    // llegan bien y los distintos motivos por los que un correo vuelve.
    //
    // Los dos ficticios usan alias con «+» de la casilla real. Google los
    // entrega en la misma bandeja, así que son contactos distintos en el CRM
    // —con su nombre, su empresa y su cargo— pero los correos llegan de verdad
    // y se pueden leer. Con direcciones inventadas no llegaría nada y no se
    // podría comprobar nada.
    //
    // Los de rebote apuntan a lugares que NO existen a propósito, cada uno con
    // un motivo distinto, que es lo que permite ver cómo reacciona el sistema:
    // el rebote definitivo archiva el contacto y lo da de baja; el pasajero
    // sólo lo marca y lo reintenta.
    $gente = [
        [
            'nombre' => 'Brian', 'apellido' => 'Sierra', 'email' => 'bsierra@intellihelp.tech',
            'cargo' => 'Responsable de Sistemas', 'telefono' => '5491150274827',
            'nota' => 'Vos. El correo llega de verdad.',
        ],
        [
            'nombre' => 'Laura', 'apellido' => 'Gómez', 'email' => 'bsierra+laura@intellihelp.tech',
            'cargo' => 'Gerenta de Recursos Humanos', 'telefono' => '5491145678901',
            'nota' => 'Contacto ficticio. Llega a tu misma bandeja (alias con «+»).',
        ],
        [
            'nombre' => 'Martín', 'apellido' => 'Ferreyra', 'email' => 'bsierra+martin@intellihelp.tech',
            'cargo' => 'Jefe de Compras', 'telefono' => '5491139876543',
            'nota' => 'Contacto ficticio. Llega a tu misma bandeja (alias con «+»).',
        ],
        [
            'nombre' => 'Casilla', 'apellido' => 'Inexistente',
            'email' => 'no-existe-esta-casilla-crm@intellihelp.tech',
            'cargo' => 'Prueba de rebote', 'telefono' => '',
            'nota' => 'REBOTE DEFINITIVO: la casilla no existe en un dominio que sí existe. '
                    . 'Google responde «user unknown». El contacto se archiva solo y se da de baja.',
        ],
        [
            'nombre' => 'Dominio', 'apellido' => 'Inexistente',
            'email' => 'contacto@dominio-que-no-existe-crm-ith.com',
            'cargo' => 'Prueba de rebote', 'telefono' => '',
            'nota' => 'REBOTE DEFINITIVO: el dominio no existe. El sistema lo detecta por el '
                    . 'código 5xx y archiva el contacto.',
        ],
        [
            'nombre' => 'Dominio', 'apellido' => 'Sin correo',
            'email' => 'alguien@example.com',
            'cargo' => 'Prueba de rebote', 'telefono' => '',
            'nota' => 'REBOTE DEFINITIVO: example.com existe pero está reservado y no recibe correo.',
        ],
    ];

    $ids = [];
    foreach ($gente as $g) {
        $ya = q1('SELECT id FROM contactos WHERE email = ? AND eliminado_en IS NULL LIMIT 1', [$g['email']]);
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
    $out[] = 'Contactos: ' . count($gente) . ' cargados (2 que llegan, 3 que rebotan, más vos)';

    // ---- Lista de prueba ----
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

    // ---- Destinatarios permitidos en modo prueba ----
    // Van TODOS los de arriba: en modo prueba sólo sale correo a las
    // direcciones de esta lista, así que sin esto los de rebote quedarían
    // omitidos y no se podría comprobar nada.
    setting_set('testing_destinatarios', implode(', ', array_column($gente, 'email')));
    $out[] = 'Modo prueba: habilitadas las ' . count($gente) . ' direcciones de arriba';

    return $out;
}

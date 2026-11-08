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
//      php app/reinicio.php                 muestra qué borraría, sin borrar
//      php app/reinicio.php --hacerlo       borra
//      php app/reinicio.php --sembrar       sólo deja cargados los datos de prueba
//      php app/reinicio.php --hacerlo --conservar-base
//                                           borra el ruido pero NO toca la base de
//                                           contactos, empresas y posibles
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

$args     = array_slice($argv, 1);
$hacerlo  = in_array('--hacerlo', $args, true);
$sembrar  = in_array('--sembrar', $args, true);
// La base de contactos se borra por defecto. Se conserva sólo si se pide,
// para cuando adentro hay una importación real.
$conservarBase = in_array('--conservar-base', $args, true)
              || in_array('--conservar-contactos', $args, true);

$entorno = (string) setting_get('app_entorno', 'testing');
if ($entorno !== 'testing') {
    fwrite(STDERR, "El sistema está en PRODUCCIÓN. No se borra nada.\n"
                 . "Si de verdad hace falta, primero se pasa a modo prueba desde Ajustes.\n");
    exit(1);
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
    'adjuntos'           => 'archivos adjuntos',
    'desuscripciones'    => 'bajas de suscripción',
    'import_lotes'       => 'lotes de importación',
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
    'imagenes'           => 'imágenes subidas a la biblioteca',
    'api_tokens'         => 'claves de la API (si tenías una integración andando, hay que generar otra)',
    'cuotas'             => 'objetivos de venta cargados',
    // La conexión con Google también la hizo una persona. Se puede borrar sin
    // miedo: sin la fila, el próximo ingreso vuelve a pedir el consentimiento y
    // Google manda el token de nuevo, así que se reconecta sola al entrar.
    'google_tokens'      => 'la conexión con Google (se rehace sola al volver a entrar)',
];
// El ruido va PRIMERO y la base después: los envíos, los miembros de las listas
// y las actividades apuntan a contactos y empresas. Varias de esas referencias
// no tienen clave foránea declarada, así que borrar al revés no da error —
// simplemente deja filas apuntando a algo que ya no existe si el script se
// corta en el medio.
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
];

echo $hacerlo ? "== Reinicio de la instalación de pruebas ==\n" : "== Simulacro: NO se borra nada ==\n";

// ------------------------------------------------------------
//  Google Calendar
// ------------------------------------------------------------
// Las reuniones de prueba también están en el calendario de Google, y borrar la
// fila de la base no las saca de ahí: quedaban dando vueltas y avisando de
// reuniones que ya no existen en ningún lado. Se borran primero, con los avisos
// de cancelación apagados: no tiene sentido mandar un correo por algo que nunca
// fue una reunión real.
$conEvento = q("SELECT id, usuario_id, gcal_event_id, invitado_nombre, inicia_el
                FROM agenda_reservas
                WHERE gcal_event_id IS NOT NULL AND gcal_event_id <> ''")->fetchAll();
if ($conEvento) {
    echo "\nEn Google Calendar:\n";
    foreach ($conEvento as $r) {
        printf("  %s  %-28s %s\n", substr((string)$r['inicia_el'], 0, 16),
               recortar((string)$r['invitado_nombre'], 27),
               $hacerlo ? '' : '(se borraría)');
        if (!$hacerlo) { continue; }
        try {
            [$c] = gcal_api((int)$r['usuario_id'], 'DELETE',
                '/calendars/primary/events/' . rawurlencode((string)$r['gcal_event_id']) . '?sendUpdates=none');
            if (!(($c >= 200 && $c < 300) || $c === 410)) {
                echo "      no se pudo borrar (HTTP $c): sacalo a mano del calendario\n";
            }
        } catch (\Throwable $e) {
            echo '      no se pudo borrar: ' . $e->getMessage() . "\n";
        }
    }
} elseif (!$hacerlo) {
    echo "\nEn Google Calendar: no hay ninguna reunión de prueba.\n";
}

echo "\nSe borra:\n";
$total = 0;
foreach ($tablas as $t => $desc) {
    $n = (int) qv("SELECT COUNT(*) FROM `$t`");
    $total += $n;
    printf("  %-22s %7d   %s\n", $t, $n, $desc);
    if ($hacerlo && $n > 0) { q("DELETE FROM `$t`"); }
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
$todas = q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
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
 * Deja cargado lo mínimo para poder probar: la empresa, mis dos casillas como
 * contactos, los de rebote y una lista con todos para mandarse una campaña a
 * uno mismo.
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
    $gente = [
        [
            // Va mi correo personal a propósito: llegando a otro proveedor se
            // prueba lo que no se puede probar mandándose correo a uno mismo
            // —que salga bien, que no caiga en spam— y, sobre todo, permite
            // contestar desde una dirección distinta a la que el sistema lee,
            // que es la única forma de comprobar que detecta las respuestas.
            'nombre' => 'Brian', 'apellido' => 'Sierra', 'email' => 'bsierra4262@gmail.com',
            'cargo' => 'Responsable de Sistemas', 'telefono' => '5491150274827',
            'nota' => 'Vos. Llega a tu Gmail: desde ahí podés responder y ver que el CRM lo detecta.',
        ],
        [
            'nombre' => 'Brian', 'apellido' => 'Sierra (interno)', 'email' => 'bsierra@intellihelp.tech',
            'cargo' => 'Responsable de Sistemas', 'telefono' => '5491177006868',
            'nota' => 'LLEGA. Tu casilla interna. Sirve para ver el correo tal cual sale.',
        ],

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
    ];

    $ids = [];
    foreach ($gente as $g) {
        // Se busca SIN filtrar por archivados. Los tres de rebote quedan
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
    $out[] = 'Contactos: ' . count($gente) . ' cargados (2 que llegan, ' . (count($gente) - 2) . ' que rebotan por motivos distintos)';

    // ---- Embudo ----
    // Se vuelve a sembrar porque el reinicio lo borra: si no, la pantalla del Embudo
    // queda vacía y los negocios no tienen dónde ubicarse.
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

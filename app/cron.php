<?php
declare(strict_types=1);

// ============================================================
//  Tareas periódicas del CRM.
//
//  Agendar en el cron de Hostinger (hPanel), cada 5 minutos:
//      */5 * * * * /usr/bin/php /home/u566451014/domains/intellihelp.tech/public_html/crm/cron.php >/dev/null 2>&1
//
//  (Esa ruta es la de producción. La instalación de pruebas tiene su propio
//  cron.php en public_html/crm-pruebas/, con su propia configuración y base;
//  si se programa también, va como una segunda línea del cron.)
//
//  Hace estas cosas, en este orden (los números son los de las secciones de
//  abajo):
//    1 y 2, en vueltas de ~55 s hasta gastar CRON_CORRIDA_SEG (280 s):
//      1. lee las casillas: rebotes y respuestas (los rebotes definitivos
//         archivan el contacto, porque ya no se le puede escribir)
//      2. despacha los correos pendientes de las campañas (con su pausa)
//    3.  completa las reservas que quedaron sin evento de Google o sin correo
//    3b. manda los recordatorios de las reuniones de las próximas 24 h
//    3c. una vez por día, actualiza los feriados desde el calendario oficial
//        y avisa si un feriado nuevo cae sobre reuniones ya agendadas
//    4.  manda por correo las alertas pendientes (un solo correo por corrida)
//    5.  una vez por día, purga lo que lleva más de `papelera_dias` (60) en
//        la papelera: el único borrado físico del sistema
//    6.  una vez por día, borra los datos personales de las entradas de la web
//        que ya no tienen contacto y limpia los contadores de límites vencidos
//
//  Cada paso va en su propio try/catch: si uno falla, se anota en la salida y
//  en `actividad` (tipo 'error', acción 'cron.<paso>_error') y la corrida
//  sigue con el siguiente. La salida del cron de hPanel va a /dev/null, así
//  que esos registros en `actividad` (pantalla CRON) son el único rastro de
//  un error: nadie recibe un aviso.
//
//  Ajustes que lee y escribe: `cron_clave` (para la llamada por URL),
//  `cron_turno`, `cron_en_curso_desde`, `cron_ultima_corrida`,
//  `papelera_ultima_purga`, `entradas_web_ultima_limpieza`, `papelera_dias`.
//
//  Es seguro correrlo muchas veces: todo es idempotente y nada se duplica.
// ============================================================

// Se puede llamar también por HTTP (algunos paneles sólo ofrecen cron-URL).
// En ese caso hace falta la clave que se configura en Ajustes.
$porWeb = PHP_SAPI !== 'cli';

// En el repositorio este archivo vive en app/ y el núcleo en app/web/app/;
// al desplegar, el contenido de app/web/ pasa a ser la raíz del sitio y el
// cron queda al lado de app/. Probamos las dos ubicaciones para que el mismo
// archivo sirva en desarrollo y en producción.
$bootstrap = null;
foreach ([__DIR__ . '/app/bootstrap.php', __DIR__ . '/web/app/bootstrap.php'] as $cand) {
    if (is_file($cand)) { $bootstrap = $cand; break; }
}
if (!$bootstrap) {
    fwrite(STDERR, "No se encontró el núcleo de la aplicación junto a cron.php.\n");
    exit(1);
}
require $bootstrap;

// Por URL: /cron.php?clave=<cron_clave>. Sin clave configurada se niega
// siempre, así que por defecto la vía web está cerrada. La clave viaja en la
// URL, de modo que queda en los registros de acceso del servidor; y se
// compara con !== (no con hash_equals), lo que en teoría deja medir tiempos.
if ($porWeb) {
    $clave = (string) setting_get('cron_clave', '');
    if ($clave === '' || (($_GET['clave'] ?? '') !== $clave)) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// Una sola corrida a la vez. Sin esto, dos corridas solapadas leían los mismos
// envíos pendientes y los mandaban las dos: el destinatario recibía el correo
// duplicado. Pasa fácil, porque además del cron se puede disparar por URL.
//
// El bloqueo vive en la conexión, y la conexión se reabre después de cada pausa
// del despachador (este hosting la cierra a los 20 segundos de inactividad), así
// que se puede perder a mitad de camino. Por eso NO es la única defensa: cada
// envío se toma además con un UPDATE condicionado a su estado, que es atómico y
// no depende de ninguna conexión en particular.
//
// Trampa: los bloqueos con nombre de MariaDB son de TODO el servidor, no de
// cada base, y producción y pruebas comparten el servidor. Este nombre fijo
// ('crm_cron', sin la base adelante, a diferencia de los de agenda.php y
// feriados.php) hace que la corrida de una instalación bloquee a la de la
// otra: si las dos están programadas a la misma hora, una se saltea entera.
if (!qv("SELECT GET_LOCK('crm_cron', 0)")) {
    $msg = 'Ya hay una corrida en curso. Esta se saltea para no duplicar envíos.';
    if ($porWeb) { echo $msg . PHP_EOL; } else { fwrite(STDOUT, $msg . PHP_EOL); }
    exit(0);
}
// El bloqueo se suelta solo al cerrar la conexión, pero se libera explícito
// por si el proceso sigue vivo.
register_shutdown_function(static function (): void {
    try { qv("SELECT RELEASE_LOCK('crm_cron')"); } catch (\Throwable $e) {}
});

// Hora de arranque, para el tiempo total que se imprime al final, y las
// líneas del resumen que arma cada paso.
$t0     = microtime(true);
$lineas = [];

// Una sola corrida a la vez. Corriendo cada minuto, la anterior puede seguir
// trabajando cuando arranca la siguiente: dos a la vez leen la misma casilla
// dos veces y cuentan de más. El que no toma el turno se va sin hacer ruido.
//
// Esta es la segunda defensa, además del GET_LOCK de arriba: el turno vive en
// `app_settings.cron_turno` de ESTA base (no se cruza con la otra instalación)
// y no depende de la conexión. Vence a los 600 s (cron_tomar_turno() en
// campanas.php): si el proceso muere sin soltarlo, la corrida siguiente lo
// retoma después de ese plazo. Una corrida que tardara más de 600 s
// perdería el turno y podría solaparse con la siguiente.
if (!cron_tomar_turno()) {
    $msg = date('c') . ' · otra corrida sigue en curso, este turno se saltea' . PHP_EOL;
    if ($porWeb) { echo $msg; } else { fwrite(STDOUT, $msg); }
    exit(0);
}
// Pase lo que pase de acá en adelante, el turno se suelta: si un error corta la
// corrida sin soltarlo, el cron queda trabado hasta que venza el plazo.
register_shutdown_function(static function (): void {
    try { db_reanudar(); cron_soltar_turno(); } catch (\Throwable $e) {}
});

// Se deja anotado que la corrida ARRANCÓ, no sólo cuándo terminó. Una corrida
// que manda correos dura varios minutos, y mientras tanto la pantalla mostraba
// la hora de la corrida anterior: parecía que el cron estaba parado justo
// cuando más trabajando estaba.
// Si la corrida muere por un error fatal, esta marca queda puesta (sólo se
// limpia al final del archivo) aunque el turno se suelte.
setting_set('cron_en_curso_desde', date('Y-m-d H:i:s'));

// ============================================================
//  Las vueltas de trabajo
//
//  El cron marca el pulso, pero el ritmo lo ponemos acá. hPanel no siempre
//  deja programar cada minuto —hay planes donde el mínimo es cada cinco—, y
//  atar el sistema a lo que ofrezca un desplegable ajeno es frágil.
//
//  Así que el script trabaja en vueltas de ~55 segundos hasta gastar su
//  presupuesto. Con el cron cada minuto da una vuelta y sale; con el cron cada
//  cinco da cinco vueltas seguidas. En los dos casos la casilla se mira
//  aproximadamente cada minuto, que es lo que hace que una respuesta aparezca
//  al toque en vez de cinco minutos después.
//
//  Las constantes viven en campanas.php: CRON_CICLO_SEG (55, cada vuelta),
//  CRON_CORRIDA_SEG (280, el total) y CRON_PRESUPUESTO_SEG (45, lo que el
//  despachador puede gastar dentro de una vuelta). Los pasos 3 a 6 corren una
//  sola vez, después de las vueltas, así que la corrida entera dura los 280 s
//  más lo que tarden ellos (la consulta de feriados espera hasta 15 s).
// ============================================================
$finDeCorrida = microtime(true) + CRON_CORRIDA_SEG;
$vueltas = 0;

do {
    $vueltas++;
    $arrancoVuelta = microtime(true);

// ---- 1. Casilla: rebotes y respuestas ----
    //
    // Va ANTES de las campañas, y no después. El despachador se lleva 240 segundos
    // de la corrida, así que leyendo al final los rebotes y las respuestas
    // aparecían casi cuatro minutos tarde, todos juntos, cuando la campaña ya había
    // terminado: la pantalla mostraba 6 de 6 con todo en cero mientras las cosas
    // ya estaban pasando.
    //
    // Cada cuánto se mira depende de si hay algo en curso: con una campaña
    // mandando, los rebotes vuelven a los segundos y las respuestas empiezan a
    // entrar enseguida, así que se mira seguido. Sin nada en curso se espacía,
    // porque abrir IMAP es caro y no tiene sentido hacerlo para no encontrar nada.
    // El despachador además vuelve a mirar en el medio del envío, aprovechando las
    // pausas entre correo y correo.
    //
    // imap_quizas_revisar(seg) devuelve null si la última lectura fue hace
    // menos de `seg` segundos; si no, lee y devuelve los contadores (o
    // 'error' con el motivo, por ejemplo una casilla con la clave vencida).
    // No lanza excepciones: el catch de abajo es por si falla la consulta de
    // campañas activas o la base.
    try {
        $activas = (int) qv("SELECT COUNT(*) FROM campanas
                             WHERE eliminado_en IS NULL AND estado IN ('enviando','programada')");
        $r = imap_quizas_revisar($activas > 0 ? 60 : 900);
        if ($r === null) {
            $lineas[] = 'casilla: se salteó (todavía no tocaba)';
        } else {
            $lineas[] = $r['error']
                ? 'casillas: ' . $r['error']
                : sprintf('casillas: %d revisadas, %d correos, %d rebotes (%d definitivos), %d respuestas',
                          $r['casillas'] ?? 1, $r['revisados'], $r['rebotes'], $r['duros'] ?? 0, $r['respuestas']);
            if (($r['rebotes'] ?? 0) || ($r['respuestas'] ?? 0)) {
                log_app('cron.casilla', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
            }
        }
    } catch (\Throwable $e) {
        db_reanudar();
        $lineas[] = 'casilla ERROR: ' . $e->getMessage();
        log_app('cron.casilla_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
    }
    
    // ---- 2. Campañas ----
    try {
        // El presupuesto sale de la constante, no de un número escrito acá: la
        // pantalla calcula con ella el ritmo de envío, y los dos valores tienen
        // que coincidir. El 3 no es un tope de correos: campanas_despachar()
        // lo multiplica por 6 para el LIMIT de pendientes que lee por campaña;
        // el tope real lo pone el presupuesto de tiempo.
        $r = campanas_despachar(3, CRON_PRESUPUESTO_SEG);
        $lineas[] = sprintf('campañas: %d enviados, %d rebotados, %d omitidos, %d errores',
            $r['enviados'], $r['rebotados'], $r['omitidos'], $r['errores']);
        if ($r['enviados'] || $r['rebotados'] || $r['errores']) {
            log_app('cron.campanas', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
        }
    } catch (\Throwable $e) {
        // La conexión pudo haberse caído durante una pausa: se reabre antes de
        // intentar registrar nada, o el registro del error falla también y la
        // corrida termina sin dejar ningún rastro de lo que pasó.
        db_reanudar();
        $lineas[] = 'campañas ERROR: ' . $e->getMessage();
        log_app('cron.campanas_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
    }
    
    // El despachador es lo único que duerme. Después de él, la conexión puede estar
    // muerta y todo lo que sigue —rebotes, recordatorios, purga— la necesita.
    db_reanudar();

    // Si queda presupuesto, se espera a completar el minuto y se da otra
    // vuelta. Dormir lo que sobra —en vez de encadenar vueltas sin pausa— es
    // lo que mantiene el ritmo parejo y no castiga al servidor.
    $sobra = CRON_CICLO_SEG - (microtime(true) - $arrancoVuelta);
    if (microtime(true) + max(0, $sobra) >= $finDeCorrida) { break; }
    if ($sobra > 0) { sleep((int) ceil($sobra)); }
    // Después de dormir, la conexión a la base puede estar muerta: este hosting
    // la cierra a los 20 segundos de inactividad.
    db_reanudar();
} while (microtime(true) < $finDeCorrida);

if ($vueltas > 1) { $lineas[] = $vueltas . ' vueltas'; }

// ---- 3. Reservas sin completar ----
// La reserva que llega de la web responde apenas queda guardada; el evento de
// Google con Meet y el correo se hacen después. Si ese proceso se cortó (un
// reinicio del servidor, un límite de tiempo), la reunión quedó tomada pero
// nadie se enteró: ni el calendario del vendedor ni el invitado. Acá se
// completan las que llevan más de dos minutos así.
// function_exists: hoy bootstrap.php carga siempre agenda.php, así que la
// función existe; la guarda quedó de cuando el cron podía convivir con un
// núcleo desplegado más viejo. Si faltara, el paso se saltea callado.
try {
    if (function_exists('agenda_completar_pendientes')) {
        $n = agenda_completar_pendientes();
        $lineas[] = "reservas: $n completadas";
        if ($n) {
            log_app('cron.reservas', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
        }
    }
} catch (\Throwable $e) {
    db_reanudar();
    $lineas[] = 'reservas ERROR: ' . $e->getMessage();
    log_app('cron.reservas_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}

// ---- 3b. Recordatorios de reuniones ----
// Correo al invitado de cada reserva confirmada que empieza en las próximas
// 24 h y todavía no tiene `recordatorio_enviado_en`.
// A diferencia de los otros pasos, este y los siguientes no llaman a
// db_reanudar() en el catch: si la conexión se hubiera caído, el log_app()
// del error fallaría también y el error no quedaría registrado.
try {
    $n = agenda_recordatorios();
    $lineas[] = "agenda: $n recordatorios enviados";
    if ($n) {
        log_app('cron.agenda', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
    }
} catch (\Throwable $e) {
    $lineas[] = 'agenda ERROR: ' . $e->getMessage();
    log_app('cron.agenda_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}

// ---- 3c. Feriados ----
// Una vez por día (la primera corrida del día, o cuando lo sincronizado tiene
// más de 24 h) se trae el calendario oficial del año y del siguiente. Va antes
// de las alertas: si un feriado nuevo cae sobre una reunión, el aviso sale por
// correo en esta misma corrida. Si la fuente no responde, la agenda sigue con
// lo que ya tenía y se vuelve a probar en un rato.
// feriados_asegurar(true) devuelve null si todavía no tocaba sincronizar; si
// tocó, los años al día, los feriados agregados y quitados, y 'error' si la
// fuente falló. Un error de la fuente NO se registra en `actividad`: queda en
// el ajuste `feriados_ultimo_error` y en la pantalla de feriados.
try {
    if (function_exists('feriados_asegurar')) {
        $r = feriados_asegurar(true);
        if ($r !== null) {
            $lineas[] = $r['error'] && !$r['anios']
                ? 'feriados: sin actualizar (' . $r['error'] . ')'
                : sprintf('feriados: %s al día, %d nuevos, %d quitados', implode(' y ', $r['anios']),
                          count($r['agregados']), count($r['quitados']));
        }
    }
} catch (\Throwable $e) {
    $lineas[] = 'feriados ERROR: ' . $e->getMessage();
    log_app('cron.feriados_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}

// ---- 4. Alertas por correo ----
// Se agrupan en un solo correo por corrida: cinco reuniones agendadas en diez
// minutos son cinco alertas en pantalla, pero un solo correo. Cinco correos
// seguidos se leen como spam y se terminan ignorando.
try {
    $n = notif_enviar_pendientes();
    $lineas[] = "alertas: $n avisadas por correo";
    if ($n) {
        log_app('cron.alertas', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
    }
} catch (\Throwable $e) {
    $lineas[] = 'alertas ERROR: ' . $e->getMessage();
    log_app('cron.alertas_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}

// ---- 5. Purga de la papelera ----
// Es el único lugar del sistema que borra físicamente, y sólo lo que ya cumplió
// su plazo. Se corre una vez por día para no castigar la base en cada pasada.
//
// La marca del día se escribe ANTES de purgar: si purgar_papelera() falla a
// la mitad, ese día no se reintenta y lo que faltaba se borra al día
// siguiente. Es a propósito del lado seguro (nunca dos purgas el mismo día),
// pero un error acá se ve sólo en `actividad`.
// date() usa la zona horaria de PHP (`app.timezone` de la configuración, UTC
// si falta): «el día» cambia a la medianoche de esa zona. El plazo de la
// purga, en cambio, lo calcula la base con NOW(), en la zona de MariaDB.
try {
    if (date('Y-m-d') !== (string) setting_get('papelera_ultima_purga', '')) {
        setting_set('papelera_ultima_purga', date('Y-m-d'));
        $borrados = purgar_papelera();
        // Los adjuntos van aparte: además de la fila, se borra el archivo del
        // disco (adjuntos_purgar en registros.php).
        $archivos = adjuntos_purgar();
        if ($archivos > 0) { $borrados['archivos'] = $archivos; }
        $total = array_sum($borrados);
        $lineas[] = 'papelera: ' . ($total
            ? $total . ' eliminados definitivamente tras ' . dias_papelera() . ' días ('
              . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($borrados), $borrados)) . ')'
            : 'nada para purgar');
    } else {
        $lineas[] = 'papelera: ya se purgó hoy';
    }
} catch (\Throwable $e) {
    $lineas[] = 'papelera ERROR: ' . $e->getMessage();
    log_app('cron.papelera_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}

// ---- 6. Entradas desde la web ----
// Una vez por día, como la papelera:
//   · las entradas viejas sin contacto (se purgó de la papelera) o que
//     quedaron con error pierden los datos personales; el canal, la página y
//     la fecha quedan, que son los números del embudo (Ley 25.326: no se
//     guarda lo que ya no hace falta);
//   · se borran los contadores de límites ya vencidos.
// Igual que en la papelera, la marca del día se pone antes de trabajar: si
// algo falla, se reintenta recién mañana. El plazo de anonimización es el
// mismo de la papelera (`papelera_dias`, 60 por defecto).
try {
    if (function_exists('entradas_web_anonimizar')
        && date('Y-m-d') !== (string) setting_get('entradas_web_ultima_limpieza', '')) {
        setting_set('entradas_web_ultima_limpieza', date('Y-m-d'));
        $anon  = entradas_web_anonimizar((int) setting_get('papelera_dias', '60') ?: 60);
        $lims  = q('DELETE FROM api_limites WHERE vence_en < NOW()')->rowCount();
        $lineas[] = "entradas web: $anon anonimizadas, $lims contadores vencidos";
        if ($anon) {
            log_app('cron.entradas_web', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
        }
    }
} catch (\Throwable $e) {
    // Sin la migración 008 todavía no hay tablas: no es un error del cron.
    if (!($e instanceof \PDOException && $e->getCode() === '42S02')) {
        $lineas[] = 'entradas web ERROR: ' . $e->getMessage();
        log_app('cron.entradas_web_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
    }
}

// Fin de la corrida: la pantalla CRON pasa a mostrar esta hora y deja de
// mostrar «en curso». El turno y el GET_LOCK los sueltan las funciones de
// cierre registradas arriba, cuando termina el script.
setting_set('cron_ultima_corrida', date('Y-m-d H:i:s'));
setting_set('cron_en_curso_desde', '');

$salida = date('c') . ' · ' . implode(' | ', $lineas)
        . sprintf(' | %.1f s', microtime(true) - $t0) . PHP_EOL;

if ($porWeb) {
    echo $salida;
} else {
    fwrite(STDOUT, $salida);
}

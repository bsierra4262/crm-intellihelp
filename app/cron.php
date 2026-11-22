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
//  Cada paso va en su propio try/catch: si uno falla, se reabre la conexión
//  (por si lo que falló fue ella), se anota en la salida y en `actividad`
//  (tipo 'error', acción 'cron.<paso>_error') y la corrida sigue con el
//  siguiente. La salida del cron de hPanel va a /dev/null, así que esos
//  registros en `actividad` (pantalla CRON) son el único rastro de un error:
//  nadie recibe un aviso.
//
//  Ajustes que lee y escribe: `cron_clave` (para la llamada por URL),
//  `cron_turno`, `cron_en_curso_desde`, `cron_ultima_corrida`,
//  `papelera_ultima_purga`, `papelera_intento_en`,
//  `entradas_web_ultima_limpieza`, `entradas_web_intento_en`, `papelera_dias`.
//
//  Es seguro correrlo muchas veces: todo es idempotente y nada se duplica.
// ============================================================

// ---- Funciones propias del cron ----
// Están arriba y sin efectos para poder probarlas (test/casos/e2.php define
// CRON_SOLO_FUNCIONES antes de incluir el archivo y se queda sólo con ellas).

/**
 * ¿La clave que llegó por URL o cabecera es la configurada?
 *
 * Sin clave configurada se niega siempre: por defecto la vía web está
 * cerrada. hash_equals compara en tiempo constante, así que no se puede ir
 * adivinando la clave carácter por carácter midiendo cuánto tarda la
 * respuesta.
 */
function cron_clave_valida(string $configurada, string $recibida): bool
{
    return $configurada !== '' && hash_equals($configurada, $recibida);
}

/**
 * ¿Toca hacer hoy una tarea diaria (purga, limpieza de entradas web)?
 *
 * La marca del día se escribe recién cuando la tarea terminó bien. Si falló,
 * se reintenta, pero no en cada corrida (serían 288 errores por día en la
 * auditoría): se espera `$esperaSeg` desde el último intento.
 *
 * @param string $marca   fecha (AAAA-MM-DD) del último día en que terminó bien
 * @param string $intento timestamp Unix del último intento, '' si no hubo
 * @param string $hoy     fecha de hoy según la base (CURDATE())
 * @param int    $ahora   timestamp Unix actual
 */
function cron_toca_diario(string $marca, string $intento, string $hoy, int $ahora, int $esperaSeg = 3600): bool
{
    if ($marca === $hoy) { return false; }
    return $intento === '' || $ahora - (int) $intento >= $esperaSeg;
}

if (defined('CRON_SOLO_FUNCIONES')) { return; }

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

// Por URL: la clave va en la cabecera `X-Cron-Clave` (preferible: no queda
// en los registros de acceso del servidor) o, si el panel sólo permite una
// dirección, en /cron.php?clave=<cron_clave> (esa sí queda registrada).
if ($porWeb) {
    $recibida = (string) ($_SERVER['HTTP_X_CRON_CLAVE'] ?? ($_GET['clave'] ?? ''));
    if (!cron_clave_valida((string) setting_get('cron_clave', ''), $recibida)) {
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
// Los bloqueos con nombre de MariaDB son de TODO el servidor, no de cada
// base, y producción y pruebas comparten el servidor. Por eso el nombre
// lleva la base adelante (como los de agenda.php y feriados.php): con un
// nombre fijo, la corrida de una instalación bloqueaba a la de la otra y, si
// las dos estaban programadas a la misma hora, una se salteaba entera.
if (!qv("SELECT GET_LOCK(CONCAT(DATABASE(), ':cron'), 0)")) {
    $msg = 'Ya hay una corrida en curso. Esta se saltea para no duplicar envíos.';
    if ($porWeb) { echo $msg . PHP_EOL; } else { fwrite(STDOUT, $msg . PHP_EOL); }
    exit(0);
}
// El bloqueo se suelta solo al cerrar la conexión, pero se libera explícito
// por si el proceso sigue vivo. Si la conexión se reabrió por el camino, el
// bloqueo ya se había perdido con la vieja y esto no hace nada.
register_shutdown_function(static function (): void {
    try { qv("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cron'))"); } catch (\Throwable $e) {}
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
// retoma después de ese plazo. Para que una corrida lenta (la de 280 s más
// pasos que esperan a Google o a la fuente de feriados) no pierda el turno
// estando viva, cron_renovar_turno() lo vuelve a fechar después de cada
// vuelta y de cada paso.
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

/**
 * Vuelve a fechar el turno tomado, para que no venza mientras esta corrida
 * sigue trabajando. Sólo lo toca si sigue siendo un turno tomado (distinto
 * de '0'): no toma un turno que alguien soltó. Un error acá no corta nada.
 */
function cron_renovar_turno(): void
{
    try {
        q("UPDATE app_settings SET valor = ? WHERE clave = 'cron_turno' AND valor <> '0' AND valor <> ''",
          [(string) time()]);
    } catch (\Throwable $e) {
        // Sin conexión: el paso siguiente la reabre.
    }
}

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
//  presupuesto: cada corrida da siempre unas cinco vueltas (280 s), esté el
//  cron cada uno o cada cinco minutos. Con el cron cada minuto, las corridas
//  que arrancan mientras otra sigue viva encuentran el bloqueo o el turno
//  tomado y se van sin hacer nada. En los dos casos la casilla se mira
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
    cron_renovar_turno();
} while (microtime(true) < $finDeCorrida);

if ($vueltas > 1) { $lineas[] = $vueltas . ' vueltas'; }
cron_renovar_turno();

// «Hoy» para las tareas diarias (pasos 5 y 6) lo dice la base, igual que
// los plazos que esas tareas calculan con NOW(): así la marca del día y el
// plazo usan siempre el mismo reloj. db.php ya alinea la zona de la sesión
// de MariaDB con la de PHP al conectar, de modo que coincide con date(); si
// la consulta fallara, se cae a date().
try {
    $hoy = (string) qv('SELECT CURDATE()');
} catch (\Throwable $e) {
    db_reanudar();
    $hoy = date('Y-m-d');
}

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
cron_renovar_turno();

// ---- 3b. Recordatorios de reuniones ----
// Correo al invitado de cada reserva confirmada que empieza en las próximas
// 24 h y todavía no tiene `recordatorio_enviado_en`.
// Como en todos los pasos, el catch reabre la conexión antes de registrar:
// si la que se cayó fue ella, el log_app() del error fallaría también y el
// error no quedaría registrado.
try {
    $n = agenda_recordatorios();
    $lineas[] = "agenda: $n recordatorios enviados";
    if ($n) {
        log_app('cron.agenda', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
    }
} catch (\Throwable $e) {
    db_reanudar();
    $lineas[] = 'agenda ERROR: ' . $e->getMessage();
    log_app('cron.agenda_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}
cron_renovar_turno();

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
    db_reanudar();
    $lineas[] = 'feriados ERROR: ' . $e->getMessage();
    log_app('cron.feriados_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}
cron_renovar_turno();

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
    db_reanudar();
    $lineas[] = 'alertas ERROR: ' . $e->getMessage();
    log_app('cron.alertas_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}
cron_renovar_turno();

// ---- 5. Purga de la papelera ----
// Es el único lugar del sistema que borra físicamente, y sólo lo que ya cumplió
// su plazo. Se corre una vez por día para no castigar la base en cada pasada.
//
// La marca del día (`papelera_ultima_purga`) se escribe DESPUÉS de purgar,
// sólo si salió bien. Si purgar_papelera() falla, el error queda en
// `actividad` y se reintenta una hora más tarde (`papelera_intento_en`), no
// recién mañana. Purgar dos veces el mismo día no hace daño: la segunda sólo
// encuentra lo que haya cumplido el plazo en el medio.
// «Hoy» es $hoy, la fecha de la base (ver arriba), el mismo reloj con el que
// la purga calcula el plazo con NOW().
try {
    if (cron_toca_diario((string) setting_get('papelera_ultima_purga', ''),
                         (string) setting_get('papelera_intento_en', ''), $hoy, time())) {
        setting_set('papelera_intento_en', (string) time());
        $borrados = purgar_papelera();
        setting_set('papelera_ultima_purga', $hoy);
        setting_set('papelera_intento_en', '');
        $total = array_sum($borrados);
        $lineas[] = 'papelera: ' . ($total
            ? $total . ' eliminados definitivamente tras ' . dias_papelera() . ' días ('
              . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($borrados), $borrados)) . ')'
            : 'nada para purgar');
    } elseif ((string) setting_get('papelera_ultima_purga', '') === $hoy) {
        $lineas[] = 'papelera: ya se purgó hoy';
    } else {
        $lineas[] = 'papelera: falló hace menos de una hora, se reintenta después';
    }
} catch (\Throwable $e) {
    db_reanudar();
    $lineas[] = 'papelera ERROR: ' . $e->getMessage();
    log_app('cron.papelera_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}
cron_renovar_turno();

// ---- 6. Entradas desde la web ----
// Una vez por día, como la papelera:
//   · las entradas viejas sin contacto (se purgó de la papelera) o que
//     quedaron con error pierden los datos personales; el canal, la página y
//     la fecha quedan, que son los números del embudo (Ley 25.326: no se
//     guarda lo que ya no hace falta);
//   · se borran los contadores de límites ya vencidos.
// Igual que en la papelera, la marca del día se pone cuando terminó bien; si
// algo falla, se reintenta una hora después (`entradas_web_intento_en`). El
// plazo de anonimización es el mismo de la papelera (`papelera_dias`, 60 por
// defecto).
try {
    if (function_exists('entradas_web_anonimizar')
        && cron_toca_diario((string) setting_get('entradas_web_ultima_limpieza', ''),
                            (string) setting_get('entradas_web_intento_en', ''), $hoy, time())) {
        setting_set('entradas_web_intento_en', (string) time());
        $anon  = entradas_web_anonimizar((int) setting_get('papelera_dias', '60') ?: 60);
        $lims  = q('DELETE FROM api_limites WHERE vence_en < NOW()')->rowCount();
        setting_set('entradas_web_ultima_limpieza', $hoy);
        setting_set('entradas_web_intento_en', '');
        $lineas[] = "entradas web: $anon anonimizadas, $lims contadores vencidos";
        if ($anon) {
            log_app('cron.entradas_web', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
        }
    }
} catch (\Throwable $e) {
    // Sin la migración 008 todavía no hay tablas: no es un error del cron.
    db_reanudar();
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

<?php
declare(strict_types=1);

// ============================================================
//  Tareas periódicas del CRM.
//
//  Agendar en el cron de Hostinger (hPanel), cada 5 minutos:
//      */5 * * * * /usr/bin/php /home/u566451014/domains/intellihelp.tech/public_html/crm/cron.php >/dev/null 2>&1
//
//  Hace cuatro cosas, en este orden:
//    1. despacha los correos pendientes de las campañas (con su pausa)
//    2. lee la casilla: rebotes y respuestas (los rebotes definitivos archivan
//       el contacto, porque ya no se le puede escribir)
//    3. manda los recordatorios de las reuniones de las próximas 24 h
//    4. purga lo que lleva más de 60 días en la papelera
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

$t0     = microtime(true);
$lineas = [];

// Se deja anotado que la corrida ARRANCÓ, no sólo cuándo terminó. Una corrida
// que manda correos dura varios minutos, y mientras tanto la pantalla mostraba
// la hora de la corrida anterior: parecía que el cron estaba parado justo
// cuando más trabajando estaba.
// Una sola corrida a la vez. Corriendo cada minuto, la anterior puede seguir
// trabajando cuando arranca la siguiente: dos a la vez leen la misma casilla
// dos veces y cuentan de más. El que no toma el turno se va sin hacer ruido.
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
    // estaban pasando de verdad.
    //
    // Cada cuánto se mira depende de si hay algo en curso: con una campaña
    // mandando, los rebotes vuelven a los segundos y las respuestas empiezan a
    // entrar enseguida, así que se mira seguido. Sin nada en curso se espacía,
    // porque abrir IMAP es caro y no tiene sentido hacerlo para no encontrar nada.
    // El despachador además vuelve a mirar en el medio del envío, aprovechando las
    // pausas entre correo y correo.
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
        // El presupuesto sale de la constante, no de un número escrito acá: de él
        // depende el ritmo que la pantalla le promete al usuario, y los dos tienen
        // que ser el mismo número o la estimación miente.
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

// ---- 3. Recordatorios de reuniones ----
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
// Es el ÚNICO lugar del sistema que borra de verdad, y sólo lo que ya cumplió
// su plazo. Se corre una vez por día para no castigar la base en cada pasada.
try {
    if (date('Y-m-d') !== (string) setting_get('papelera_ultima_purga', '')) {
        setting_set('papelera_ultima_purga', date('Y-m-d'));
        $borrados = purgar_papelera();
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

setting_set('cron_ultima_corrida', date('Y-m-d H:i:s'));
setting_set('cron_en_curso_desde', '');

$salida = date('c') . ' · ' . implode(' | ', $lineas)
        . sprintf(' | %.1f s', microtime(true) - $t0) . PHP_EOL;

if ($porWeb) {
    echo $salida;
} else {
    fwrite(STDOUT, $salida);
}

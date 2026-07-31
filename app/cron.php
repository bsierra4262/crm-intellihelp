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

// Una sola corrida a la vez. El encabezado de este archivo prometía que era
// seguro correrlo muchas veces, pero no había ningún bloqueo: dos corridas
// solapadas leían los mismos envíos pendientes y los mandaban las dos, así que
// el destinatario recibía el correo duplicado. Pasa fácil, porque además del
// cron cada 5 minutos se puede disparar por URL con la clave de Ajustes.
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

// ---- 1. Campañas ----
try {
    // 240 s de presupuesto: el cron corre cada 5 minutos, así que la corrida
    // termina con margen antes de que se dispare la siguiente. Con la pausa de
    // 25-45 s eso da unos 6 a 9 correos por corrida y campaña: entre 80 y 100
    // por hora, que es el ritmo que anuncia la pantalla.
    $r = campanas_despachar(3, 240);
    $lineas[] = sprintf('campañas: %d enviados, %d rebotados, %d omitidos, %d errores',
        $r['enviados'], $r['rebotados'], $r['omitidos'], $r['errores']);
    if ($r['enviados'] || $r['rebotados'] || $r['errores']) {
        log_app('cron.campanas', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
    }
} catch (\Throwable $e) {
    $lineas[] = 'campañas ERROR: ' . $e->getMessage();
    log_app('cron.campanas_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}

// ---- 2. Casilla: rebotes y respuestas ----
// Se lee cada 15 minutos, no en cada corrida: abrir IMAP es caro y los rebotes
// no llegan al segundo.
try {
    $ultima = setting_get('imap_ultima_corrida', '0');
    if (time() - (int)$ultima >= 900) {
        setting_set('imap_ultima_corrida', (string)time());
        // Todas las casillas activas, no sólo una: si una campaña sale por la
        // cuenta de RRHH, sus rebotes llegan a la casilla de RRHH.
        $r = imap_revisar_todas();
        $lineas[] = $r['error']
            ? 'casillas: ' . $r['error']
            : sprintf('casillas: %d revisadas, %d correos, %d rebotes (%d definitivos), %d respuestas',
                      $r['casillas'] ?? 1, $r['revisados'], $r['rebotes'], $r['duros'] ?? 0, $r['respuestas']);
        if (($r['rebotes'] ?? 0) || ($r['respuestas'] ?? 0)) {
            log_app('cron.casilla', ['tipo' => 'sistema', 'actor' => 'cron', 'detalle' => end($lineas)]);
        }
    } else {
        $lineas[] = 'casilla: se salteó (se revisa cada 15 min)';
    }
} catch (\Throwable $e) {
    $lineas[] = 'casilla ERROR: ' . $e->getMessage();
    log_app('cron.casilla_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => $e->getMessage()]);
}

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

// ---- 4. Purga de la papelera ----
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

$salida = date('c') . ' · ' . implode(' | ', $lineas)
        . sprintf(' | %.1f s', microtime(true) - $t0) . PHP_EOL;

if ($porWeb) {
    echo $salida;
} else {
    fwrite(STDOUT, $salida);
}

<?php
declare(strict_types=1);
require_login();

// Estado de las campañas en curso, para que la pantalla se actualice sola.
//
// Una campaña manda un correo cada 25-45 segundos y una corrida dura varios
// minutos: sin esto había que recargar la página a mano para ver si avanzaba,
// y mirando una pantalla quieta parece que no está pasando nada.
//
// Los números se cuentan sobre la cola en el momento, igual que en la pantalla:
// es la única fuente que no puede quedar vieja.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = (int)($_GET['id'] ?? 0);

/** Cifras de una campaña, contadas sobre la cola. */
$cifras = static function (array $c): array {
    $total = (int)$c['total'];
    return [
        'id'          => (int)$c['id'],
        'estado'      => (string)$c['estado'],
        'total'       => $total,
        'enviados'    => (int)$c['enviados'],
        'rebotados'   => (int)$c['rebotados'],
        'respondidos' => (int)$c['respondidos'],
        'errores'     => (int)($c['errores'] ?? 0),
        'reuniones'   => (int)($c['reuniones'] ?? 0),
        'aperturas'   => (int)($c['aperturas'] ?? 0),
        'pct'         => $total > 0 ? min(100, (int) round(100 * (int)$c['enviados'] / $total)) : 0,
        // Mientras haya algo por salir conviene seguir mirando; si terminó, no.
        'sigue'       => in_array($c['estado'], ['enviando', 'programada'], true),
    ];
};

if ($id > 0) {
    $c = campana_get($id);
    if (!$c) { json_out(['ok' => false], 404); }

    // El estado de cada destinatario, para actualizar la tabla sin recargar.
    $filas = [];
    foreach (q('SELECT id, estado, enviado_en, respondido_en FROM envios WHERE campana_id = ?', [$id])->fetchAll() as $e) {
        $filas[] = [
            'id'        => (int)$e['id'],
            'estado'    => (string)$e['estado'],
            'badge'     => badge_envio((string)$e['estado']),
            'enviado'   => $e['enviado_en'] ? fecha_hora($e['enviado_en']) : '—',
            'respondio' => $e['respondido_en'] ? fecha_hora($e['respondido_en']) : '—',
        ];
    }
    json_out(['ok' => true, 'campana' => $cifras($c), 'filas' => $filas,
              'cron' => setting_get('cron_en_curso_desde', '') !== '']);
}

// Sin id: las campañas en curso, para el panel.
$out = [];
foreach (campanas_listar(['estado' => ''])['rows'] as $c) {
    if (!in_array($c['estado'], ['enviando', 'programada', 'pausada'], true)) { continue; }
    $out[] = $cifras($c) + ['nombre' => (string)$c['nombre']];
}
json_out(['ok' => true, 'campanas' => $out,
          'cron' => setting_get('cron_en_curso_desde', '') !== '']);

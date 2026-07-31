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
        // El avance mide lo RESUELTO —entregados más rebotados más fallidos—,
        // no sólo lo entregado. Una campaña que entregó 3 y rebotó 3 terminó su
        // trabajo con los 6, y la barra tiene que decir eso.
        'procesados'  => (int)($c['procesados'] ?? $c['enviados']),
        'pct'         => $total > 0
            ? min(100, (int) round(100 * (int)($c['procesados'] ?? $c['enviados']) / $total)) : 0,
        // El estado va dibujado desde el servidor: cambia el texto y el color a
        // la vez, sin que el navegador tenga que saber qué color le toca a cada
        // estado. Si eso se duplicara en el JavaScript, tarde o temprano los dos
        // lados dirían cosas distintas.
        'badge'       => badge_campana((string)$c['estado']),
        // «Sigue» sólo decide el RITMO de consulta, no si se deja de mirar: una
        // campaña terminada sigue recibiendo respuestas y reuniones agendadas
        // durante días, y ésos son justamente los números que importan.
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

// Sin id: TODAS las campañas de la primera página, no sólo las que están
// mandando. Una campaña terminada sigue sumando respuestas y reuniones, y en el
// listado esos números tienen que moverse igual. El navegador actualiza sólo
// las filas que encuentra en pantalla, así que devolver de más no molesta.
$out = [];
foreach (campanas_listar(['estado' => ''])['rows'] as $c) {
    $out[] = $cifras($c) + ['nombre' => (string)$c['nombre']];
}
json_out(['ok' => true, 'campanas' => $out,
          'cron' => setting_get('cron_en_curso_desde', '') !== '']);

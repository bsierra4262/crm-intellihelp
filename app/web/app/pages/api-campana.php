<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: GET /api-campana[?id=N]  (con sesión; módulo «campanas»).
//  Responde JSON. La consultan cada seis segundos las pantallas de
//  campañas y de una campaña (atributos data-vivo, script en app.js).
//    · con id: las cifras de esa campaña y el estado de CADA destinatario;
//    · sin id: las cifras de las campañas de la primera página del listado.
//  Lee campanas y envios (vía campana_get / campanas_listar) y el ajuste
//  `cron_en_curso_desde` que escribe app/cron.php mientras corre.
//  No chequea rol ni propietario: cualquier usuario ve cualquier campaña.
//  Ojo con el rendimiento: con id devuelve la cola entera sin paginar, y
//  cada fila de campanas_listar lleva siete subconsultas sobre envios.
// ============================================================
//
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

/**
 * Cifras de una campaña, contadas sobre la cola.
 * Recibe una fila de campana_get() o campanas_listar(); `reuniones` sale de
 * la columna de la tabla (la suma agenda.php).
 */
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
    // Es TODA la cola, aunque la pantalla muestre de a 50: con miles de
    // destinatarios cada consulta trae miles de filas.
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

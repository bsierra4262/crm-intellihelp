<?php
declare(strict_types=1);

// ============================================================
//  Ruta: GET /reportes?periodo=AAAA-MM (requiere sesión; módulo Reportes).
//
//  Arma los bloques de la pantalla:
//    · pronóstico del mes por vendedor (pronostico() de negocios.php);
//    · embudo por etapa, con todos los negocios no eliminados (es una foto
//      de hoy, no depende del período);
//    · fuentes de los negocios ganados (cerrados) en el período;
//    · rendimiento de las campañas que tuvieron envíos en el período (los
//      números de cada campaña son sus totales);
//    · actividades completadas en el período, por usuario activo.
//  Los importes van por moneda, sin convertir (pesos y dólares por separado);
//  las barras miden cantidad de negocios, que sí se puede comparar.
//  Vista: views/reportes.php.
// ============================================================
require_login();

// El período se valida con formato estricto; si no, el mes actual.
$periodo = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['periodo'] ?? '')) ? $_GET['periodo'] : date('Y-m');
$desde   = $periodo . '-01';
$hasta   = date('Y-m-t', strtotime($desde));

// Embudo: cuántos negocios hay en cada etapa y cuánta plata, por moneda.
$etapas = q("SELECT e.id, e.nombre, e.color, e.tipo, COUNT(n.id) n
             FROM etapas e
             LEFT JOIN negocios n ON n.etapa_id = e.id AND n.eliminado_en IS NULL
             WHERE e.activo = 1 GROUP BY e.id, e.nombre, e.color, e.tipo, e.orden
             ORDER BY e.orden")->fetchAll();
$porEtapa = [];
foreach (q("SELECT etapa_id, moneda, COALESCE(SUM(importe),0) total FROM negocios
            WHERE eliminado_en IS NULL GROUP BY etapa_id, moneda")->fetchAll() as $f) {
    $porEtapa[(int)$f['etapa_id']][(string)$f['moneda'] ?: 'ARS'] = (float)$f['total'];
}
foreach ($etapas as &$e) { $e['totales'] = $porEtapa[(int)$e['id']] ?? []; }
unset($e);

// De dónde vienen los negocios que se ganaron en el período.
$fuentes = [];
foreach (q("SELECT COALESCE(n.fuente, 'Sin definir') fuente, n.moneda, COUNT(*) n,
                   COALESCE(SUM(n.importe),0) total
            FROM negocios n JOIN etapas e ON e.id = n.etapa_id
            WHERE n.eliminado_en IS NULL AND e.tipo = 'ganada' AND n.cerrado_en BETWEEN ? AND ?
            GROUP BY COALESCE(n.fuente, 'Sin definir'), n.moneda",
           [$desde . ' 00:00:00', $hasta . ' 23:59:59'])->fetchAll() as $f) {
    $k = (string)$f['fuente'];
    $fuentes[$k] ??= ['fuente' => $k, 'n' => 0, 'totales' => []];
    $fuentes[$k]['n'] += (int)$f['n'];
    $fuentes[$k]['totales'][(string)$f['moneda'] ?: 'ARS'] = (float)$f['total'];
}
usort($fuentes, fn($a, $b) => $b['n'] <=> $a['n']);
$fuentes = array_slice($fuentes, 0, 12);

render('reportes', [
    'periodo'    => $periodo,
    'pronostico' => pronostico($periodo),
    'etapas'     => $etapas,
    'fuentes'    => $fuentes,
    // Las campañas que enviaron algo en el período, con sus totales.
    'campanas'   => q("SELECT c.nombre, c.total, c.enviados, c.respondidos, c.rebotados, c.reuniones
                       FROM campanas c
                       WHERE c.eliminado_en IS NULL AND c.enviados > 0
                         AND EXISTS (SELECT 1 FROM envios v WHERE v.campana_id = c.id
                                     AND v.enviado_en BETWEEN ? AND ?)
                       ORDER BY c.enviados DESC LIMIT 10",
                      [$desde . ' 00:00:00', $hasta . ' 23:59:59'])->fetchAll(),
    'actividad'  => q("SELECT u.nombre, COUNT(a.id) n
                       FROM usuarios u LEFT JOIN actividades a
                            ON a.propietario_id = u.id AND a.eliminado_en IS NULL
                            AND a.completada_en BETWEEN ? AND ?
                       WHERE u.activo = 1 GROUP BY u.id, u.nombre ORDER BY n DESC",
                      [$desde . ' 00:00:00', $hasta . ' 23:59:59'])->fetchAll(),
], 'Reportes');

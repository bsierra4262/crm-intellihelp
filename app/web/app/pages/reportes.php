<?php
declare(strict_types=1);
require_login();

$periodo = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['periodo'] ?? '')) ? $_GET['periodo'] : date('Y-m');
$desde   = $periodo . '-01';
$hasta   = date('Y-m-t', strtotime($desde));

render('reportes', [
    'periodo'    => $periodo,
    'pronostico' => pronostico($periodo),
    // Embudo: cuántos negocios y cuánta plata hay en cada etapa.
    'etapas'     => q("SELECT e.nombre, e.color, e.tipo, COUNT(n.id) n, COALESCE(SUM(n.importe),0) total
                       FROM etapas e
                       LEFT JOIN negocios n ON n.etapa_id = e.id AND n.eliminado_en IS NULL
                       WHERE e.activo = 1 GROUP BY e.id, e.nombre, e.color, e.tipo, e.orden
                       ORDER BY e.orden")->fetchAll(),
    // De dónde vienen los negocios que se ganan.
    'fuentes'    => q("SELECT COALESCE(n.fuente, 'Sin definir') fuente, COUNT(*) n,
                              COALESCE(SUM(n.importe),0) total
                       FROM negocios n JOIN etapas e ON e.id = n.etapa_id
                       WHERE n.eliminado_en IS NULL AND e.tipo = 'ganada'
                       GROUP BY fuente ORDER BY total DESC LIMIT 12")->fetchAll(),
    'campanas'   => q("SELECT nombre, total, enviados, respondidos, rebotados, reuniones
                       FROM campanas WHERE eliminado_en IS NULL AND enviados > 0
                       ORDER BY enviados DESC LIMIT 10")->fetchAll(),
    'actividad'  => q("SELECT u.nombre, COUNT(a.id) n
                       FROM usuarios u LEFT JOIN actividades a
                            ON a.propietario_id = u.id AND a.eliminado_en IS NULL
                            AND a.completada_en BETWEEN ? AND ?
                       WHERE u.activo = 1 GROUP BY u.id, u.nombre ORDER BY n DESC",
                      [$desde . ' 00:00:00', $hasta . ' 23:59:59'])->fetchAll(),
], 'Reportes');

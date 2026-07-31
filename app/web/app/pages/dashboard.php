<?php
declare(strict_types=1);
require_login();

$uid = current_user_id();
$mio = ($_GET['ambito'] ?? 'mio') !== 'todos';   // por defecto, lo mío

// Filtro de propietario reutilizado en todas las consultas del panel.
$fProp = $mio ? ' AND propietario_id = ' . (int)$uid : '';

$m = [
    'contactos' => (int) qv("SELECT COUNT(*) FROM contactos WHERE eliminado_en IS NULL $fProp"),
    'cuentas'   => (int) qv("SELECT COUNT(*) FROM cuentas   WHERE eliminado_en IS NULL $fProp"),
    'posibles'  => (int) qv("SELECT COUNT(*) FROM posibles_clientes
                             WHERE eliminado_en IS NULL AND convertido_en IS NULL $fProp"),
    'sin_tocar' => (int) qv("SELECT COUNT(*) FROM posibles_clientes
                             WHERE eliminado_en IS NULL AND convertido_en IS NULL
                               AND estado = 'Sin contactar' $fProp"),
];

// Embudo abierto: cuántos negocios y cuánto pesan, ponderado por probabilidad.
$emb = q1("SELECT COUNT(*) n, COALESCE(SUM(n.importe),0) total, COALESCE(SUM(n.ingreso_esperado),0) ponderado
           FROM negocios n JOIN etapas e ON e.id = n.etapa_id
           WHERE n.eliminado_en IS NULL AND e.tipo = 'abierta'"
          . ($mio ? ' AND n.propietario_id = ' . (int)$uid : '')) ?: [];

$ganadoMes = (float) qv("SELECT COALESCE(SUM(n.importe),0) FROM negocios n JOIN etapas e ON e.id = n.etapa_id
                         WHERE n.eliminado_en IS NULL AND e.tipo = 'ganada'
                           AND n.cerrado_en >= ?" . ($mio ? ' AND n.propietario_id = ' . (int)$uid : ''),
                        [date('Y-m-01 00:00:00')]);

// Agenda del día y pendientes vencidos.
$hoy = q("SELECT a.*, c.nombre_completo AS contacto
          FROM actividades a LEFT JOIN contactos c ON c.id = a.contacto_id
          WHERE a.eliminado_en IS NULL AND a.completada_en IS NULL
            AND ((a.inicia_el BETWEEN ? AND ?) OR (a.vence_el BETWEEN ? AND ?))"
        . ($mio ? ' AND a.propietario_id = ' . (int)$uid : '') . '
          ORDER BY COALESCE(a.inicia_el, a.vence_el) LIMIT 12',
        [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'), date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])->fetchAll();

$vencidas = (int) qv("SELECT COUNT(*) FROM actividades
                      WHERE eliminado_en IS NULL AND completada_en IS NULL
                        AND COALESCE(vence_el, inicia_el) < NOW()"
                    . ($mio ? ' AND propietario_id = ' . (int)$uid : ''));

// Campañas en curso.
$campanas = q("SELECT id, nombre, estado, total, enviados, rebotados, respondidos
               FROM campanas WHERE eliminado_en IS NULL AND estado IN ('enviando','programada','pausada')
               ORDER BY actualizado_en DESC LIMIT 5")->fetchAll();

render('dashboard', compact('m', 'emb', 'ganadoMes', 'hoy', 'vencidas', 'campanas', 'mio'), 'Panel');

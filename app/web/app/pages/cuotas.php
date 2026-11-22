<?php
declare(strict_types=1);
require_admin();

// ============================================================
//  Ruta `cuotas` (sólo administradores): objetivos de venta por mes.
//
//  GET  cuotas?periodo=AAAA-MM  → la tabla de vendedores activos con su
//       objetivo, lo ganado en el mes y el porcentaje de cumplimiento.
//  POST                         → guarda los objetivos del período: un
//       monto mayor que cero se inserta o actualiza, vacío o cero borra la fila.
//
//  Tabla: cuotas (única por usuario y período); lee negocios y etapas.
//  Módulo «negocios». Vista: views/cuotas.php.
// ============================================================
//
// Objetivos de venta por persona y por mes.
//
// La columna «Objetivo» del pronóstico de Reportes lee de la tabla `cuotas`, y
// no existía ninguna pantalla que la escribiera: la columna mostraba siempre un
// guión y el porcentaje de cumplimiento nunca se podía calcular.

$periodo = (string)($_GET['periodo'] ?? date('Y-m'));
// El formato se valida pero no el mes: «2026-13» pasa la expresión y después
// strtotime() devuelve false (ver auditoría).
if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) { $periodo = date('Y-m'); }

$aviso = null;

if (is_post()) {
    verify_csrf();
    $periodo = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['periodo'] ?? ''))
             ? (string)$_POST['periodo'] : date('Y-m');
    $n = 0;
    foreach ((array)($_POST['objetivo'] ?? []) as $usuarioId => $valor) {
        $usuarioId = (int)$usuarioId;
        // Se acepta «1.500.000» y «1500000,50»: es como se escribe un importe acá.
        // Ojo: el campo de la vista es type="number" y el navegador manda punto
        // decimal («1500000.5»); al sacar los puntos ese valor queda multiplicado.
        $limpio = str_replace(['.', ' '], '', trim((string)$valor));
        $limpio = str_replace(',', '.', $limpio);
        $monto  = $limpio === '' ? 0.0 : (float)$limpio;

        if ($monto > 0) {
            // La moneda se guarda siempre en ARS, aunque lo ganado sume negocios en USD.
            q('INSERT INTO cuotas (usuario_id, periodo, objetivo, moneda) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE objetivo = VALUES(objetivo)',
              [$usuarioId, $periodo, $monto, 'ARS']);
            $n++;
        } else {
            // Sin objetivo no se guarda un cero: se borra la fila, así la
            // columna vuelve a mostrar un guión en vez de «0 %» de cumplimiento.
            q('DELETE FROM cuotas WHERE usuario_id = ? AND periodo = ?', [$usuarioId, $periodo]);
        }
    }
    log_app('cuotas.guardar', ['detalle' => 'Objetivos de ' . $periodo . ': ' . $n . ' cargados']);
    $aviso = ['success', 'Objetivos guardados para ' . $periodo . '.'];
}

$usuarios = users_activos();
$cargadas = [];
foreach (q('SELECT usuario_id, objetivo FROM cuotas WHERE periodo = ?', [$periodo])->fetchAll() as $r) {
    $cargadas[(int)$r['usuario_id']] = (float)$r['objetivo'];
}

// Lo ganado en el período, para comparar contra el objetivo sin salir de acá.
$ganado = [];
// Se compara por rango y no con DATE_FORMAT: la función devuelve un texto con
// otro cotejamiento que el del parámetro y MySQL se niega a compararlos. De
// paso, el rango puede usar el índice de la fecha en vez de recorrer la tabla.
// Lo ganado se cuenta por fecha ESTIMADA de cierre, igual que pronostico().
$desde = $periodo . '-01';
$hasta = date('Y-m-01', strtotime($desde . ' +1 month'));
foreach (q("SELECT n.propietario_id, SUM(n.importe) total
            FROM negocios n JOIN etapas e ON e.id = n.etapa_id
            WHERE n.eliminado_en IS NULL AND e.tipo = 'ganada'
              AND n.fecha_cierre >= ? AND n.fecha_cierre < ?
            GROUP BY n.propietario_id", [$desde, $hasta])->fetchAll() as $r) {
    $ganado[(int)$r['propietario_id']] = (float)$r['total'];
}

render('cuotas', compact('usuarios', 'cargadas', 'ganado', 'periodo', 'aviso'), 'Objetivos de venta');

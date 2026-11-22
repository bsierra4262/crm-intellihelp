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
// Formato y mes (01 a 12): «2026-13» vuelve al mes actual.
if (!periodo_valido($periodo)) { $periodo = date('Y-m'); }

$aviso = null;

if (is_post()) {
    verify_csrf();
    $periodo = periodo_valido((string)($_POST['periodo'] ?? '')) ? (string)$_POST['periodo'] : date('Y-m');
    $n = 0;
    foreach ((array)($_POST['objetivo'] ?? []) as $usuarioId => $valor) {
        $usuarioId = (int)$usuarioId;
        // importe_parsear() entiende tanto lo que manda el campo type="number"
        // («1500000.5») como lo escrito a mano («1.500.000» o «1500000,50»).
        $monto  = (float)(importe_parsear($valor) ?? 0.0);

        if ($monto > 0) {
            // La cuota es siempre en pesos; lo ganado en dólares se muestra aparte
            // y no cuenta para el cumplimiento.
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

// Lo ganado en el período, para comparar contra el objetivo sin salir de acá,
// separado por moneda: usuario_id => ['ARS' => …, 'USD' => …].
$ganado = [];
// Se compara por rango y no con DATE_FORMAT: la función devuelve un texto con
// otro cotejamiento que el del parámetro y MySQL se niega a compararlos. De
// paso, el rango puede usar el índice de la fecha en vez de recorrer la tabla.
// Lo ganado se cuenta por fecha ESTIMADA de cierre, igual que pronostico().
$desde = $periodo . '-01';
$hasta = date('Y-m-01', (int) strtotime($desde . ' +1 month'));
foreach (q("SELECT n.propietario_id, n.moneda, SUM(n.importe) total
            FROM negocios n JOIN etapas e ON e.id = n.etapa_id
            WHERE n.eliminado_en IS NULL AND e.tipo = 'ganada'
              AND n.fecha_cierre >= ? AND n.fecha_cierre < ?
            GROUP BY n.propietario_id, n.moneda", [$desde, $hasta])->fetchAll() as $r) {
    $uid = (int)$r['propietario_id'];
    $ganado[$uid] ??= array_fill_keys(NEGOCIO_MONEDAS, 0.0);
    $mon = in_array($r['moneda'], NEGOCIO_MONEDAS, true) ? $r['moneda'] : 'ARS';
    $ganado[$uid][$mon] += (float)$r['total'];
}

render('cuotas', compact('usuarios', 'cargadas', 'ganado', 'periodo', 'aviso'), 'Objetivos de venta');

<?php
declare(strict_types=1);
require_login();

$uid = current_user_id();

/**
 * Cómo quiere ver el panel esta persona.
 *
 * Separado: una tarjeta por cosa —contactos, empresas, y los pendientes de cada
 * una— para ver de un vistazo cuánto hay que aprobar de cada lado. Unificado:
 * los totales juntos, para quien prefiere menos tarjetas.
 *
 * Es una preferencia POR USUARIO y se guarda: si se perdiera al cerrar sesión,
 * habría que volver a elegirla todos los días.
 */
$vista = (string) setting_get('panel_vista_' . $uid, 'separado');
if (isset($_GET['vista']) && in_array($_GET['vista'], ['separado', 'unificado'], true)) {
    $vista = (string) $_GET['vista'];
    setting_set('panel_vista_' . $uid, $vista);
    redirect('dashboard' . (($_GET['ambito'] ?? '') === 'mio' ? '?ambito=mio' : ''));
}
$separado = $vista === 'separado';
// Por defecto se ve TODO. Arrancaba filtrando por «lo mío» y, con los
// contactos importados sin propietario asignado, el panel mostraba cero:
// parecía que la importación no había pasado. Lo general es lo que responde
// «cómo viene el sistema»; lo personal es un recorte que se pide.
$mio = ($_GET['ambito'] ?? 'todos') === 'mio';

// Filtro de propietario reutilizado en todas las consultas del panel.
$fProp = $mio ? ' AND propietario_id = ' . (int)$uid : '';

// Qué partes están en marcha. El panel no sólo esconde lo apagado: NO LO
// CONSULTA. Contar negocios, actividades y campañas para después no mostrarlas
// son cuatro consultas por visita que no le sirven a nadie, y el panel es la
// pantalla que más se abre en el día.
$verPosibles    = ruta_habilitada('posibles');
$verNegocios    = ruta_habilitada('embudo');
$verActividades = ruta_habilitada('actividades');
$verCampanas    = ruta_habilitada('campanas');

// Una sola consulta para los contadores del núcleo, en vez de una por número.
// Son cuatro recorridos de tabla que el motor resuelve junto, y el panel deja
// de hacer cuatro viajes a la base para dibujar la misma fila de tarjetas.
$m = q1("SELECT
          (SELECT COUNT(*) FROM contactos WHERE eliminado_en IS NULL $fProp) AS contactos,
          (SELECT COUNT(*) FROM cuentas   WHERE eliminado_en IS NULL $fProp) AS cuentas,
          (SELECT COUNT(*) FROM contactos WHERE eliminado_en IS NULL AND pendiente_norm = 1 $fProp) AS cont_pend,
          (SELECT COUNT(*) FROM cuentas   WHERE eliminado_en IS NULL AND pendiente_norm = 1 $fProp) AS cta_pend") ?: [];
$m = array_map('intval', $m);

if ($verPosibles) {
    $p = q1("SELECT COUNT(*) n,
                    SUM(estado = 'Sin contactar') sin_tocar
             FROM posibles_clientes
             WHERE eliminado_en IS NULL AND convertido_en IS NULL $fProp") ?: [];
    $m['posibles']  = (int)($p['n'] ?? 0);
    $m['sin_tocar'] = (int)($p['sin_tocar'] ?? 0);
}

// Embudo abierto: cuántos negocios y cuánto pesan, ponderado por probabilidad.
$emb = [];
$ganadoMes = 0.0;
if ($verNegocios) {
    $emb = q1("SELECT COUNT(*) n, COALESCE(SUM(n.importe),0) total, COALESCE(SUM(n.ingreso_esperado),0) ponderado
               FROM negocios n JOIN etapas e ON e.id = n.etapa_id
               WHERE n.eliminado_en IS NULL AND e.tipo = 'abierta'"
              . ($mio ? ' AND n.propietario_id = ' . (int)$uid : '')) ?: [];

    $ganadoMes = (float) qv("SELECT COALESCE(SUM(n.importe),0) FROM negocios n JOIN etapas e ON e.id = n.etapa_id
                             WHERE n.eliminado_en IS NULL AND e.tipo = 'ganada'
                               AND n.cerrado_en >= ?" . ($mio ? ' AND n.propietario_id = ' . (int)$uid : ''),
                            [date('Y-m-01 00:00:00')]);
}

// Agenda del día y pendientes vencidos.
$hoy = [];
$vencidas = 0;
if ($verActividades) {
    $hoy = q("SELECT a.id, a.tipo, a.asunto, a.inicia_el, a.vence_el, c.nombre_completo AS contacto
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
}

// Campañas en curso.
$campanas = $verCampanas
    ? q("SELECT id, nombre, estado, total, enviados, rebotados, respondidos
         FROM campanas WHERE eliminado_en IS NULL AND estado IN ('enviando','programada','pausada')
         ORDER BY actualizado_en DESC LIMIT 5")->fetchAll()
    : [];

render('dashboard', compact('m', 'emb', 'ganadoMes', 'hoy', 'vencidas', 'campanas', 'mio', 'separado',
                            'verPosibles', 'verNegocios', 'verActividades', 'verCampanas'), 'Panel');

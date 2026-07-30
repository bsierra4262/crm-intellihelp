<?php
declare(strict_types=1);

// ============================================================
//  API de sólo lectura para otros sistemas (ITHOS / Jarvis).
//  Autenticación por header X-API-Key o Authorization: Bearer.
// ============================================================

$clave = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($clave === '' && preg_match('/Bearer\s+(\S+)/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $clave = $m[1];
}

$token = $clave !== ''
    ? q1('SELECT * FROM api_tokens WHERE token_hash = ? AND activo = 1', [hash('sha256', $clave)])
    : null;

if (!$token) {
    log_api('api/estado', ['tipo' => 'auth', 'actor' => 'desconocido', 'detalle' => 'Acceso rechazado: clave inválida']);
    json_out(['ok' => false, 'error' => 'no_autorizado'], 401);
}

q('UPDATE api_tokens SET usos = usos + 1, ultimo_uso = NOW() WHERE id = ?', [(int)$token['id']]);
log_api('api/estado', ['actor' => $token['nombre'], 'detalle' => 'Consulta de estado del CRM']);

json_out([
    'ok'    => true,
    'fecha' => date('c'),
    'crm'   => [
        'contactos'        => (int) qv('SELECT COUNT(*) FROM contactos WHERE eliminado_en IS NULL'),
        'empresas'         => (int) qv('SELECT COUNT(*) FROM cuentas WHERE eliminado_en IS NULL'),
        'posibles_clientes'=> (int) qv('SELECT COUNT(*) FROM posibles_clientes WHERE eliminado_en IS NULL AND convertido_en IS NULL'),
        'negocios_abiertos'=> (int) qv("SELECT COUNT(*) FROM negocios n JOIN etapas e ON e.id = n.etapa_id
                                        WHERE n.eliminado_en IS NULL AND e.tipo = 'abierta'"),
        'embudo_abierto'   => (float) qv("SELECT COALESCE(SUM(n.importe),0) FROM negocios n JOIN etapas e ON e.id = n.etapa_id
                                          WHERE n.eliminado_en IS NULL AND e.tipo = 'abierta'"),
    ],
    'actividades' => [
        'pendientes' => (int) qv('SELECT COUNT(*) FROM actividades WHERE eliminado_en IS NULL AND completada_en IS NULL'),
        'vencidas'   => (int) qv('SELECT COUNT(*) FROM actividades WHERE eliminado_en IS NULL AND completada_en IS NULL
                                  AND COALESCE(vence_el, inicia_el) < NOW()'),
    ],
    'campanas' => [
        'activas'   => (int) qv("SELECT COUNT(*) FROM campanas WHERE eliminado_en IS NULL AND estado = 'enviando'"),
        'en_cola'   => (int) qv("SELECT COUNT(*) FROM envios WHERE estado = 'pendiente'"),
        'enviados_hoy' => (int) qv('SELECT COUNT(*) FROM envios WHERE DATE(enviado_en) = CURDATE()'),
    ],
    'agenda' => [
        'proximas_reuniones' => (int) qv("SELECT COUNT(*) FROM agenda_reservas
                                          WHERE estado = 'confirmada' AND inicia_el >= NOW()"),
    ],
    'cron_ultima_corrida' => setting_get('cron_ultima_corrida', null),
]);

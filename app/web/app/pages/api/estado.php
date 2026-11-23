<?php
declare(strict_types=1);

// ============================================================
//  API de sólo lectura para otros sistemas (ITHOS / Jarvis).
//  Autenticación por header X-API-Key o Authorization: Bearer.
//
//  Ruta: GET api/estado (pública en index.php: no usa sesión, la autentica
//  la clave). Devuelve contadores agregados del CRM, nunca datos de personas.
//  No pertenece a ningún módulo: cuenta también lo de módulos apagados.
//
//  A diferencia de la API v1 (app/api.php) no hay firma: alcanza con la clave
//  porque lo único que se expone son números. Sólo GET (405 con otro método)
//  y como mucho 60 pedidos por minuto por IP de origen (429): sin eso,
//  martillarla con claves falsas llenaba la auditoría, porque cada rechazo
//  deja su línea. Los rechazos se registran hasta 20 por origen cada 10
//  minutos; los aceptados, siempre. Responde con json_out() (helpers.php),
//  que termina el proceso.
//
//  Tablas: lee api_tokens (y suma `usos`), contactos, cuentas,
//  posibles_clientes, negocios/etapas, actividades, campanas, envios,
//  agenda_reservas y el ajuste `cron_ultima_corrida`.
// ============================================================

if (method() !== 'GET') {
    header('Allow: GET');
    json_out(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}
// Origen hasheado con el secreto de la instalación: la IP no se guarda.
$origen = (string)(ip_hash(ip_confiable()) ?? 'sin-ip');
if (limite_superado('estado:' . $origen . ':60', 60, 60)) {
    header('Retry-After: ' . limite_espera(60));
    json_out(['ok' => false, 'error' => 'demasiados_pedidos'], 429);
}

$clave = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($clave === '' && preg_match('/Bearer\s+(\S+)/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $clave = $m[1];
}

// Se busca por el SHA-256: en la base nunca está la clave en claro.
// Sólo las claves de lectura (y la root). La de la web es de escritura y no
// sirve sin su secreto: si se filtrara sola, con ella no se tiene que poder
// leer nada, tampoco estos contadores.
$token = $clave !== ''
    ? q1("SELECT * FROM api_tokens WHERE token_hash = ? AND activo = 1 AND scope IN ('lectura', 'root')",
         [hash('sha256', $clave)])
    : null;

if (!$token) {
    if (!limite_superado('estado-rechazo:' . $origen . ':600', 20, 600)) {
        log_api('api/estado', ['tipo' => 'auth', 'actor' => 'desconocido', 'detalle' => 'Acceso rechazado: clave inválida']);
    }
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

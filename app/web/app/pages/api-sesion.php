<?php
declare(strict_types=1);

/**
 * Latido de sesión. Lo llama idle.js ante actividad real, como mucho una vez
 * por minuto, para que la sesión no venza mientras se trabaja en una pantalla
 * que no recarga.
 *
 * El trabajo pesado ya lo hizo bootstrap.php en esta misma request: si la
 * sesión estaba vencida respondió 440 y nunca se llegó hasta acá; si estaba
 * viva, ya corrió «ultima_actividad». Acá sólo se contesta cuánto queda.
 */
header('Content-Type: application/json; charset=utf-8');

$uid = current_user_id();
if (!$uid) {
    http_response_code(440);
    echo json_encode(['ok' => false, 'error' => 'sesion_expirada']);
    exit;
}

$ahora   = time();
$idleTtl = (int)($GLOBALS['CONFIG']['app']['idle_ttl'] ?? 600);
$absTtl  = (int)($GLOBALS['CONFIG']['app']['abs_ttl']  ?? 28800);
$resto   = max(0, $absTtl - ($ahora - (int)($_SESSION['login_en'] ?? $ahora)));

echo json_encode(['ok' => true, 'idle' => $idleTtl, 'abs_resto' => $resto]);

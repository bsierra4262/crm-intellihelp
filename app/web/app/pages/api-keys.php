<?php
declare(strict_types=1);
require_admin();

$nuevaClave = null;

if (is_post()) {
    verify_csrf();
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'crear') {
        $nombre = trim((string)($_POST['nombre'] ?? '')) ?: 'Token sin nombre';
        $scope  = ($_POST['scope'] ?? 'lectura') === 'root' ? 'root' : 'lectura';
        // Se guarda sólo el SHA-256: la clave completa se muestra una única vez
        // y después no hay forma de recuperarla (ni para nosotros).
        $clave   = 'crm_' . bin2hex(random_bytes(20));
        $prefijo = substr($clave, 0, 12);
        q('INSERT INTO api_tokens (nombre, prefijo, token_hash, scope, creado_por) VALUES (?, ?, ?, ?, ?)',
          [$nombre, $prefijo, hash('sha256', $clave), $scope, current_user_id()]);
        $nuevaClave = $clave;
        log_app('api.token_crear', ['entidad' => 'token', 'entidad_id' => last_id(),
            'detalle' => $nombre . ' · permiso ' . $scope]);

    } elseif ($accion === 'baja') {
        $id = (int)($_POST['id'] ?? 0);
        q('UPDATE api_tokens SET activo = 0 WHERE id = ?', [$id]);
        log_app('api.token_baja', ['entidad' => 'token', 'entidad_id' => $id, 'detalle' => 'Token desactivado']);
        flash('Token desactivado.', 'success');
        redirect('api-keys');
    }
}

render('api-keys', [
    'rows'       => q('SELECT t.*, u.nombre AS creador FROM api_tokens t
                       LEFT JOIN usuarios u ON u.id = t.creado_por ORDER BY t.activo DESC, t.id DESC')->fetchAll(),
    'nuevaClave' => $nuevaClave,
], 'API');

<?php
declare(strict_types=1);
require_admin();

// ============================================================
//  API → claves de acceso para otros sistemas.
//
//  Ruta: api-keys (sólo administradores, también marcado así en index.php).
//  Núcleo: no depende de ningún módulo. Vista: views/api-keys.php.
//
//  Acciones por POST (con CSRF):
//    · crear: genera la clave (y, si es de escritura, su secreto) y la
//      muestra UNA vez en la misma respuesta, sin redirigir: si se redirigiera,
//      habría que pasarla por la sesión y quedaría guardada en el servidor;
//    · baja: desactiva la clave (activo = 0). No se borra, para que la
//      auditoría siga mostrando a quién correspondía; no hay forma de
//      reactivarla desde acá.
//  El permiso 'root' no se puede crear desde esta pantalla: sólo se muestra
//  si ya existía en la base.
//
//  Tabla: api_tokens. La clave se guarda como SHA-256; el secreto, cifrado
//  con cifrar() (google.php), porque hay que leerlo para verificar firmas.
// ============================================================

$nuevaClave   = null;
$nuevoSecreto = null;
$nuevoScope   = null;

if (is_post()) {
    verify_csrf();
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'crear') {
        $nombre = trim((string)($_POST['nombre'] ?? '')) ?: 'Token sin nombre';
        $scope  = ($_POST['scope'] ?? 'lectura') === 'escritura' ? 'escritura' : 'lectura';

        // Una clave de escritura necesita su secreto, y el secreto se guarda
        // cifrado con el de la instalación. Si ése es corto (o no está y se usa
        // el respaldo derivado de la base), cifrar ahí no protege nada: mejor
        // no generar la clave que generarla y que la API la rechace después.
        if ($scope === 'escritura' && !api_secreto_app_ok()) {
            flash('Para generar una clave de escritura, la instalación necesita un secreto propio de al '
                . 'menos 32 caracteres (app.secret en la configuración del servidor).', 'error');
            redirect('api-keys');
        }
        if ($scope === 'escritura' && !api_migracion_lista()) {
            flash('Falta aplicar la actualización de la base (migración 008) para usar claves de escritura.', 'error');
            redirect('api-keys');
        }

        // Se guarda sólo el SHA-256: la clave completa se muestra una única vez
        // y después no hay forma de recuperarla (ni para nosotros).
        $clave   = 'crm_' . bin2hex(random_bytes(20));
        // El prefijo (crm_ + 8 hex) es lo único que se ve después en la lista,
        // para reconocer cuál es cuál sin exponer la clave.
        $prefijo = substr($clave, 0, 12);
        // El secreto, en cambio, hay que poder leerlo para verificar cada firma:
        // se guarda cifrado y tampoco se vuelve a mostrar.
        $secreto = $scope === 'escritura' ? bin2hex(random_bytes(32)) : null;

        if ($secreto !== null) {
            q('INSERT INTO api_tokens (nombre, prefijo, token_hash, secreto_cifrado, scope, creado_por)
               VALUES (?, ?, ?, ?, ?, ?)',
              [$nombre, $prefijo, hash('sha256', $clave), cifrar($secreto), $scope, current_user_id()]);
        } else {
            q('INSERT INTO api_tokens (nombre, prefijo, token_hash, scope, creado_por) VALUES (?, ?, ?, ?, ?)',
              [$nombre, $prefijo, hash('sha256', $clave), $scope, current_user_id()]);
        }
        $nuevaClave   = $clave;
        $nuevoSecreto = $secreto;
        $nuevoScope   = $scope;
        // En la auditoría va el nombre y el permiso; la clave y el secreto, nunca.
        log_app('api.token_crear', ['entidad' => 'token', 'entidad_id' => last_id(),
            'detalle' => $nombre . ' · permiso ' . $scope]);

        // Esta respuesta lleva la clave y el secreto: que no quede en ningún
        // caché, ni del navegador ni del servidor.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-LiteSpeed-Cache-Control: no-cache');

    } elseif ($accion === 'baja') {
        // Rige al instante: api_autenticar() y api/estado buscan sólo activo = 1.
        $id = (int)($_POST['id'] ?? 0);
        q('UPDATE api_tokens SET activo = 0 WHERE id = ?', [$id]);
        log_app('api.token_baja', ['entidad' => 'token', 'entidad_id' => $id, 'detalle' => 'Token desactivado']);
        flash('Token desactivado.', 'success');
        redirect('api-keys');
    }
}

render('api-keys', [
    'rows'         => q('SELECT t.*, u.nombre AS creador FROM api_tokens t
                         LEFT JOIN usuarios u ON u.id = t.creado_por ORDER BY t.activo DESC, t.id DESC')->fetchAll(),
    'nuevaClave'   => $nuevaClave,
    'nuevoSecreto' => $nuevoSecreto,
    'nuevoScope'   => $nuevoScope,
    'escrituraOk'  => api_secreto_app_ok() && api_migracion_lista(),
], 'API');

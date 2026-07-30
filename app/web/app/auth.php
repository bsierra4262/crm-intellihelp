<?php
declare(strict_types=1);

// ============================================================
//  Sesión y usuarios.
//  No hay contraseñas: el acceso es 100 % Google Workspace.
// ============================================================

/** Devuelve el usuario autenticado (array) o null. */
function auth_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $u = q1('SELECT id, nombre, email, rol, activo, avatar_url, cargo FROM usuarios WHERE id = ? LIMIT 1',
            [$_SESSION['uid']]);
    if (!$u || (int)$u['activo'] !== 1) {
        auth_logout();
        return null;
    }
    return $cache = $u;
}

function auth_is_admin(): bool
{
    $u = auth_user();
    return $u && $u['rol'] === 'admin';
}

function current_user_id(): ?int
{
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
}

function auth_logout(): void
{
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Busca un usuario por correo (o null). */
function find_user_by_email(string $email): ?array
{
    return q1('SELECT id, nombre, email, rol, activo FROM usuarios WHERE email = ? LIMIT 1',
              [normalizar_email($email)]);
}

/** Inicia sesión para un id de usuario ya validado (viene de Google OAuth). */
function auth_login_user(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['uid']              = $id;
    $_SESSION['login_en']         = time();
    $_SESSION['ultima_actividad'] = time();
    q('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?', [$id]);
    log_app('sesion.ingreso', ['tipo' => 'auth', 'usuario_id' => $id,
        'detalle' => 'Ingreso con Google Workspace']);
}

/** Crea un usuario para acceso vía Google. */
function provision_user(string $email, string $nombre, string $rol = 'comercial'): int
{
    $email = normalizar_email($email);
    // El primer usuario del sistema es administrador: si no, nadie podría
    // entrar a configurar la app después de instalarla.
    if ((int) qv('SELECT COUNT(*) FROM usuarios') === 0) {
        $rol = 'admin';
    }
    q('INSERT INTO usuarios (nombre, email, rol, activo) VALUES (?, ?, ?, 1)',
      [$nombre !== '' ? $nombre : $email, $email, $rol === 'admin' ? 'admin' : 'comercial']);
    return last_id();
}

/** Lista todos los usuarios (admin). */
function users_all(): array
{
    return q('SELECT id, nombre, email, rol, activo, avatar_url, cargo, ultimo_acceso, creado_en
              FROM usuarios ORDER BY activo DESC, rol, nombre')->fetchAll();
}

/** Usuarios activos, para los selectores de "propietario". */
function users_activos(): array
{
    return q('SELECT id, nombre, email FROM usuarios WHERE activo = 1 ORDER BY nombre')->fetchAll();
}

/** Actualiza rol y estado de un usuario. */
function user_update(int $id, string $rol, bool $activo): void
{
    $rol = $rol === 'admin' ? 'admin' : 'comercial';
    q('UPDATE usuarios SET rol = ?, activo = ? WHERE id = ?', [$rol, $activo ? 1 : 0, $id]);
    log_app('usuario.actualizar', ['entidad' => 'usuario', 'entidad_id' => $id,
        'detalle' => 'rol=' . $rol . ' · ' . ($activo ? 'activo' : 'inactivo')]);
}

/** Cantidad de administradores activos (para no quedarse sin ninguno). */
function admins_activos(): int
{
    return (int) qv("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1");
}

function require_login(): void
{
    if (!auth_user()) {
        redirect('login');
    }
}

function require_admin(): void
{
    require_login();
    if (!auth_is_admin()) {
        http_response_code(403);
        exit('Acceso denegado: se requiere rol administrador.');
    }
}

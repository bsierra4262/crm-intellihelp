<?php
declare(strict_types=1);

// ============================================================
//  Sesión y usuarios.
//  No hay contraseñas: el acceso es 100 % Google Workspace.
//
//  El ingreso lo hace google.php (pages/google-callback.php): valida la
//  cuenta con Google, la busca o la crea (find_user_by_email /
//  provision_user) y llama a auth_login_user(). Desde ahí la sesión PHP sólo
//  guarda el id (`$_SESSION['uid']`) y las marcas de tiempo del cierre
//  automático (`login_en`, `ultima_actividad`, que controla bootstrap.php).
//
//  Tabla: `usuarios` (id, nombre, email, rol 'admin'|'comercial', activo,
//  avatar_url, cargo, ultimo_acceso, creado_en).
//
//  Roles: 'admin' ve todo (configuración, usuarios, API, CRON, papelera);
//  'comercial' el trabajo diario. El control de rol se hace en index.php
//  (rutas marcadas 'admin') y en cada controlador con require_admin().
//  Fuera de eso no hay permisos por registro: un comercial ve y edita lo
//  mismo que cualquier otro comercial.
// ============================================================

/**
 * Devuelve el usuario autenticado (array) o null.
 *
 * Se relee de la base una vez por pedido (y se guarda en un caché estático):
 * así un cambio de rol o una desactivación rige desde el pedido siguiente,
 * sin esperar a que la persona vuelva a ingresar. Si el usuario ya no existe
 * o está inactivo, se cierra la sesión en el acto.
 *
 * @return array{id:int,nombre:string,email:string,rol:string,activo:int,avatar_url:?string,cargo:?string}|null
 */
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

/** ¿El usuario de la sesión es administrador? False si no hay sesión. */
function auth_is_admin(): bool
{
    $u = auth_user();
    return $u && $u['rol'] === 'admin';
}

/**
 * Id del usuario de la sesión, sin ir a la base.
 *
 * Ojo: no comprueba que el usuario siga activo (eso lo hace auth_user()).
 * Sirve para anotar autoría (creado_por) una vez que index.php ya validó.
 */
function current_user_id(): ?int
{
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
}

/**
 * Cierra la sesión: vacía los datos, vence la cookie y destruye el archivo
 * de sesión del servidor. No redirige: eso lo decide quien llama.
 */
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

/** Busca un usuario por correo (o null). El correo se normaliza a minúsculas. */
function find_user_by_email(string $email): ?array
{
    return q1('SELECT id, nombre, email, rol, activo FROM usuarios WHERE email = ? LIMIT 1',
              [normalizar_email($email)]);
}

/**
 * Inicia sesión para un id de usuario ya validado (viene de Google OAuth).
 *
 * Regenera el id de sesión (evita la fijación de sesión: un id que alguien
 * plantó antes del ingreso deja de servir), arranca los relojes del cierre
 * automático, anota el último acceso y deja el ingreso en la auditoría.
 * No comprueba que el usuario esté activo: eso le toca a quien llama.
 */
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

/**
 * Crea un usuario para acceso vía Google y devuelve su id.
 *
 * El rol sólo puede quedar 'admin' o 'comercial': cualquier otro valor cae en
 * 'comercial'. Si no se recibe nombre, se usa el correo.
 *
 * @throws PDOException si el correo ya existe (clave única) u otro error de base
 */
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

/**
 * Actualiza rol y estado de un usuario y lo deja en la auditoría.
 *
 * No controla que quede al menos un administrador: eso lo hace
 * pages/usuario-guardar.php antes de llamar.
 */
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

/**
 * Corta el pedido si no hay sesión válida: redirige al login (hace exit).
 * Redundante con el control de index.php, pero deja explícito en cada
 * controlador que la pantalla es privada.
 */
function require_login(): void
{
    if (!auth_user()) {
        redirect('login');
    }
}

/**
 * Corta el pedido si el usuario no es administrador: sin sesión, al login;
 * con sesión de comercial, pantalla 403 y exit.
 */
function require_admin(): void
{
    require_login();
    if (!auth_is_admin()) {
        // Con el diseño de siempre y una salida, no una línea de texto suelta
        // en una pantalla en blanco.
        http_response_code(403);
        render('error', [
            'codigo'  => 403,
            'titulo'  => 'Esta pantalla es de administración',
            'mensaje' => 'Tu usuario no tiene permiso para entrar acá. Si necesitás acceso, pedíselo a un administrador.',
        ], 'Sin permiso');
        exit;
    }
}

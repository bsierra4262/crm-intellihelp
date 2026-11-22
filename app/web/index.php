<?php
declare(strict_types=1);

// ============================================================
//  Front controller: la única puerta de entrada HTTP de la aplicación.
//
//  El `.htaccess` manda acá todo pedido que no sea un archivo real, con la
//  ruta en `?r=` (ej. /crm/contacto-ver?id=3 → index.php?r=contacto-ver&id=3).
//  Este archivo:
//    1. arranca la aplicación (bootstrap.php: config, base, sesión, auto-cierre);
//    2. busca la ruta en la tabla `$rutas` (lo que no está ahí es 404);
//    3. exige sesión si la ruta no es pública (si no, al login);
//    4. bloquea la ruta si su módulo está apagado (modulos.php);
//    5. exige rol administrador si la ruta lo pide;
//    6. anota la visita en la bitácora (tabla `actividad`, sección CRON);
//    7. incluye el controlador `app/pages/<ruta>.php`.
//
//  Trampas:
//    · El controlador se incluye por el nombre de la ruta, así que sólo se
//      puede llegar a un archivo de `pages/` que figure como clave de
//      `$rutas`: no hay forma de pedir un archivo arbitrario.
//    · Las pantallas de administración se protegen dos veces: acá (`admin`)
//      y adentro de cada controlador con require_admin(). Si se agrega una
//      ruta de administración, conviene marcarla en las dos partes.
//    · Una ruta nueva que pertenezca a un módulo apagable tiene que figurar
//      también en modulos(); si no, queda siempre habilitada.
//    · Las rutas "public" no tienen sesión obligatoria, pero la sesión
//      igual se abre (salvo la API v1, ver bootstrap.php).
// ============================================================

require __DIR__ . '/app/bootstrap.php';

// Sin ruta (la raíz /crm/) se entra por 'home', que redirige al panel.
$ruta = current_route();
if ($ruta === '' || $ruta === '/') {
    $ruta = 'home';
}

/**
 * Rutas registradas.
 *   public → accesible sin sesión
 *   admin  → sólo rol administrador
 *
 * La clave es a la vez la dirección y el nombre del controlador:
 * 'contacto-ver' se atiende con app/pages/contacto-ver.php, y 'api/v1/reserva'
 * con app/pages/api/v1/reserva.php.
 */
$rutas = [
    // --- Acceso ---
    'home'            => ['public' => false],
    'login'           => ['public' => true],
    'google-login'    => ['public' => true],
    'google-callback' => ['public' => true],
    'logout'          => ['public' => false],

    // --- Trabajo diario ---
    'dashboard'       => ['public' => false],

    'contactos'       => ['public' => false],
    'contacto-form'   => ['public' => false],
    'contacto-ver'    => ['public' => false],

    'cuentas'         => ['public' => false],
    'cuenta-form'     => ['public' => false],
    'cuenta-ver'      => ['public' => false],

    'posibles'        => ['public' => false],
    'posible-form'    => ['public' => false],
    'posible-ver'     => ['public' => false],
    'posible-convertir' => ['public' => false],

    'negocios'        => ['public' => false],
    'negocio-form'    => ['public' => false],
    'negocio-ver'     => ['public' => false],
    'embudo'          => ['public' => false],
    'negocio-etapa'   => ['public' => false],
    'embudos'         => ['public' => false, 'admin' => true],

    'actividades'     => ['public' => false],
    'actividad-form'  => ['public' => false],
    'actividad-completar' => ['public' => false],

    'campanas'        => ['public' => false],
    'campana-form'    => ['public' => false],
    'campana-ver'     => ['public' => false],
    'campana-accion'  => ['public' => false],
    'plantillas'      => ['public' => false],
    'plantilla-form'  => ['public' => false],
    'plantilla-vista' => ['public' => false],
    // Administración: el menú ya las trataba así, pero la ruta no lo decía y
    // se podía entrar escribiendo la dirección a mano.
    'imagenes'        => ['public' => false, 'admin' => true],
    'img'             => ['public' => true],   // sirve las imágenes de las campañas
    'cuentas-correo'  => ['public' => false, 'admin' => true],
    'listas'          => ['public' => false],
    'lista-form'      => ['public' => false],
    'lista-ver'       => ['public' => false],

    'agenda'          => ['public' => false],
    'agenda-form'     => ['public' => false],
    'reservas'        => ['public' => false],
    'reserva-accion'  => ['public' => false],
    'reserva-mover'   => ['public' => false],
    'feriados'        => ['public' => false, 'admin' => true],
    'google-desconectar' => ['public' => false],

    'reportes'        => ['public' => false],
    'cuotas'          => ['public' => false, 'admin' => true],
    'importar'        => ['public' => false, 'admin' => true],
    'registro-eliminar' => ['public' => false],
    'nota-guardar'    => ['public' => false],
    'nota-eliminar'   => ['public' => false],
    'etiquetas-guardar' => ['public' => false],
    'adjunto'         => ['public' => false],
    'adjunto-subir'   => ['public' => false],
    'buscar'          => ['public' => false],

    // --- Público (sin sesión): agenda de reuniones y baja de suscripción ---
    'r'               => ['public' => true],   // /r/<usuario>/<tipo>  → página de reserva
    'reservar'        => ['public' => true],
    'baja'            => ['public' => true],
    'px'              => ['public' => true],   // píxel de apertura

    // --- API de lectura ---
    'api/estado'      => ['public' => true],

    // --- API v1: lo que entra desde la web ---
    // Públicas en el sentido de que no usan sesión: la autenticación es la
    // firma de cada pedido (app/api.php).
    'api/v1/disponibilidad' => ['public' => true],
    'api/v1/reserva'  => ['public' => true],
    'api/v1/entrada'  => ['public' => true],
    'entradas-web'    => ['public' => false],
    'api-log'         => ['public' => false],
    'api-sesion'      => ['public' => false],   // latido del cierre por inactividad
    'api-campana'     => ['public' => false],   // avance de las campañas, en vivo
    'api-alertas'     => ['public' => false],   // cuántas alertas sin leer
    'api-buscar'      => ['public' => false],   // buscador de los desplegables grandes
    'alertas'         => ['public' => false],
    'conversacion'    => ['public' => false],   // leer y contestar sin salir del CRM

    // --- Administración ---
    'usuarios'        => ['public' => false, 'admin' => true],
    'usuario-guardar' => ['public' => false, 'admin' => true],
    'papelera'        => ['public' => false, 'admin' => true],
    'ajustes'         => ['public' => false, 'admin' => true],
    'api-keys'        => ['public' => false, 'admin' => true],
    'cron'            => ['public' => false, 'admin' => true],
];

// La agenda pública usa rutas con segmentos (/r/juan/demo-30): se resuelve al
// controlador 'r' y él parsea el resto.
$rutaBase = $ruta;
if (str_starts_with($ruta, 'r/')) {
    $rutaBase = 'r';
    $_GET['_ruta'] = substr($ruta, 2);
}

// Ruta desconocida: 404 con la pantalla de error del sistema. Se responde
// antes de mirar la sesión, así que una dirección inexistente da 404 aunque
// no se haya ingresado.
if (!isset($rutas[$rutaBase])) {
    http_response_code(404);
    require APP . '/pages/404.php';
    exit;
}

// Sin sesión válida (o con un usuario desactivado: auth_user() lo detecta y
// cierra la sesión) se manda al login. Los pedidos en segundo plano (los
// endpoints api-*, un fetch con X-Requested-With, el refresco automático)
// reciben 440 con JSON, igual que cuando bootstrap.php cierra una sesión
// vencida: una redirección los dejaba leyendo el HTML del login como si
// fuera la respuesta.
$def = $rutas[$rutaBase];
if (empty($def['public']) && !auth_user()) {
    // api-keys es una pantalla (la de claves), no un endpoint: va al login.
    if ((str_starts_with($rutaBase, 'api-') && $rutaBase !== 'api-keys') || vivo_pedido()
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')) {
        json_out(['ok' => false, 'error' => 'sesion_expirada'], 440);
    }
    redirect('login');
}

// Una sección apagada no se esconde solamente del menú: también se bloquea la ruta.
// Esconder un enlace no impide entrar escribiendo la dirección, y quien lo
// hiciera llegaría a una pantalla que el sistema da por no disponible, cargaría
// algo ahí y después no aparecería donde espera.
if (!ruta_habilitada($rutaBase)) {
    http_response_code(404);
    render('error', [
        'codigo'  => 404,
        'titulo'  => 'Esta parte todavía no está en marcha',
        'mensaje' => 'Se habilita desde Ajustes → Qué está en marcha, cuando esté lista para usarse.',
    ], 'No disponible');
    exit;
}
if (!empty($def['admin']) && !auth_is_admin()) {
    // Pantalla de error del sistema y no un `exit()` con una línea de texto:
    // explica qué pasa y ofrece una forma de volver.
    http_response_code(403);
    render('error', [
        'codigo'  => 403,
        'titulo'  => 'Esta pantalla es de administración',
        'mensaje' => 'Tu usuario no tiene permiso para entrar acá. Si necesitás acceso, pedíselo a un administrador.',
    ], 'Sin permiso');
    exit;
}

// Registro de navegación para el CRON: cada visita GET a una pantalla real.
// Las mutaciones se registran en el modelo; los endpoints técnicos se excluyen
// para no ensuciar la bitácora.
$rutasNav = ['dashboard', 'contactos', 'cuentas', 'posibles', 'negocios', 'embudo', 'actividades',
             'campanas', 'plantillas', 'listas', 'agenda', 'reservas', 'reportes', 'importar',
             'usuarios', 'papelera', 'ajustes', 'api-keys', 'feriados'];
// El refresco automático se excluye: son varias visitas por minuto por pantalla
// abierta, y llenarían la bitácora de ruido hasta tapar los movimientos reales.
if (method() === 'GET' && !vivo_pedido() && in_array($rutaBase, $rutasNav, true) && auth_user()) {
    log_app('ver.' . $rutaBase, ['tipo' => 'nav', 'detalle' => 'Ingresó a la pantalla «' . $rutaBase . '»']);
}

// Pasados todos los controles, se ejecuta el controlador. Cada uno termina
// dibujando una vista (render), respondiendo JSON (json_out) o redirigiendo.
require APP . '/pages/' . $rutaBase . '.php';

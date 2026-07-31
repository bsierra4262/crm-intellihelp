<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$ruta = current_route();
if ($ruta === '' || $ruta === '/') {
    $ruta = 'home';
}

/**
 * Rutas registradas.
 *   public → accesible sin sesión
 *   admin  → sólo rol administrador
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
    'api-log'         => ['public' => false],
    'api-sesion'      => ['public' => false],   // latido del cierre por inactividad
    'api-campana'     => ['public' => false],   // avance de las campañas, en vivo
    'api-alertas'     => ['public' => false],   // cuántas alertas sin leer
    'alertas'         => ['public' => false],

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

if (!isset($rutas[$rutaBase])) {
    http_response_code(404);
    require APP . '/pages/404.php';
    exit;
}

$def = $rutas[$rutaBase];
if (empty($def['public']) && !auth_user()) {
    redirect('login');
}
if (!empty($def['admin']) && !auth_is_admin()) {
    // Antes esto era un `exit('Acceso denegado.')`: una pantalla en blanco con
    // una línea de texto, sin diseño y sin forma de volver. Se usa la pantalla
    // de error del sistema, que explica y ofrece una salida.
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
             'usuarios', 'papelera', 'ajustes', 'api-keys'];
// El refresco automático se excluye: son varias visitas por minuto por pantalla
// abierta, y llenarían la bitácora de ruido hasta tapar los movimientos reales.
if (method() === 'GET' && !vivo_pedido() && in_array($rutaBase, $rutasNav, true) && auth_user()) {
    log_app('ver.' . $rutaBase, ['tipo' => 'nav', 'detalle' => 'Ingresó a la pantalla «' . $rutaBase . '»']);
}

require APP . '/pages/' . $rutaBase . '.php';

<?php
declare(strict_types=1);

// ============================================================
//  Test de render — CRM INTELLIHELP
//
//  El linter de PHP encuentra errores de sintaxis, pero no encuentra una
//  función inexistente ni una variable que la vista espera y nadie pasó.
//  Este test arma una sesión falsa y **ejecuta de verdad cada pantalla**,
//  capturando la salida: si algo revienta, se ve acá y no en producción.
//
//  Cada pantalla corre en su PROPIO proceso: las páginas que redirigen
//  terminan con exit y matarían al resto de la batería. El bucle lo hace
//  `test/render.sh`, no PHP, porque el hosting tiene deshabilitadas las
//  funciones de ejecución de procesos (shell_exec, exec, system…).
//
//  Uso:
//    bash test/render.sh                      → corre todas las pantallas
//    php test/render_test.php --rutas         → lista las claves
//    php test/render_test.php contactos WEB   → corre una sola
//    php test/render_test.php --limpiar WEB   → borra el usuario de prueba
//
//  Necesita la base de datos (usa la configuración desplegada).
// ============================================================

$RAIZ = getenv('CRM_WEB') ?: '/home/u566451014/domains/intellihelp.tech/public_html/crm';

/** Rutas a probar: ruta => parámetros GET. */
function rutas_a_probar(): array
{
    return [
        // El login se prueba SIN sesión: con sesión redirige al panel y no
        // renderiza nada, que es justamente lo que tiene que hacer.
        'login'           => ['_sin_sesion' => true],
        'dashboard'       => [],
        'contactos'       => [],
        'contactos:filtro'=> ['_r' => 'contactos', 'q' => 'a', 'provincia' => 'Buenos Aires', 'page' => '1'],
        'contacto-form'   => [],
        'cuentas'         => [],
        'cuenta-form'     => [],
        'posibles'        => [],
        'posible-form'    => [],
        'negocios'        => [],
        'negocio-form'    => [],
        'embudo'          => [],
        'actividades'     => [],
        'actividades:hoy' => ['_r' => 'actividades', 'vista' => 'hoy'],
        'actividad-form'  => [],
        'campanas'        => [],
        'campana-form'    => [],
        'plantillas'      => [],
        'plantilla-form'  => [],
        'plantilla-nueva' => ['_r' => 'plantilla-form', 'desde' => 'pulsemail'],
        'cuentas-correo'  => [],
        'imagenes'        => [],
        'listas'          => [],
        'lista-form'      => [],
        'agenda'          => [],
        'agenda-form'     => [],
        'reservas'        => [],
        'reportes'        => [],
        'buscar'          => ['q' => 'test'],
        'importar'        => [],
        'usuarios'        => [],
        'papelera'        => [],
        'ajustes'         => [],
        'cron'            => [],
        'api-keys'        => [],
    ];
}

$modo = $argv[1] ?? '--rutas';
$web  = $argv[2] ?? $RAIZ;

// Lista de claves, para que el bucle de bash sepa qué correr.
if ($modo === '--rutas') {
    foreach (array_keys(rutas_a_probar()) as $k) { echo $k, "\n"; }
    exit(0);
}

// Limpieza: el usuario de prueba no queda dando vueltas en la base.
if ($modo === '--limpiar') {
    require $web . '/app/bootstrap.php';
    q("DELETE FROM actividad WHERE usuario_id IN (SELECT id FROM usuarios WHERE email = 'render.test@intellihelp.tech')");
    q("DELETE FROM usuarios WHERE email = 'render.test@intellihelp.tech'");
    echo "limpio\n";
    exit(0);
}

// ------------------------------------------------------------
//  Ejecuta UNA pantalla y dice qué pasó.
// ------------------------------------------------------------
{
    $clave  = $modo;
    $rutas  = rutas_a_probar();
    $params = $rutas[$clave] ?? [];
    $ruta      = $params['_r'] ?? $clave;
    $sinSesion = !empty($params['_sin_sesion']);
    unset($params['_r'], $params['_sin_sesion']);

    // Fingir una request web para que el bootstrap y las vistas funcionen.
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST']      = 'intellihelp.tech';
    $_SERVER['SCRIPT_NAME']    = '/crm/index.php';
    $_SERVER['REQUEST_URI']    = '/crm/' . $ruta;
    $_SERVER['HTTPS']          = 'on';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_GET = $params + ['r' => $ruta];

    require $web . '/app/bootstrap.php';

    // Sesión falsa de administrador. El usuario de prueba se crea si no está y
    // se borra al final de la batería.
    if ($sinSesion) {
        $_SESSION = [];
    } else {
        $u = q1("SELECT id FROM usuarios WHERE email = 'render.test@intellihelp.tech'");
        if (!$u) {
            q("INSERT INTO usuarios (nombre, email, rol, activo) VALUES ('Render Test', 'render.test@intellihelp.tech', 'admin', 1)");
            $uid = last_id();
        } else {
            $uid = (int)$u['id'];
        }
        $_SESSION = ['uid' => $uid, 'login_en' => time(), 'ultima_actividad' => time(), '_csrf' => str_repeat('a', 64)];
    }

    // Las páginas escriben cabeceras; en CLI eso no molesta.
    ob_start();
    try {
        require APP . '/pages/' . $ruta . '.php';
        $salida = ob_get_clean();
        $largo  = strlen($salida);
        // Un fatal de PHP se imprime dentro del HTML: hay que buscarlo.
        if (stripos($salida, 'Fatal error') !== false || stripos($salida, 'Uncaught') !== false) {
            fwrite(STDERR, "FATAL en la salida\n" . substr($salida, 0, 900) . "\n");
            exit(2);
        }
        if ($largo < 200) {
            fwrite(STDERR, "SALIDA SOSPECHOSAMENTE CORTA ($largo bytes)\n$salida\n");
            exit(3);
        }
        fwrite(STDOUT, "ok $largo\n");
        exit(0);
    } catch (\Throwable $e) {
        ob_end_clean();
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage()
             . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
        exit(2);
    }
}

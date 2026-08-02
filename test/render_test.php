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
        'embudos'         => [],
        'cuotas'          => [],
        'alertas'         => [],
        'reserva-mover'   => ['_id_de' => 'agenda_reservas'],

        // Fichas de detalle. El listado y la ficha no comparten código, y las
        // que se rompieron fueron las fichas: `campana-ver` pedía la vista
        // previa del correo y le llegaba el nombre de la vista en su lugar,
        // mientras el listado de campañas se dibujaba perfecto. Sin estas
        // entradas, la batería daba 35 en verde con la pantalla rota.
        // El id sale de la base al momento de correr; si la tabla está vacía la
        // pantalla se saltea, en vez de dar un falso negativo.
        'contacto-ver'    => ['_id_de' => 'contactos'],
        'cuenta-ver'      => ['_id_de' => 'cuentas'],
        'posible-ver'     => ['_id_de' => 'posibles_clientes'],
        'negocio-ver'     => ['_id_de' => 'negocios'],
        'campana-ver'     => ['_id_de' => 'campanas'],
        'lista-ver'       => ['_id_de' => 'listas'],
        // La conversación se abre por correo, no por id: quien responde una
        // campaña puede no estar cargado como contacto todavía.
        'conversacion'    => ['_email_de' => 'mensajes'],
        'campana-editar'  => ['_r' => 'campana-form',   '_id_de' => 'campanas'],
        'plantilla-editar'=> ['_r' => 'plantilla-form', '_id_de' => 'plantillas'],

        // --- El refresco automático de cada pantalla ---
        // Se piden las mismas pantallas con `__vivo=1`, que es como las pide el
        // navegador solo cada pocos segundos. Tienen que traer el cuerpo sin el
        // layout y con al menos una zona marcada: si no traen zonas, el refresco
        // no actualiza nada y la pantalla queda congelada sin que nada avise.
        'vivo:dashboard'  => ['_r' => 'dashboard',   '_vivo' => true],
        'vivo:contactos'  => ['_r' => 'contactos',   '_vivo' => true],
        'vivo:campanas'   => ['_r' => 'campanas',    '_vivo' => true],
        'vivo:listas'     => ['_r' => 'listas',      '_vivo' => true],
        'vivo:lista-ver'  => ['_r' => 'lista-ver',   '_vivo' => true, '_id_de' => 'listas'],
        'vivo:reservas'   => ['_r' => 'reservas',    '_vivo' => true],
        'vivo:alertas'    => ['_r' => 'alertas',     '_vivo' => true],
        'vivo:actividades'=> ['_r' => 'actividades', '_vivo' => true],
        'vivo:negocios'   => ['_r' => 'negocios',    '_vivo' => true],
        'vivo:cuentas'    => ['_r' => 'cuentas',     '_vivo' => true],
        'vivo:posibles'   => ['_r' => 'posibles',    '_vivo' => true],
        'vivo:campana-ver'=> ['_r' => 'campana-ver', '_vivo' => true, '_id_de' => 'campanas'],
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
    $idDe      = $params['_id_de'] ?? null;
    $emailDe   = $params['_email_de'] ?? null;
    $vivo      = !empty($params['_vivo']);
    unset($params['_r'], $params['_sin_sesion'], $params['_id_de'], $params['_vivo'], $params['_email_de']);
    if ($vivo) { $params['__vivo'] = '1'; }

    // Fingir una request web para que el bootstrap y las vistas funcionen.
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST']      = 'intellihelp.tech';
    $_SERVER['SCRIPT_NAME']    = '/crm/index.php';
    $_SERVER['REQUEST_URI']    = '/crm/' . $ruta;
    $_SERVER['HTTPS']          = 'on';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_GET = $params + ['r' => $ruta];

    require $web . '/app/bootstrap.php';

    // Id real de la ficha a probar, sacado de la base.
    if ($emailDe !== null) {
        $email = qv('SELECT de FROM `' . $emailDe . "` WHERE direccion = 'entrante' ORDER BY id LIMIT 1");
        if (!$email) { fwrite(STDOUT, "ok 0\n"); exit(0); }   // todavía no respondió nadie
        $_GET['email'] = $email;
    }
    if ($idDe !== null) {
        $tieneBorrado = (bool) q1("SHOW COLUMNS FROM `$idDe` LIKE 'eliminado_en'");
        $id = qv('SELECT MIN(id) FROM `' . $idDe . '`' . ($tieneBorrado ? ' WHERE eliminado_en IS NULL' : ''));
        if (!$id) {
            fwrite(STDOUT, "ok 0\n");   // tabla vacía: no hay nada que dibujar
            exit(0);
        }
        $_GET['id'] = (int)$id;
    }

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

    // El refresco automático corta la ejecución con `exit` en cuanto terminó de
    // escribir el cuerpo —es lo correcto: no tiene que seguir dibujando el
    // layout—, así que lo que venga después de esta línea nunca se ejecuta y
    // las comprobaciones tienen que hacerse al cerrar el proceso.
    if ($vivo) {
        register_shutdown_function(static function (): void {
            $salida = (string) ob_get_clean();
            $largo  = strlen($salida);
            // Se busca la hoja de estilos, que la escribe SÓLO el layout. Buscar
            // «</html>» acá daba un falso positivo: la ficha de una campaña
            // muestra la vista previa del correo, que es un documento HTML
            // entero, así que esa etiqueta viene del contenido y no del marco.
            if (str_contains($salida, 'assets/css/app.css')) {
                fwrite(STDERR, "EL REFRESCO TRAJO EL LAYOUT ENTERO\n");
                exit(6);
            }
            if (!str_contains($salida, 'data-vivo=')) {
                fwrite(STDERR, "EL REFRESCO NO TRAE NINGUNA ZONA MARCADA: la pantalla no se actualizaría\n");
                exit(7);
            }
            // La huella tiene que venir al principio: es lo que el navegador
            // devuelve para que el servidor sepa que ya tiene esta versión.
            if (!preg_match('/^<!--vivo:[0-9a-f]{32}-->/', $salida)) {
                fwrite(STDERR, "EL REFRESCO NO TRAE LA HUELLA: mandaría la pantalla entera cada vez\n");
                exit(8);
            }
            if ($largo < 200) {
                fwrite(STDERR, "SALIDA SOSPECHOSAMENTE CORTA ($largo bytes)\n$salida\n");
                exit(3);
            }
            fwrite(STDOUT, "ok $largo\n");
            exit(0);
        });
    }

    // Las páginas escriben cabeceras; en CLI eso no molesta.
    ob_start();
    try {
        require APP . '/pages/' . $ruta . '.php';
        $salida = ob_get_clean();
        $largo  = strlen($salida);
        // Un fatal de PHP se imprime dentro del HTML: hay que buscarlo. Se
        // busca la MARCA que imprime PHP, no las palabras sueltas: la pantalla
        // de CRON muestra el registro de errores, y ahí aparecen textos como
        // «Uncaught TypeError» que son el contenido, no una falla de la
        // pantalla. Buscando las palabras a secas, esa pantalla daba error
        // justamente por estar haciendo bien su trabajo. El contenido va
        // escapado, así que las etiquetas de PHP no pueden venir del dato.
        if (preg_match('#<b>(Fatal error|Parse error|Warning)</b>#i', $salida)
            || preg_match('/^(PHP )?(Fatal error|Parse error):/mi', $salida)) {
            fwrite(STDERR, "FATAL en la salida\n" . substr($salida, 0, 900) . "\n");
            exit(2);
        }
        if ($largo < 200) {
            fwrite(STDERR, "SALIDA SOSPECHOSAMENTE CORTA ($largo bytes)\n$salida\n");
            exit(3);
        }
        // La página tiene que haber salido ENTERA. Cuando una vista se colgaba
        // a mitad de camino, PHP volcaba igual lo que llevaba escrito: salía el
        // HTML suelto, sin <head> y por lo tanto sin estilos, que es la pared
        // de texto que se vio en pantalla. «No reventó» no alcanza como prueba.
        // Si una pantalla del refresco llega hasta acá es que NO cortó, y eso
        // significa que dibujó el layout entero: lo juzga el cierre de arriba.
        if (stripos($salida, '</html>') === false || stripos($salida, 'app.css') === false) {
            fwrite(STDERR, "SALIÓ SIN EL LAYOUT: falta </html> o la hoja de estilos\n");
            exit(4);
        }
        // Ahora una vista rota se atrapa y se reemplaza por la pantalla de
        // error. Eso está bien para quien usa el sistema, pero para la batería
        // sigue siendo un fallo y hay que detectarlo.
        if (str_contains($salida, 'No pudimos dibujar esta pantalla')) {
            fwrite(STDERR, "LA VISTA FALLÓ Y SE DIBUJÓ LA PANTALLA DE ERROR\n");
            exit(5);
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

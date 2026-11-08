<?php
/** Layout principal. Requiere en scope: $content, $title. */
/** @var string $content @var string $title */
$u       = auth_user();
$ruta    = current_route();
// Manda lo cargado en Ajustes; el config del servidor queda de respaldo.
$appName = (string) (setting_get('app_nombre', '') ?: ($GLOBALS['CONFIG']['app']['name'] ?? 'CRM INTELLIHELP'));

// La barra lleva SÓLO el trabajo diario. Todo lo administrativo va al menú
// «Más»: con catorce solapas sueltas la barra no entraba ni en una pantalla de
// 1920 px y la última quedaba cortada al medio.
// Sólo se muestran las secciones que están en marcha. En producción se eligen
// una por una desde Ajustes; en prueba está todo, que es donde se termina lo
// que falta.
$nav = [
    'dashboard'   => ['Panel',       'panel'],
    'posibles'    => ['Posibles',    'posibles'],
    'contactos'   => ['Contactos',   'contactos'],
    'cuentas'     => ['Empresas',    'cuentas'],
    'embudo'      => ['Embudo',      'embudo'],
    'actividades' => ['Actividades', 'tareas'],
    'campanas'    => ['Campañas',    'campanas'],
    'agenda'      => ['Agenda',      'agenda'],
    'reportes'    => ['Reportes',    'reportes'],
];
$nav = array_filter($nav, fn($k) => ruta_habilitada($k), ARRAY_FILTER_USE_KEY);

// Las rutas hijas marcan activa a su sección madre: estando en «Nuevo contacto»
// la solapa Contactos tiene que seguir encendida.
$seccionDe = [
    'contacto-form' => 'contactos', 'contacto-ver' => 'contactos',
    'cuenta-form'   => 'cuentas',   'cuenta-ver'   => 'cuentas',
    'posible-form'  => 'posibles',  'posible-ver'  => 'posibles', 'posible-convertir' => 'posibles',
    'negocio-form'  => 'embudo',    'negocio-ver'  => 'embudo',   'negocios' => 'embudo',
    'embudos'       => 'embudos',
    'cuotas'        => 'cuotas',
    'actividad-form' => 'actividades',
    'campana-form'  => 'campanas',  'campana-ver'  => 'campanas',
    'plantillas'    => 'campanas',  'plantilla-form' => 'campanas',
    'listas'        => 'campanas',  'lista-form'   => 'campanas', 'lista-ver' => 'campanas',
    'agenda-form'   => 'agenda',    'reservas'     => 'agenda',
];
$seccion = $seccionDe[$ruta] ?? $ruta;

$admin = [];
if ($u && ($u['rol'] ?? '') === 'admin') {
    $admin = [
        'importar'       => ['Importar datos',  'importar'],
        'cuentas-correo' => ['Cuentas de correo', 'campanas'],
        'imagenes'       => ['Imágenes',        'subir'],
        'embudos'  => ['Embudos y etapas', 'embudo'],
        'cuotas'   => ['Objetivos de venta', 'reportes'],
        'usuarios' => ['Usuarios',       'usuarios'],
        'papelera' => ['Papelera',       'papelera'],
        'ajustes'  => ['Ajustes',        'ajustes'],
        'api-keys' => ['API',            'clave'],
        'entradas-web' => ['Entradas desde la web', 'enlace'],
        'cron'     => ['CRON',           'cron'],
    ];
}
// El grupo de administración se filtra igual que el resto: si no, ahí
// seguirían apareciendo Importar, Cuentas de correo o Embudos con su módulo
// apagado, y hacer clic llevaría a «esto todavía no está en marcha».
$admin = array_filter($admin, fn($k) => ruta_habilitada($k), ARRAY_FILTER_USE_KEY);

$asset     = fn($p) => e(url($p));
$extraHead = $extraHead ?? '';
$extraFoot = $extraFoot ?? '';
$bodyClass = $bodyClass ?? ($u ? 'app' : 'auth');

// Marca (white-label): color y logo configurables por config/entorno.
$brand     = $GLOBALS['CONFIG']['app']['brand'] ?? [];
$brandLogo = !empty($brand['logo']) ? $brand['logo'] : 'assets/img/ITH-logo-139x75.png';
$brandOrg  = $GLOBALS['CONFIG']['app']['org'] ?? 'INTELLIHELP';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#663399">
    <meta name="robots" content="noindex, nofollow">
    <?php if ($u): ?><meta name="csrf" content="<?= e(csrf_token()) ?>"><?php endif; ?>
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link rel="icon" href="<?= $asset('assets/img/favicon.png') ?>">
    <link rel="apple-touch-icon" href="<?= $asset('assets/img/ITH-logo-80x80.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $asset('assets/css/app.css') ?>?v=<?= ASSETS_V ?>">
    <?php if (!empty($brand['primary']) || !empty($brand['accent'])): ?>
    <style>:root{<?php if (!empty($brand['primary'])): ?>--primary:<?= e($brand['primary']) ?>;<?php endif; ?><?php if (!empty($brand['accent'])): ?>--accent:<?= e($brand['accent']) ?>;<?php endif; ?>}</style>
    <?php endif; ?>
    <?php if ($u): ?>
    <script>
    // Auditoría desde el cliente: cualquier evento o error del navegador queda
    // registrado en el CRON, igual que los del servidor.
    window.CSRF = <?= json_encode(csrf_token()) ?>;
    window.appLog = function (a, d, t) {
        try {
            fetch(<?= json_encode(url('api-log')) ?>, {
                method: 'POST', keepalive: true,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'accion=' + encodeURIComponent(a || '') + '&detalle=' + encodeURIComponent(d || '') +
                      '&tipo=' + encodeURIComponent(t || 'accion') + '&_csrf=' + encodeURIComponent(window.CSRF || '')
            }).catch(function () {});
        } catch (e) {}
    };
    window.addEventListener('error', function (e) {
        if (window.appLog) window.appLog('js.error', (e.message || 'error') + ' @ ' + ((e.filename || '').split('/').pop()) + ':' + (e.lineno || ''), 'error');
    });
    window.addEventListener('unhandledrejection', function (e) {
        if (window.appLog) window.appLog('js.error', 'promesa: ' + ((e.reason && (e.reason.message || e.reason)) || 'rechazo'), 'error');
    });
    window.__sesion = {
        idle: <?= (int)($GLOBALS['CONFIG']['app']['idle_ttl'] ?? 600) ?>,
        abs: <?= (int)($GLOBALS['CONFIG']['app']['abs_ttl'] ?? 28800) ?>,
        loginAt: <?= (int)($_SESSION['login_en'] ?? time()) ?>,
        base: <?= json_encode(url('')) ?>
    };
    </script>
    <?php endif; ?>
    <?= $extraHead ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?php if ($u && $bodyClass === 'app'):
    $ini = iniciales((string)($u['nombre'] ?? '?'));
    // Las alertas nacen de las campañas o de la agenda: con los dos módulos
    // apagados no se muestra la campana.
    $verAlertas   = ruta_habilitada('campanas') || ruta_habilitada('agenda');
    $sinLeerBarra = $verAlertas ? notif_sin_leer((int)$u['id']) : 0;
    // Cuántas fichas esperan aprobación, al lado de su sección. Es el número que
    // se mira todo el día mientras se normaliza la base: tenerlo que ir a buscar
    // al tablero es un viaje de ida y vuelta por cada grupo que se aprueba.
    $pendientes = contar_pendientes();
    $contador = fn(int $n): string => $n > 999 ? '999+' : (string) $n;
?>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= $asset('dashboard') ?>">
            <img src="<?= $asset($brandLogo) ?>" alt="<?= e($brandOrg) ?>" class="brand-logo">
            <span><b><?= e($brandOrg) ?></b><small>CRM comercial</small></span>
        </a>
        <nav class="nav">
            <?php foreach ($nav as $r => [$etiqueta, $ic]):
                $n = (int) ($pendientes[$r] ?? 0); ?>
                <a href="<?= $asset($r) ?>" class="<?= $seccion === $r ? 'active' : '' ?>">
                    <?= icon($ic) ?><span><?= e($etiqueta) ?></span>
                    <?php if ($n): ?><em class="count" title="<?= $n ?> para aprobar"><?= $contador($n) ?></em><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if ($admin): ?>
                <div class="nav-title">Administración</div>
                <?php foreach ($admin as $r => $def): ?>
                    <?php if ($def === null) { continue; } ?>
                    <a href="<?= $asset($r) ?>" class="<?= $seccion === $r ? 'active' : '' ?>">
                        <?= icon($def[1]) ?><span><?= e($def[0]) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="me">
                <?php if (!empty($u['avatar_url'])): ?>
                    <img class="avatar" src="<?= e($u['avatar_url']) ?>" alt="" referrerpolicy="no-referrer">
                <?php else: ?>
                    <span class="avatar-fallback"><?= e($ini) ?></span>
                <?php endif; ?>
                <span>
                    <b><?= e($u['nombre']) ?></b>
                    <small><?= e($u['rol'] === 'admin' ? 'Administrador' : 'Comercial') ?></small>
                </span>
            </div>
            <a class="icon-btn" href="<?= $asset('logout') ?>" title="Cerrar sesión" aria-label="Cerrar sesión"><?= icon('salir', 18) ?></a>
        </div>
    </aside>
    <div class="scrim" data-close-nav></div>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-btn" type="button" data-toggle-nav aria-label="Menú" aria-controls="sidebar"><?= icon('menu', 22) ?></button>
            <div class="topbar-title"><?= e($title) ?></div>
            <div class="topbar-actions">
                <form class="gsearch" method="get" action="<?= $asset('buscar') ?>" role="search">
                    <?= icon('buscar', 16) ?>
                    <input type="search" name="q" placeholder="Buscar en el CRM…" value="<?= e($_GET['q'] ?? '') ?>" aria-label="Buscar">
                </form>
                <?php /* El número se actualiza solo: sin eso, la respuesta de un
                         cliente queda esperando a que alguien entre a mirar la
                         campaña, que es exactamente cuando no sirve. */ ?>
                <?php if ($verAlertas): ?>
                    <a class="icon-btn alertas-btn" href="<?= $asset('alertas') ?>" title="Alertas" data-alertas>
                        <?= icon('campanas', 18) ?>
                        <span class="alertas-n" data-alertas-n <?= $sinLeerBarra ? '' : 'hidden' ?>><?= $sinLeerBarra ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </header>
        <main class="content">
            <?php foreach (take_flash() as $f): ?>
                <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
        <footer class="foot"><?= e($appName) ?> · <?= e($brandOrg) ?> — <?= date('Y') ?></footer>
    </div>
</div>
<?php else: ?>
    <?= $content ?>
<?php endif; ?>
<?php /* Va en todas, también sin sesión: la página de reservar una reunión la
         usa gente de afuera y era justo donde el campo de teléfono aceptaba
         cualquier cosa, porque ahí no se cargaba ningún script. */ ?>
<script defer src="<?= $asset('assets/js/campos.js') ?>?v=<?= ASSETS_V ?>"></script>
<script defer src="<?= $asset('assets/js/selects.js') ?>?v=<?= ASSETS_V ?>"></script>
<?php if ($u): ?><script defer src="<?= $asset('assets/js/app.js') ?>?v=<?= ASSETS_V ?>"></script>
<script defer src="<?= $asset('assets/js/idle.js') ?>?v=<?= ASSETS_V ?>"></script>
<script defer src="<?= $asset('assets/js/vivo.js') ?>?v=<?= ASSETS_V ?>"></script>
<script defer src="<?= $asset('assets/js/secciones.js') ?>?v=<?= ASSETS_V ?>"></script><?php endif; ?>
<?= $extraFoot ?>
</body>
</html>

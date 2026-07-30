<?php
/** Layout principal. Requiere en scope: $content, $title. */
/** @var string $content @var string $title */
$u       = auth_user();
$ruta    = current_route();
$appName = $GLOBALS['CONFIG']['app']['name'] ?? 'CRM INTELLIHELP';

// La navegación agrupa por trabajo, no por tabla: primero lo que se usa todos
// los días, después lo de marketing, y al final lo de administración.
$nav = [
    'dashboard'   => ['Panel',      'panel'],
    'posibles'    => ['Posibles',   'posibles'],
    'contactos'   => ['Contactos',  'contactos'],
    'cuentas'     => ['Empresas',   'cuentas'],
    'embudo'      => ['Embudo',     'embudo'],
    'actividades' => ['Actividades','tareas'],
    'campanas'    => ['Campañas',   'campanas'],
    'agenda'      => ['Agenda',     'agenda'],
    'reportes'    => ['Reportes',   'reportes'],
];
if ($u && ($u['rol'] ?? '') === 'admin') {
    $nav['importar'] = ['Importar', 'importar'];
    $nav['usuarios'] = ['Usuarios', 'usuarios'];
    $nav['papelera'] = ['Papelera', 'papelera'];
    $nav['ajustes']  = ['Ajustes',  'ajustes'];
    $nav['cron']     = ['CRON',     'cron'];
}

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
    <link rel="stylesheet" href="<?= $asset('assets/css/app.css') ?>?v=1">
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
?>
    <header class="topbar">
        <div class="brand">
            <a href="<?= $asset('dashboard') ?>" aria-label="Inicio">
                <img src="<?= $asset($brandLogo) ?>" alt="<?= e($brandOrg) ?>" class="brand-logo">
            </a>
        </div>
        <button class="nav-toggle" aria-label="Menú" onclick="document.body.classList.toggle('nav-open')"><?= icon('menu', 20) ?></button>
        <nav class="topnav">
            <?php foreach ($nav as $r => [$etiqueta, $ic]): ?>
                <a href="<?= $asset($r) ?>" class="<?= $ruta === $r ? 'active' : '' ?>"><?= icon($ic) ?><?= e($etiqueta) ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="gsearch" method="get" action="<?= $asset('buscar') ?>" role="search">
            <?= icon('buscar', 16) ?>
            <input type="search" name="q" placeholder="Buscar en el CRM…" value="<?= e($_GET['q'] ?? '') ?>" aria-label="Buscar">
        </form>
        <div class="user-box">
            <div class="user-meta">
                <span class="user-name"><?= e($u['nombre']) ?></span>
                <span class="user-rol"><?= e($u['rol'] === 'admin' ? 'Administrador' : 'Comercial') ?></span>
            </div>
            <?php if (!empty($u['avatar_url'])): ?>
                <img class="avatar" src="<?= e($u['avatar_url']) ?>" alt="" referrerpolicy="no-referrer">
            <?php else: ?>
                <span class="avatar-fallback"><?= e($ini) ?></span>
            <?php endif; ?>
            <a class="btn-logout" href="<?= $asset('logout') ?>"><?= icon('salir', 16) ?>Salir</a>
        </div>
    </header>
    <main class="content">
        <?php foreach (take_flash() as $f): ?>
            <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
    <footer class="foot"><?= e($appName) ?> · <?= e($brandOrg) ?> — <?= date('Y') ?></footer>
<?php else: ?>
    <?= $content ?>
<?php endif; ?>
<?php if ($u): ?><script defer src="<?= $asset('assets/js/app.js') ?>?v=1"></script><?php endif; ?>
<?= $extraFoot ?>
</body>
</html>

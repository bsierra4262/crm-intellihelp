<?php
declare(strict_types=1);
require_admin();

// Canal: 'app' (lo que hace la gente + errores) | 'api' (pedidos a la API)
$canal = ($_GET['canal'] ?? 'app') === 'api' ? 'api' : 'app';

$f = [
    'canal' => $canal,
    'tipo'  => $_GET['tipo'] ?? '',
    'q'     => trim((string)($_GET['q'] ?? '')),
    'desde' => $_GET['desde'] ?? '',
    'hasta' => $_GET['hasta'] ?? '',
    'page'  => $_GET['page'] ?? 1,
];

render('cron', [
    'f'       => $f,
    'data'    => actividad_list($f),
    'resumen' => actividad_resumen($canal),
    'conteo'  => actividad_conteo_tipos($canal),
    'ultima'  => setting_get('cron_ultima_corrida', ''),
    // Estado del reloj. Todo lo que se agenda depende de que la aplicación y la
    // base coincidan: si discreparan, los recordatorios saldrían a destiempo y
    // la agenda pública ofrecería horarios ya pasados. Se muestra para poder
    // comprobarlo de un vistazo en vez de tener que entrar al servidor.
    'reloj'   => (function (): array {
        $fila = q1('SELECT NOW() n, @@session.time_zone tz');
        $desfase = abs(strtotime((string)$fila['n']) - time());
        return [
            'php'     => date('Y-m-d H:i:s'),
            'zona'    => date_default_timezone_get(),
            'base'    => (string)$fila['n'],
            'base_tz' => (string)$fila['tz'],
            'utc'     => gmdate('Y-m-d H:i:s'),
            'ok'      => $desfase <= 2,
            'desfase' => $desfase,
        ];
    })(),
], 'CRON');

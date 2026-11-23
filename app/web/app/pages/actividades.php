<?php
declare(strict_types=1);

// ============================================================
//  Página `actividades`: el listado de tareas, llamadas y reuniones.
//
//  GET dibuja views/actividades.php con actividades_listar()
//  (actividades.php). Vistas rápidas con `vista`: (vacía) pendientes, hoy,
//  semana, vencidas, completadas. `vencidas=1` es el atajo del contador del
//  panel.
//
//  Tablas: lee `actividades`, `usuarios`, `contactos`, `cuentas`.
//  Módulo: `actividades`. Permisos: cualquier usuario con sesión ve las de
//  todos cambiando el filtro de responsable.
// ============================================================

require_login();

// Por defecto se ven las de quien entra: si el parámetro no vino, el filtro
// de responsable es el usuario actual. `propietario_id=` vacío (la opción
// «Todos») sí muestra las de todo el equipo.
$f = [
    'q'              => trim((string)($_GET['q'] ?? '')),
    'tipo'           => $_GET['tipo'] ?? '',
    'estado'         => $_GET['estado'] ?? '',
    'propietario_id' => $_GET['propietario_id'] ?? (string)current_user_id(),
    'vista'          => $_GET['vista'] ?? '',
    'vencidas'       => $_GET['vencidas'] ?? '',
    'page'           => $_GET['page'] ?? 1,
];

render('actividades', [
    'f'        => $f,
    'data'     => actividades_listar($f),
    'usuarios' => users_activos(),
], 'Actividades');

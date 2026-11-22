<?php
declare(strict_types=1);

// ============================================================
//  Página `cuenta-ver?id=N`: la ficha de una empresa.
//
//  Junta los contactos de la empresa, sus negocios, notas, actividades y la
//  línea de tiempo de auditoría, y los pasa a views/cuenta-ver.php. No
//  recibe POST: las notas, adjuntos y etiquetas se mandan a sus propias rutas.
//
//  Tablas que lee: `cuentas`, `contactos`, `negocios`/`etapas`, `notas`,
//  `actividades`, `actividad` (auditoría).
//  Módulo: núcleo (Empresas); negocios, actividades y adjuntos se muestran
//  sólo si su módulo está encendido.
//  Permisos: cualquier usuario con sesión ve cualquier ficha.
// ============================================================

require_login();

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? cuenta_get($id) : null;
if (!$c) {
    http_response_code(404);
    render('error', ['codigo' => 404, 'titulo' => 'Empresa inexistente',
        'mensaje' => 'Puede que la hayan enviado a la papelera.'], 'No encontrado');
    exit;
}

// Lo que no está en marcha no se muestra. A diferencia de la ficha del
// contacto, acá negocios y actividades se consultan siempre, aunque el módulo
// esté apagado y la vista no los dibuje.
$verNegocios    = ruta_habilitada('negocios');
$verActividades = ruta_habilitada('actividades');
$verAdjuntos    = ruta_habilitada('adjunto');

render('cuenta-ver', [
    'verNegocios'    => $verNegocios,
    'verActividades' => $verActividades,
    'verAdjuntos'    => $verAdjuntos,
    'c'           => $c,
    // Todos los contactos de la empresa, sin límite ni paginación.
    'contactos'   => q('SELECT * FROM contactos WHERE cuenta_id = ? AND eliminado_en IS NULL
                        ORDER BY apellido, nombre', [$id])->fetchAll(),
    'negocios'    => q('SELECT n.*, e.nombre AS etapa, e.color, e.tipo AS etapa_tipo
                        FROM negocios n JOIN etapas e ON e.id = n.etapa_id
                        WHERE n.cuenta_id = ? AND n.eliminado_en IS NULL
                        ORDER BY n.creado_en DESC', [$id])->fetchAll(),
    'notas'       => notas_de('cuenta', $id),
    'actividades' => actividades_de('cuenta_id', $id),
    'linea'       => timeline('cuenta', $id),
], $c['nombre']);

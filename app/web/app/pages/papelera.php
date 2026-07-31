<?php
declare(strict_types=1);
require_admin();

$entidad = (string)($_GET['entidad'] ?? 'contacto');
$defs    = tablas_papelera();
if (!isset($defs[$entidad])) { $entidad = 'contacto'; }
$tabla = $defs[$entidad]['tabla'];

// El nombre visible depende de la tabla; se arma acá para que la vista no
// tenga que saber la estructura de cada módulo.
$campoNombre = match ($entidad) {
    'contacto'        => 'nombre_completo',
    'posible_cliente' => "TRIM(CONCAT(COALESCE(nombre,''), ' ', apellido))",
    'actividad'       => 'asunto',
    default           => 'nombre',
};

$dias = dias_papelera();

// `dias_restantes` se calcula en la base para que coincida exactamente con lo
// que después va a borrar el cron.
$rows = q("SELECT id, $campoNombre AS etiqueta, eliminado_en,
                  DATEDIFF(DATE_ADD(eliminado_en, INTERVAL ? DAY), NOW()) AS dias_restantes
           FROM $tabla WHERE eliminado_en IS NOT NULL
           ORDER BY eliminado_en DESC LIMIT 300", [$dias])->fetchAll();

// El motivo del archivado vive en la auditoría: se trae para poder distinguir
// lo que borró una persona de lo que archivó el sistema por un rebote.
$motivos = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    foreach (q("SELECT entidad_id, accion, detalle FROM actividad
                WHERE entidad = ? AND entidad_id IN ($ids)
                  AND accion IN (?, ?)
                ORDER BY id DESC",
               [$entidad, $entidad . '.archivar_rebote', $entidad . '.eliminar'])->fetchAll() as $a) {
        $motivos[(int)$a['entidad_id']] ??= [
            'automatico' => str_ends_with((string)$a['accion'], 'archivar_rebote'),
            'detalle'    => (string)$a['detalle'],
        ];
    }
}

$conteos = [];
foreach ($defs as $k => $d) {
    $conteos[$k] = (int) qv("SELECT COUNT(*) FROM {$d['tabla']} WHERE eliminado_en IS NOT NULL");
}

render('papelera', compact('entidad', 'defs', 'rows', 'conteos', 'dias', 'motivos'), 'Papelera');

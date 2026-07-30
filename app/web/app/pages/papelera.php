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

$rows = q("SELECT id, $campoNombre AS etiqueta, eliminado_en
           FROM $tabla WHERE eliminado_en IS NOT NULL
           ORDER BY eliminado_en DESC LIMIT 300")->fetchAll();

$conteos = [];
foreach ($defs as $k => $d) {
    $conteos[$k] = (int) qv("SELECT COUNT(*) FROM {$d['tabla']} WHERE eliminado_en IS NOT NULL");
}

render('papelera', compact('entidad', 'defs', 'rows', 'conteos'), 'Papelera');

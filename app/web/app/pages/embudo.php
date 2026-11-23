<?php
declare(strict_types=1);
// ============================================================
//  Ruta `embudo`: el tablero Kanban del embudo de ventas.
//
//  Una columna por etapa activa con sus negocios, la cantidad y el total por
//  columna. Filtros por GET: embudo_id (por defecto, el primero de embudos(),
//  que es el predeterminado), q y propietario_id. Los negocios se cambian de
//  etapa desde su ficha: el tablero no tiene arrastrar y soltar.
//
//  Módulo «negocios». Sólo lectura. Vista: views/embudo.php.
// ============================================================
require_login();

$embudosTodos = embudos();
if (!$embudosTodos) {
    // Sin embudos cargados, el administrador va directo a crearlos; el resto
    // recibe el aviso de a quién pedírselo.
    if (auth_is_admin()) { redirect('embudos'); }
    render('error', ['codigo' => 500, 'titulo' => 'Falta configurar el embudo',
        'mensaje' => 'Todavía no hay ningún embudo de ventas cargado. '
                   . 'Pedile a un administrador que cree uno desde Más → Embudos y etapas.'], 'Embudo');
    exit;
}
// El embudo pedido no se valida contra la lista: uno inexistente o inactivo
// muestra un tablero sin columnas.
$embudoId = (int)($_GET['embudo_id'] ?? $embudosTodos[0]['id']);

$f = [
    'q'              => trim((string)($_GET['q'] ?? '')),
    'propietario_id' => $_GET['propietario_id'] ?? '',
];

render('embudo', [
    'embudos'  => $embudosTodos,
    'embudoId' => $embudoId,
    'cols'     => embudo_kanban($embudoId, $f),
    'f'        => $f,
    'usuarios' => users_activos(),
], 'Embudo de ventas');

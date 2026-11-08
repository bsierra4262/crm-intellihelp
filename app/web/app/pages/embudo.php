<?php
declare(strict_types=1);
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

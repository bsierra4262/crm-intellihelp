<?php
declare(strict_types=1);
require_admin();
require_once APP . '/importador.php';

$resultado = null;
$error     = null;

if (is_post()) {
    verify_csrf();
    $archivo = $_FILES['archivo'] ?? null;
    $destino = (string)($_POST['destino'] ?? 'contactos');
    $simular = !empty($_POST['simular']);

    if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'No llegó ningún archivo. Revisá que pese menos que el límite del servidor.';
    } else {
        // El CSV se procesa desde el temporal y no se guarda: no queremos una
        // copia de la base de contactos colgada en el servidor.
        try {
            if ($destino === 'lista') {
                $listaId = (int)($_POST['lista_id'] ?? 0);
                if (!$listaId) {
                    throw new RuntimeException('Elegí a qué lista cargar los correos.');
                }
                $resultado = importar_lista_csv($archivo['tmp_name'], $listaId) + ['modo' => 'lista'];
            } else {
                $resultado = importar_contactos_zoho(
                    $archivo['tmp_name'],
                    $simular,
                    (int)($_POST['propietario_id'] ?? 0) ?: null
                ) + ['modo' => 'contactos'];
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            log_app('importar.error', ['tipo' => 'error', 'detalle' => $e->getMessage()]);
        }
    }
}

render('importar', [
    'resultado' => $resultado,
    'error'     => $error,
    'usuarios'  => users_activos(),
    'listas'    => listas_listar(),
    'lotes'     => q('SELECT l.*, u.nombre AS usuario FROM import_lotes l
                      LEFT JOIN usuarios u ON u.id = l.creado_por
                      ORDER BY l.id DESC LIMIT 15')->fetchAll(),
    'maxSubida' => ini_get('upload_max_filesize'),
], 'Importar');

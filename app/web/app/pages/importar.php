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
                if ($simular) {
                    // Antes esta rama ignoraba la casilla y guardaba igual: se
                    // marcaba «Simular primero» y los correos entraban lo mismo.
                    throw new RuntimeException(
                        'La simulación todavía no está disponible para cargar correos a una lista. '
                      . 'Destildá «Simular primero» para hacerlo de verdad, o usá el destino '
                      . '«Contactos y empresas», que sí simula.');
                }
                $resultado = importar_lista_csv($archivo['tmp_name'], $listaId) + ['modo' => 'lista'];
            } else {
                // Si no se eligió uno y el archivo no lo dice, queda a nombre
                // de quien importa. Un contacto sin propietario no aparece en
                // «Lo mío» de NADIE: se importaban 29.000 y el panel mostraba
                // cero, como si la importación no hubiera pasado.
                $resultado = importar_contactos_zoho(
                    $archivo['tmp_name'],
                    $simular,
                    (int)($_POST['propietario_id'] ?? 0) ?: (int) current_user_id()
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

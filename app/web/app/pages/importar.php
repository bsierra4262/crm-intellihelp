<?php
declare(strict_types=1);
// ============================================================
//  Ruta `importar` (sólo administradores): carga de archivos CSV.
//
//  Dos destinos (campo `destino`):
//    · 'contactos'  CSV de Zoho → contactos y empresas, con modo simulación
//                   (importar_contactos_zoho);
//    · 'lista'      correos sueltos → miembros de una lista de campaña
//                   (importar_lista_csv), sin simulación.
//  El archivo se procesa desde el temporal de PHP y no se guarda.
//
//  Es núcleo (modulos.php no la apaga). La vista esconde el destino 'lista'
//  cuando las campañas están apagadas, pero esta página lo acepta igual si
//  llega en el POST. No hay límite de tiempo propio: un archivo grande corre
//  con el max_execution_time del servidor.
//  Vista: views/importar.php, con las últimas 15 corridas de import_lotes.
// ============================================================
require_admin();
require_once APP . '/importador.php';

$resultado = null;
$error     = null;

if (is_post()) {
    verify_csrf();
    $archivo = $_FILES['archivo'] ?? null;
    $destino = (string)($_POST['destino'] ?? 'contactos');
    $simular = !empty($_POST['simular']);

    // Un archivo más grande que el límite (UPLOAD_ERR_INI_SIZE o FORM_SIZE)
    // llega acá con el mismo mensaje genérico.
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
                    // La carga a lista no tiene modo simulación: se corta acá para
                    // no guardar los correos con «Simular primero» marcado.
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

// listas_listar() es de campanas.php: se llama aunque el módulo de campañas
// esté apagado.
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

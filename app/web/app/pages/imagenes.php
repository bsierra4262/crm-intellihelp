<?php
declare(strict_types=1);
require_admin();

// ============================================================
//  Ruta: /imagenes  (sólo administradores; módulo «campanas»).
//  Galería de imágenes para las plantillas: subir y borrar.
//    · POST con accion=borrar → borra el archivo y la fila de `imagenes`.
//      No se fija si alguna plantilla la usa: esos correos quedan con la
//      imagen rota (el aviso lo dice).
//    · POST con archivo → valida y guarda.
//  Validación de la subida: el tipo se decide por getimagesize() (el
//  contenido real, no la extensión ni el tipo que manda el navegador); sólo
//  JPG, PNG, GIF o WEBP (nada de SVG, que puede traer código); tope de
//  400 KB; nombre en disco al azar con la extensión que corresponde al tipo
//  detectado. El nombre original sólo se guarda como etiqueta.
//  Los archivos los sirve la ruta pública `img` (pages/img.php).
// ============================================================

$aviso = null;

// Las imágenes se guardan en disco, fuera del web root, y se sirven por la
// ruta `img`. Incrustarlas en el correo como adjunto engordaría el mensaje y
// Gmail recorta a los 102 KB de HTML.
$dir = rtrim(STORAGE, '/') . '/imagenes';

if (is_post()) {
    verify_csrf();

    if (($_POST['accion'] ?? '') === 'borrar') {
        $im = q1('SELECT * FROM imagenes WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if ($im) {
            // `archivo` lo generó el propio sistema (hexadecimal + extensión),
            // así que no puede apuntar fuera de la carpeta.
            @unlink($dir . '/' . $im['archivo']);
            q('DELETE FROM imagenes WHERE id = ?', [(int)$im['id']]);
            log_app('imagen.borrar', ['detalle' => $im['nombre']]);
            flash('Imagen eliminada.', 'success');
        }
        redirect('imagenes');
    }

    $f = $_FILES['archivo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $aviso = ['error', 'No llegó ninguna imagen.'];
    } else {
        // Si no es una imagen, getimagesize devuelve false y $mime queda vacío.
        $info = @getimagesize($f['tmp_name']);
        $mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $mime  = $info['mime'] ?? '';
        if (!isset($mimes[$mime])) {
            $aviso = ['error', 'Sólo se aceptan imágenes JPG, PNG, GIF o WEBP.'];
        } elseif ($f['size'] > 400 * 1024) {
            // Tope propio: por encima de 100 KB la imagen empieza a pesar en la
            // entregabilidad y en el tiempo de carga del correo.
            $aviso = ['error', 'La imagen pesa ' . round($f['size'] / 1024) . ' KB. '
                     . 'El máximo son 400 KB, y lo ideal es que no pase de 100 KB: '
                     . 'las imágenes pesadas tardan en cargar y perjudican la entrega.'];
        } else {
            if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
            $archivo = bin2hex(random_bytes(10)) . '.' . $mimes[$mime];
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $archivo)) {
                $aviso = ['error', 'No se pudo guardar la imagen en el servidor.'];
            } else {
                q('INSERT INTO imagenes (nombre, archivo, mime, bytes, ancho, alto, creado_por)
                   VALUES (?, ?, ?, ?, ?, ?, ?)',
                  [mb_substr((string)$f['name'], 0, 255), $archivo, $mime, (int)$f['size'],
                   $info[0] ?? null, $info[1] ?? null, current_user_id()]);
                log_app('imagen.subir', ['detalle' => $f['name'] . ' · ' . round($f['size'] / 1024) . ' KB']);
                $aviso = ['success', 'Imagen subida. Ya la podés usar en las plantillas.'];
            }
        }
    }
}

render('imagenes', [
    'aviso' => $aviso,
    'rows'  => q('SELECT i.*, u.nombre AS autor FROM imagenes i
                  LEFT JOIN usuarios u ON u.id = i.creado_por
                  ORDER BY i.id DESC')->fetchAll(),
], 'Imágenes de las campañas');

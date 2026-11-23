<?php
declare(strict_types=1);

// ============================================================
//  Pantalla «no encontrada». No es una ruta: la incluye index.php cuando la
//  dirección pedida no figura en la tabla de rutas (ya con código 404).
//  Se dibuja con el layout; sin sesión, sale sin el marco del sistema.
// ============================================================
render('error', [
    'codigo'  => 404,
    'titulo'  => 'No encontramos esa página',
    'mensaje' => 'El enlace puede estar viejo o mal escrito.',
], 'No encontrado');

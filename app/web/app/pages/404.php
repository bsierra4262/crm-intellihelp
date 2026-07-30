<?php
declare(strict_types=1);
render('error', [
    'codigo'  => 404,
    'titulo'  => 'No encontramos esa página',
    'mensaje' => 'El enlace puede estar viejo o mal escrito.',
], 'No encontrado');

<?php
declare(strict_types=1);
require_login();
// ============================================================
//  Ruta: /plantillas  (con sesión; módulo «campanas»).
//  Listado de plantillas (incluidas las inactivas) y los diseños
//  prearmados para empezar una nueva. Pinta views/plantillas.php.
// ============================================================
render('plantillas', [
    'rows'       => plantillas_listar(),
    'preajustes' => plantillas_preajustes(),
], 'Plantillas de correo');

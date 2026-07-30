<?php
declare(strict_types=1);
require_login();
render('plantillas', ['rows' => plantillas_listar()], 'Plantillas de correo');

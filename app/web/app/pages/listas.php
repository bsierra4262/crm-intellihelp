<?php
declare(strict_types=1);
require_login();
render('listas', ['rows' => listas_listar()], 'Listas de destinatarios');

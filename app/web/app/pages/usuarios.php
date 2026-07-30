<?php
declare(strict_types=1);
require_admin();
render('usuarios', ['rows' => users_all()], 'Usuarios');

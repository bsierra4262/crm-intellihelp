<?php
declare(strict_types=1);

// ============================================================
//  Ruta: GET /usuarios (sólo administrador). Lista de usuarios con su rol,
//  estado, conexión con Google Calendar y último acceso. Los cambios se
//  guardan por POST a usuario-guardar. Vista: views/usuarios.php.
// ============================================================
require_admin();
render('usuarios', ['rows' => users_all()], 'Usuarios');

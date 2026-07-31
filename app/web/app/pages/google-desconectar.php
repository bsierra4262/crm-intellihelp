<?php
declare(strict_types=1);
require_login();

// Corta el acceso a Google Calendar. La función de revocación ya existía en
// google.php pero no había ninguna pantalla que la llamara: para desconectar
// había que entrar a la base de datos.

if (!is_post()) { redirect('agenda'); }
verify_csrf();

google_desconectar(current_user_id());
log_app('google.desconectar', ['tipo' => 'auth',
    'detalle' => 'Se desconectó Google Calendar de esta cuenta']);
flash('Google Calendar quedó desconectado. Las reuniones ya agendadas se conservan, '
    . 'pero la agenda pública deja de ofrecer horarios hasta que lo vuelvas a conectar.', 'info');
redirect('agenda');

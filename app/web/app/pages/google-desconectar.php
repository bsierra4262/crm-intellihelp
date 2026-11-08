<?php
declare(strict_types=1);
require_login();

// Desconecta la cuenta de Google Calendar del usuario actual
// (google_desconectar() en google.php revoca el acceso).

if (!is_post()) { redirect('agenda'); }
verify_csrf();

google_desconectar(current_user_id());
log_app('google.desconectar', ['tipo' => 'auth',
    'detalle' => 'Se desconectó Google Calendar de esta cuenta']);
flash('Google Calendar quedó desconectado. Las reuniones ya agendadas se conservan, '
    . 'pero la agenda pública deja de ofrecer horarios hasta que lo vuelvas a conectar.', 'info');
redirect('agenda');

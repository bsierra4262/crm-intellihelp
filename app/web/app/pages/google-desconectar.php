<?php
declare(strict_types=1);
require_login();

// Desconecta la cuenta de Google Calendar del usuario actual
// (google_desconectar() en google.php revoca el acceso).
//
// Ruta: POST google-desconectar, con sesión y token CSRF (el botón está en
// la pantalla de la agenda). Módulo: Agenda. Borra la fila del usuario en
// google_tokens; la sesión del CRM sigue abierta. Un GET vuelve a la agenda
// sin hacer nada. La línea de la auditoría la deja google_desconectar(): acá
// no se agrega otra (quedaban dos por cada desconexión).

if (!is_post()) { redirect('agenda'); }
verify_csrf();

google_desconectar(current_user_id());
flash('Google Calendar quedó desconectado. Las reuniones ya agendadas se conservan, '
    . 'pero la agenda pública deja de ofrecer horarios hasta que lo vuelvas a conectar.', 'info');
redirect('agenda');

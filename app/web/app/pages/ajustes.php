<?php
declare(strict_types=1);
require_admin();

$aviso = null;

if (is_post()) {
    verify_csrf();
    $accion = (string)($_POST['accion'] ?? 'guardar');

    if ($accion === 'probar_smtp') {
        $err = null;
        $aviso = smtp_probar($err)
            ? ['success', 'La conexión con el servidor de salida funciona.']
            : ['error', 'No se pudo conectar: ' . $err];
        log_app('ajustes.probar_smtp', ['detalle' => $aviso[1]]);

    } elseif ($accion === 'probar_imap') {
        $err = null;
        $aviso = imap_probar($err)
            ? ['success', 'La casilla de entrada responde bien.']
            : ['error', 'No se pudo abrir la casilla: ' . $err];
        log_app('ajustes.probar_imap', ['detalle' => $aviso[1]]);

    } elseif ($accion === 'revisar_casilla') {
        $r = imap_revisar();
        $aviso = $r['error']
            ? ['error', $r['error']]
            : ['success', sprintf('Revisados %d mensajes: %d rebotes y %d respuestas.',
                                  $r['revisados'], $r['rebotes'], $r['respuestas'])];

    } else {
        $claves = [
            'app_nombre', 'app_entorno', 'testing_destinatarios',
            'smtp_host', 'smtp_port', 'smtp_seguridad', 'smtp_user', 'smtp_from', 'smtp_from_name',
            'imap_host', 'imap_port', 'imap_user',
            'campana_pausa_min', 'campana_pausa_max', 'campana_limite_diario',
            'cron_clave',
        ];
        foreach ($claves as $k) {
            if (array_key_exists($k, $_POST)) {
                setting_set($k, trim((string)$_POST[$k]));
            }
        }
        // Las contraseñas sólo se pisan si escribieron una nueva: así el campo
        // puede quedar vacío en pantalla sin borrar lo guardado.
        foreach (['smtp_pass', 'imap_pass'] as $k) {
            if (trim((string)($_POST[$k] ?? '')) !== '') {
                setting_set($k, trim((string)$_POST[$k]));
            }
        }
        log_app('ajustes.guardar', ['detalle' => 'Se actualizaron los ajustes del sistema']);
        $aviso = ['success', 'Ajustes guardados.'];
    }
}

$s = static fn(string $k, string $def = '') => (string) setting_get($k, $def);

render('ajustes', [
    'aviso' => $aviso,
    's'     => $s,
    'smtp'  => smtp_configurado(),
    'imap'  => imap_configurado(),
    'cron'  => setting_get('cron_ultima_corrida', ''),
], 'Ajustes');

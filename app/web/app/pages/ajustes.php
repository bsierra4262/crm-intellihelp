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

    } elseif ($accion === 'limpiar_prueba') {
        // Saca los datos que sembró el sistema para probar el circuito, y NADA
        // más: los identificadores quedaron anotados al crearlos, así que no
        // hace falta adivinar cuáles eran. Lo que cargó una persona no se toca.
        $hizo = datos_prueba_limpiar();
        $aviso = $hizo
            ? ['success', 'Listo, se borraron: '
                . implode(' · ', array_map(fn($k, $v) => "$v $k", array_keys($hizo), $hizo))
                . '. Lo que cargaste vos quedó intacto.']
            : ['info', 'No quedaba ningún dato de prueba por borrar.'];

    } elseif ($accion === 'revisar_casilla') {
        // Todas las casillas activas, igual que el cron: si sólo se revisara la
        // predeterminada, los rebotes de las campañas que salen por las otras
        // cuentas no aparecerían nunca.
        $r = imap_revisar_todas();
        $aviso = $r['error']
            ? ['error', $r['error']]
            : ['success', sprintf('Revisadas %d casilla(s): %d mensajes, %d rebotes y %d respuestas.',
                                  $r['casillas'] ?? 1, $r['revisados'], $r['rebotes'], $r['respuestas'])];

    } else {
        $claves = [
            'app_nombre', 'app_entorno',
            'empresa_nombre', 'empresa_web', 'empresa_telefono', 'empresa_email', 'empresa_whatsapp',
            'campana_pausa_min', 'campana_pausa_max', 'campana_limite_diario',
            'papelera_dias', 'cron_clave', 'avisar_email_a',
        ];

        // Los interruptores no llegan cuando están apagados: hay que ponerlos
        // en cero a mano, o apagar uno no lo apagaría nunca.
        foreach (notif_tipos() as $t) {
            setting_set($t['ajuste'], empty($_POST[$t['ajuste']]) ? '0' : '1');
        }
        // Lo mismo con las partes del sistema: un interruptor apagado no llega
        // en el formulario, así que apagarlo no lo apagaría nunca.
        foreach (array_keys(modulos()) as $mod) {
            setting_set('modulo_' . $mod, empty($_POST['modulo_' . $mod]) ? '0' : '1');
        }
        foreach ($claves as $k) {
            if (array_key_exists($k, $_POST)) {
                setting_set($k, trim((string)$_POST[$k]));
            }
        }

        // La casilla por defecto se elige entre las cargadas en «Cuentas de
        // correo». Esta pantalla no tiene campos SMTP/IMAP propios, para que haya
        // una sola fuente de configuración.
        $elegida = (int)($_POST['cuenta_predeterminada'] ?? 0);
        if ($elegida > 0 && cuenta_correo_get($elegida)) {
            q('UPDATE cuentas_correo SET predeterminada = 0');
            q('UPDATE cuentas_correo SET predeterminada = 1 WHERE id = ?', [$elegida]);
        }

        log_app('ajustes.guardar', ['detalle' => 'Se actualizaron los ajustes del sistema']);
        $aviso = ['success', 'Ajustes guardados.'];
    }
}

$s = static fn(string $k, string $def = '') => (string) setting_get($k, $def);

render('ajustes', [
    'aviso'   => $aviso,
    's'       => $s,
    'smtp'    => smtp_configurado(),
    'imap'    => imap_configurado(),
    'cron'    => setting_get('cron_ultima_corrida', ''),
    'cuentas' => cuentas_correo_listar(),
], 'Ajustes');

<?php
declare(strict_types=1);

// ============================================================
//  Ajustes del sistema (ruta «ajustes», sólo administradores).
//
//  Una sola pantalla con varios formularios que llegan todos acá por POST y
//  se distinguen por el campo `accion`:
//    · guardar (o cualquier valor desconocido): el formulario grande. Guarda
//      en app_settings los datos generales, los de la empresa, el ritmo de las
//      campañas, la papelera, la clave del cron, los avisos por correo y qué
//      módulos están encendidos; y marca la casilla de correo por defecto en
//      cuentas_correo.
//    · probar_smtp / probar_imap: prueban la casilla por defecto (envio.php,
//      imap.php) y muestran el resultado.
//    · revisar_casilla: lee ya mismo rebotes y respuestas de todas las
//      casillas activas, sin esperar al cron.
//    · limpiar_prueba: borra los datos sembrados para probar
//      (datos_prueba.php).
//
//  No redirige después del POST: muestra el resultado en la misma respuesta
//  ($aviso) y vuelve a dibujar la pantalla. Recargar la página reenvía el
//  formulario (el navegador lo pregunta).
//
//  No pertenece a ningún módulo de modulos.php: está siempre disponible para
//  el administrador, porque es donde se encienden los demás.
//
//  Validación: los valores se guardan tal cual llegan, recortados. Los límites
//  del formulario (min, max, pattern) los controla sólo el navegador; quien
//  lee cada ajuste tiene que defenderse (por ejemplo, la papelera usa
//  max(1, ...)).
//
//  Vista: views/ajustes.php. Recibe $aviso, $s, $smtp, $imap, $cron y $cuentas.
// ============================================================

require_admin();

/** [tipo, texto] del aviso a mostrar arriba de la pantalla (tipo = clase flash-*), o null. */
$aviso = null;

if (is_post()) {
    verify_csrf();
    // Sin `accion` se toma como «guardar»: es lo que manda el formulario grande.
    $accion = (string)($_POST['accion'] ?? 'guardar');

    if ($accion === 'probar_smtp') {
        // El mensaje de error del servidor de correo se muestra tal cual (la
        // vista lo escapa) y queda en el registro de actividad.
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
        // Devuelve [qué => cuántos] de lo borrado; vacío si no había nada.
        // Ojo: el botón tiene data-confirmar, y la confirmación de app.js
        // envía con form.submit(), que no manda el name/value del botón; el
        // pedido llega con el `accion=guardar` del campo oculto y esta rama
        // no se ejecuta. Sin JavaScript sí llega bien.
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
        // Corre dentro de esta misma request: con muchas casillas o mensajes
        // puede tardar, y queda sujeto al tiempo máximo de PHP del hosting.
        $r = imap_revisar_todas();
        $aviso = $r['error']
            ? ['error', $r['error']]
            : ['success', sprintf('Revisadas %d casilla(s): %d mensajes, %d rebotes y %d respuestas.',
                                  $r['casillas'] ?? 1, $r['revisados'], $r['rebotes'], $r['respuestas'])];

    } else {
        // Ajustes de texto que se guardan tal cual vienen del formulario. Una
        // clave que no llega en el POST no se toca (no se borra). Ninguna se
        // valida acá: un número vacío o negativo, un correo mal escrito o una
        // clave de cron de un carácter se guardan igual.
        $claves = [
            'app_nombre', 'app_entorno',
            'empresa_nombre', 'empresa_web', 'empresa_telefono', 'empresa_email', 'empresa_whatsapp',
            'campana_pausa_min', 'campana_pausa_max', 'campana_limite_diario',
            'papelera_dias', 'cron_clave', 'avisar_email_a',
        ];

        // Los interruptores no llegan cuando están apagados: hay que ponerlos
        // en cero a mano, o apagar uno no lo apagaría nunca.
        // Trampa: se recorren TODOS los tipos, pero la vista no dibuja el
        // interruptor de 'sistema' (lo saltea). Ese ajuste (avisar_sistema)
        // nunca llega en el POST y queda en '0' cada vez que se guarda la
        // pantalla, aunque su valor por defecto sea '1'.
        foreach (notif_tipos() as $t) {
            setting_set($t['ajuste'], empty($_POST[$t['ajuste']]) ? '0' : '1');
        }
        // Lo mismo con las partes del sistema: un interruptor apagado no llega
        // en el formulario, así que apagarlo no lo apagaría nunca.
        // En modo prueba se guardan igual, aunque ahí no tengan efecto
        // (modulos.php enciende todo): rigen cuando se pase a producción.
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
        // Se acepta cualquier casilla que exista, también una inactiva. Las dos
        // UPDATE no van en una transacción: entre una y otra no hay ninguna
        // predeterminada.
        $elegida = (int)($_POST['cuenta_predeterminada'] ?? 0);
        if ($elegida > 0 && cuenta_correo_get($elegida)) {
            q('UPDATE cuentas_correo SET predeterminada = 0');
            q('UPDATE cuentas_correo SET predeterminada = 1 WHERE id = ?', [$elegida]);
        }

        log_app('ajustes.guardar', ['detalle' => 'Se actualizaron los ajustes del sistema']);
        $aviso = ['success', 'Ajustes guardados.'];
    }
}

/**
 * Atajo para la vista: lee un ajuste como texto, con valor por defecto.
 * Ojo: el defecto vale sólo si la clave no existe en app_settings; una clave
 * guardada vacía devuelve ''.
 */
$s = static fn(string $k, string $def = '') => (string) setting_get($k, $def);

render('ajustes', [
    'aviso'   => $aviso,
    's'       => $s,
    'smtp'    => smtp_configurado(),
    'imap'    => imap_configurado(),
    'cron'    => setting_get('cron_ultima_corrida', ''),
    'cuentas' => cuentas_correo_listar(),
], 'Ajustes');

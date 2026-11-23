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
//  Validación: ajustes_validar() (ajustes_validar.php) controla del lado del
//  servidor lo mismo que el navegador (y algo más): números con su rango,
//  pausa mínima ≤ máxima, papelera de al menos 7 días, clave del cron vacía o
//  de 24 caracteres o más, correos válidos y la confirmación aparte para
//  volver a «Prueba». Si algo está mal no se guarda NADA y la pantalla vuelve
//  con lo que se había escrito y la lista de problemas.
//
//  Vista: views/ajustes.php. Recibe $aviso, $s, $smtp, $imap, $cron y $cuentas.
// ============================================================

require_admin();
require_once APP . '/ajustes_validar.php';

/** Valores escritos en el formulario cuando no se pudieron guardar (para no perderlos). */
$escrito = [];

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
        // El botón tiene data-confirmar: app.js envía con requestSubmit(botón)
        // (o con un campo oculto equivalente), así que llega su
        // accion=limpiar_prueba y no el `accion=guardar` del campo oculto.
        // datos_prueba_limpiar() deja su propio registro en la auditoría.
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
        $actual = static fn(string $k, string $def = '') => (string) setting_get($k, $def);
        $r = ajustes_validar($_POST, $actual);

        // La casilla por defecto se elige entre las cargadas en «Cuentas de
        // correo». Esta pantalla no tiene campos SMTP/IMAP propios, para que
        // haya una sola fuente de configuración. Se acepta una casilla activa,
        // o la que ya era la predeterminada aunque esté inactiva (dejarla como
        // está no es elegirla). 0 = «no cambiar».
        $elegida = (int)($_POST['cuenta_predeterminada'] ?? 0);
        $cuenta  = $elegida > 0 ? cuenta_correo_get($elegida) : null;
        if ($elegida > 0 && (!$cuenta || (empty($cuenta['activo']) && empty($cuenta['predeterminada'])))) {
            $r['errores'][] = 'La casilla elegida como predeterminada no existe o está inactiva.';
        }

        if ($r['errores']) {
            // No se guarda nada: un formulario a medias guardado deja la
            // configuración en un estado que nadie eligió.
            $escrito = $_POST;
            $aviso = ['error', 'No se guardaron los ajustes. ' . implode(' ', $r['errores'])];
        } else {
            // Qué cambió, para el registro (sin mostrar valores: la clave del
            // cron no puede quedar escrita en la auditoría).
            $cambios = [];
            foreach ($r['valores'] as $k => $v) {
                if ($actual($k, "\0") !== $v) { $cambios[] = $k; }
                setting_set($k, $v);
            }

            // Los interruptores no llegan cuando están apagados: hay que
            // ponerlos en cero a mano, o apagar uno no lo apagaría nunca. Se
            // recorren sólo los que la vista dibuja (ajustes_tipos_aviso, sin
            // 'sistema'): uno que no se dibuja nunca llega y quedaría apagado
            // en cada guardado.
            foreach (ajustes_tipos_aviso() as $t) {
                $v = empty($_POST[$t['ajuste']]) ? '0' : '1';
                if ($actual($t['ajuste'], "\0") !== $v) { $cambios[] = $t['ajuste']; }
                setting_set($t['ajuste'], $v);
            }
            // Lo mismo con las partes del sistema: un interruptor apagado no
            // llega en el formulario, así que apagarlo no lo apagaría nunca.
            // En modo prueba se guardan igual, aunque ahí no tengan efecto
            // (modulos.php enciende todo): rigen cuando se pase a producción.
            foreach (array_keys(modulos()) as $mod) {
                $v = empty($_POST['modulo_' . $mod]) ? '0' : '1';
                if ($actual('modulo_' . $mod, "\0") !== $v) { $cambios[] = 'modulo_' . $mod; }
                setting_set('modulo_' . $mod, $v);
            }

            // Una sola sentencia: no hay un instante sin predeterminada.
            if ($cuenta && empty($cuenta['predeterminada'])) {
                q('UPDATE cuentas_correo SET predeterminada = CASE WHEN id = ? THEN 1 ELSE 0 END', [$elegida]);
                $cambios[] = 'cuenta_predeterminada';
            }

            // El cambio de modo queda con su propia línea en la auditoría:
            // volver a «Prueba» habilita reiniciar todo.
            if ($r['entorno'] === 'a_prueba') {
                log_app('ajustes.entorno_prueba', [
                    'detalle' => 'Modo de trabajo cambiado de Producción a Prueba (confirmado): vuelve a quedar habilitado reiniciar todo.']);
            } elseif ($r['entorno'] === 'a_produccion') {
                log_app('ajustes.entorno_produccion', [
                    'detalle' => 'Modo de trabajo cambiado de Prueba a Producción: queda bloqueado reiniciar todo.']);
            }

            log_app('ajustes.guardar', ['detalle' => $cambios
                ? 'Se actualizaron los ajustes: ' . implode(', ', $cambios)
                : 'Se guardaron los ajustes sin cambios']);
            $aviso = ['success', $r['entorno'] === 'a_prueba'
                ? 'Ajustes guardados. El sistema volvió a modo Prueba.'
                : 'Ajustes guardados.'];
        }
    }
}

/**
 * Atajo para la vista: lee un ajuste como texto, con valor por defecto.
 * Si el formulario no se pudo guardar, devuelve lo que se había escrito
 * (sólo campos de texto: los interruptores vuelven a su valor guardado).
 * Ojo: el defecto vale sólo si la clave no existe en app_settings; una clave
 * guardada vacía devuelve ''.
 */
$s = static function (string $k, string $def = '') use ($escrito): string {
    if (array_key_exists($k, $escrito) && is_string($escrito[$k])) { return trim($escrito[$k]); }
    return (string) setting_get($k, $def);
};

render('ajustes', [
    'aviso'   => $aviso,
    's'       => $s,
    'smtp'    => smtp_configurado(),
    'imap'    => imap_configurado(),
    'cron'    => setting_get('cron_ultima_corrida', ''),
    'cuentas' => cuentas_correo_listar(),
], 'Ajustes');

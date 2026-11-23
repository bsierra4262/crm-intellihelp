<?php
declare(strict_types=1);
require_login();

// ============================================================
//  Ruta: POST /campana-accion  (con sesión y CSRF; módulo «campanas»).
//  Los botones de la pantalla de la campaña (views/campana-ver.php):
//    preparar  → arma la cola (campana_preparar).
//    iniciar   → prepara la cola y pasa a 'enviando', o a 'programada' si
//                tiene fecha de arranque a futuro. Si arranca ya, manda el
//                primer correo después de contestar al navegador.
//    pausar    → pasa a 'pausada'; los correos pendientes esperan.
//    cancelar  → pasa a 'cancelada' y marca 'omitido' lo que no salió.
//  Siempre vuelve a campana-ver con un aviso (flash).
//
//  Permisos: preparar y pausar, cualquiera con sesión (no mandan nada).
//  Iniciar y cancelar, sólo el creador, el responsable o un admin
//  (campana_puede_gestionar); la vista oculta esos botones a los demás.
//  Cada acción sólo vale desde ciertos estados (campana_transicion_valida):
//  un POST directo ya no puede volver a poner a enviar una campaña
//  completada o cancelada. Iniciar exige además que no falte ningún paso
//  bloqueante (campana_lista_para_enviar), lo que incluye la cuenta de
//  salida de ESTA campaña.
//  Modo prueba: al iniciar, lo que está fuera de la lista blanca queda
//  omitido (campana_aplicar_lista_blanca) y se avisa cuántos.
// ============================================================

if (!is_post()) { redirect('campanas'); }
verify_csrf();

$id     = (int)($_POST['id'] ?? 0);
$accion = (string)($_POST['accion'] ?? '');
$c      = campana_get($id);

if (!$c) {
    flash('La campaña no existe.', 'error');
    redirect('campanas');
}

try {
    if (!in_array($accion, ['preparar', 'iniciar', 'pausar', 'cancelar'], true)) {
        throw new InvalidArgumentException('Acción desconocida.');
    }
    if (in_array($accion, ['iniciar', 'cancelar'], true) && !campana_puede_gestionar($c, auth_user())) {
        throw new InvalidArgumentException('Sólo quien creó la campaña, su responsable o un administrador puede '
            . ($accion === 'iniciar' ? 'iniciarla.' : 'cancelarla.'));
    }
    if (!campana_transicion_valida((string)$c['estado'], $accion)) {
        throw new InvalidArgumentException('No se puede ' . $accion . ' una campaña que está «' . $c['estado'] . '».');
    }

    switch ($accion) {
        case 'preparar':
            $r = campana_preparar($id);
            $detalle = $r['agregados'] . ' destinatarios en cola';
            if ($r['omitidos']) {
                $detalle .= ', ' . $r['omitidos'] . ' omitidos (' .
                    implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($r['motivos']), $r['motivos'])) . ')';
            }
            flash('Cola preparada: ' . $detalle . '.', 'success');
            break;

        case 'iniciar':
            // Los mismos pasos que muestra la pantalla: si falta uno bloqueante
            // (plantilla, lista, la cuenta de salida de esta campaña o la
            // agenda que usa la plantilla), no arranca.
            if (!campana_lista_para_enviar($c)) {
                $falta = array_filter(campana_pasos($c), fn($p) => $p['bloqueante'] && !$p['ok'] && $p['clave'] !== 'cola');
                throw new InvalidArgumentException('Falta resolver: '
                    . implode(' · ', array_map(fn($p) => $p['titulo'], $falta)) . '.');
            }
            // Preparar antes de arrancar evita el error clásico de iniciar una
            // campaña con la cola vacía porque se olvidaron el paso previo.
            campana_preparar($id);

            // Modo prueba: sólo sale a la lista blanca. Se avisa ahora, no
            // cuando la campaña aparezca con todo omitido.
            $fueraLista = campana_aplicar_lista_blanca($id, es_testing());
            $avisoPrueba = $fueraLista > 0
                ? ' Modo prueba: ' . $fueraLista . ' destinatario(s) fuera de la lista blanca quedaron omitidos.'
                : '';

            // Si tiene fecha de arranque a futuro, queda PROGRAMADA y el cron
            // la larga a su hora en lugar de mandar todo al apretar «Iniciar».
            $c = campana_get($id);
            $cuando = (string)($c['programada_para'] ?? '');
            if ($cuando !== '' && strtotime($cuando) > time()) {
                campana_estado($id, 'programada');
                flash('Campaña programada para el ' . fecha_hora($cuando)
                    . '. A esa hora arranca sola; no hace falta que dejes nada abierto.' . $avisoPrueba, 'success');
            } else {
                campana_estado($id, 'enviando');
                // El primero sale ahora, no cuando pase el cron: esperar hasta
                // cinco minutos con la pantalla en «enviando» parece un cuelgue.
                $arrancarYa = true;
                flash('Campaña iniciada. El primer correo sale ahora mismo; después uno cada '
                    . (int)$c['pausa_min_seg'] . '–' . (int)$c['pausa_max_seg']
                    . ' segundos. Podés cerrar el navegador: sigue sola.' . $avisoPrueba, 'success');
            }
            break;

        case 'pausar':
            // Un correo que el despachador ya tomó ('enviando') termina de
            // salir igual; la pausa frena los siguientes.
            campana_estado($id, 'pausada');
            flash('Campaña pausada. Podés reanudarla cuando quieras.', 'info');
            break;

        case 'cancelar':
            campana_estado($id, 'cancelada');
            q("UPDATE envios SET estado = 'omitido', motivo = 'Campaña cancelada'
               WHERE campana_id = ? AND estado = 'pendiente'", [$id]);
            campana_recontar($id);
            flash('Campaña cancelada.', 'info');
            break;
    }
} catch (\PDOException $e) {
    // Un error de la base no se le muestra a la persona (PDOException es una
    // RuntimeException: por eso va antes). Queda registrado con su ubicación.
    flash('No se pudo completar la acción. Quedó registrado el error; si se repite, avisale al administrador.', 'error');
    log_error('campana.' . $accion, get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
} catch (InvalidArgumentException | RuntimeException $e) {
    // Estos mensajes los escribimos nosotros para la persona: se muestran.
    flash('No se pudo completar: ' . $e->getMessage(), 'error');
    log_app('campana.accion_error', ['tipo' => 'error', 'entidad' => 'campana', 'entidad_id' => $id,
        'detalle' => $accion . ': ' . $e->getMessage()]);
} catch (\Throwable $e) {
    flash('No se pudo completar la acción. Quedó registrado el error; si se repite, avisale al administrador.', 'error');
    log_error('campana.' . $accion, get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
}

/**
 * Al arrancar a mano, el primer correo sale en el acto.
 *
 * La pantalla se contesta primero y el envío se hace después, con la respuesta
 * ya en el navegador: nadie espera a que el servidor SMTP conteste. Se manda
 * UNO solo y se corta; el resto lo sigue el cron con la pausa de siempre, que
 * es lo que evita que el proveedor marque el ritmo como robótico.
 *
 * Si el cron justo está corriendo y toma el mismo correo, no se manda dos
 * veces: cada envío se reclama con un UPDATE condicional y sólo uno gana.
 * El despachador aplica también acá la lista blanca del modo prueba.
 *
 * finish_request() cierra la respuesta al navegador; lo que sigue corre sin
 * nadie mirando y sin el turno del cron. Con presupuesto 0, el despachador
 * manda uno, anota cuándo puede salir el siguiente y corta.
 */
if (!empty($arrancarYa)) {
    header('Location: ' . url('campana-ver?id=' . $id));
    finish_request();
    try {
        campanas_despachar(1, 0, $id);
    } catch (\Throwable $e) {
        log_app('campana.arranque_error', ['tipo' => 'error', 'entidad' => 'campana', 'entidad_id' => $id,
            'detalle' => 'El primer correo no pudo salir en el acto: ' . $e->getMessage()
                       . '. Lo toma el cron en la próxima corrida.']);
    }
    exit;
}

redirect('campana-ver?id=' . $id);

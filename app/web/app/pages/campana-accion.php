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
//  Permisos: alcanza con tener sesión. No hay rol, ni chequeo de que la
//  campaña sea de quien aprieta, ni paso de aprobación.
//  Tampoco se valida la transición: «iniciar» funciona sobre una campaña
//  completada o cancelada (la vuelve a poner a enviar a quien falte), y no
//  se consulta campana_lista_para_enviar().
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
            // Mira la cuenta por defecto, no la que eligió la campaña: una
            // campaña con su propia cuenta activa no arranca si no hay
            // cuenta por defecto, y al revés pasa este control aunque su
            // cuenta esté mal.
            if (!smtp_configurado()) {
                throw new RuntimeException('Falta configurar la cuenta de salida en Ajustes.');
            }
            // Preparar antes de arrancar evita el error clásico de iniciar una
            // campaña con la cola vacía porque se olvidaron el paso previo.
            campana_preparar($id);

            // Si tiene fecha de arranque a futuro, queda PROGRAMADA y el cron
            // la larga a su hora en lugar de mandar todo al apretar «Iniciar».
            $c = campana_get($id);
            $cuando = (string)($c['programada_para'] ?? '');
            if ($cuando !== '' && strtotime($cuando) > time()) {
                campana_estado($id, 'programada');
                flash('Campaña programada para el ' . fecha_hora($cuando)
                    . '. A esa hora arranca sola; no hace falta que dejes nada abierto.', 'success');
            } else {
                campana_estado($id, 'enviando');
                // El primero sale ahora, no cuando pase el cron: esperar hasta
                // cinco minutos con la pantalla en «enviando» parece un cuelgue.
                $arrancarYa = true;
                flash('Campaña iniciada. El primer correo sale ahora mismo; después uno cada '
                    . (int)$c['pausa_min_seg'] . '–' . (int)$c['pausa_max_seg']
                    . ' segundos. Podés cerrar el navegador: sigue sola.', 'success');
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

        default:
            flash('Acción desconocida.', 'error');
    }
} catch (\Throwable $e) {
    flash('No se pudo completar: ' . $e->getMessage(), 'error');
    log_app('campana.accion_error', ['tipo' => 'error', 'entidad' => 'campana', 'entidad_id' => $id,
        'detalle' => $accion . ': ' . $e->getMessage()]);
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

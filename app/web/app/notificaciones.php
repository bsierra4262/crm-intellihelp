<?php
declare(strict_types=1);

// ============================================================
//  Alertas del sistema.
//
//  Sin esto, lo importante pasaría sin que nadie se entere: un cliente que
//  responde una campaña, alguien que agenda una reunión, un contacto que se
//  archiva por rebote definitivo; todo quedaría adentro de una pantalla que
//  hay que ir a mirar a propósito.
//
//  Son dos avisos distintos y conviene no confundirlos:
//    · en pantalla — la campana de la barra, que se actualiza sola;
//    · por correo  — lo manda el cron, y se elige qué avisar en Ajustes.
//
//  Una alerta sin `usuario_id` es para todo el equipo. Se usa cuando no hay un
//  responsable claro: si una campaña no tiene dueño, la respuesta le importa a
//  cualquiera que esté mirando.
// ============================================================

/** Los tipos que existen, con su ícono y su clave de configuración. */
function notif_tipos(): array
{
    return [
        'respuesta' => ['nombre' => 'Respuestas de clientes', 'icono' => 'campanas',
                        'ajuste' => 'avisar_respuesta',
                        'ayuda'  => 'Cuando alguien contesta un correo de campaña. Es la señal más '
                                  . 'importante que da el sistema: hay que llamar ese mismo día.'],
        'reunion'   => ['nombre' => 'Reuniones agendadas', 'icono' => 'agenda',
                        'ajuste' => 'avisar_reunion',
                        'ayuda'  => 'Cuando alguien reserva un horario desde el enlace público.'],
        'rebote'    => ['nombre' => 'Rebotes definitivos', 'icono' => 'alerta',
                        'ajuste' => 'avisar_rebote',
                        'ayuda'  => 'Cuando una dirección no existe y el contacto se archiva solo. '
                                  . 'Suele ser mucho volumen: conviene dejarlo apagado y mirarlo en la campaña.'],
        'campana'   => ['nombre' => 'Campañas terminadas', 'icono' => 'ok',
                        'ajuste' => 'avisar_campana',
                        'ayuda'  => 'Cuando una campaña termina de enviarse.'],
        'web'       => ['nombre' => 'Consultas desde la web', 'icono' => 'enlace',
                        'ajuste' => 'avisar_web',
                        'ayuda'  => 'Cuando alguien deja una consulta en el formulario de intellihelp.tech '
                                  . 'sin reservar un horario. Le llega a quien atiende las consultas de la web.'],
        'sistema'   => ['nombre' => 'Problemas del sistema', 'icono' => 'alerta',
                        'ajuste' => 'avisar_sistema',
                        'ayuda'  => 'Cuando algo deja de funcionar: Google Calendar sin responder, '
                                  . 'una casilla que rechaza la contraseña.'],
    ];
}

/**
 * Deja una alerta.
 *
 * Es a prueba de fallos a propósito: si por lo que sea no se puede escribir,
 * NO se rompe lo que la disparó. Una respuesta de un cliente tiene que quedar
 * registrada aunque el aviso falle.
 */
function notificar(string $tipo, string $titulo, array $d = []): void
{
    try {
        q('INSERT INTO notificaciones (usuario_id, tipo, titulo, detalle, entidad, entidad_id, url)
           VALUES (?, ?, ?, ?, ?, ?, ?)', [
            ($d['usuario_id'] ?? null) ?: null,
            mb_substr($tipo, 0, 40),
            mb_substr($titulo, 0, 200),
            isset($d['detalle']) ? mb_substr((string)$d['detalle'], 0, 500) : null,
            ($d['entidad'] ?? null) ?: null,
            ($d['entidad_id'] ?? null) ?: null,
            ($d['url'] ?? null) ?: null,
        ]);
    } catch (\Throwable $e) {
        error_log('[crm] no se pudo dejar la alerta: ' . $e->getMessage());
    }
}

/** Alertas que le corresponden a una persona: las suyas y las del equipo. */
function notif_de(int $usuarioId, bool $soloSinLeer = false, int $limite = 30): array
{
    $w = $soloSinLeer ? ' AND leida_en IS NULL' : '';
    return q("SELECT * FROM notificaciones
              WHERE (usuario_id = ? OR usuario_id IS NULL)$w
              ORDER BY id DESC LIMIT " . max(1, $limite), [$usuarioId])->fetchAll();
}

function notif_sin_leer(int $usuarioId): int
{
    return (int) qv('SELECT COUNT(*) FROM notificaciones
                     WHERE (usuario_id = ? OR usuario_id IS NULL) AND leida_en IS NULL', [$usuarioId]);
}

function notif_marcar_leidas(int $usuarioId, ?int $id = null): void
{
    if ($id) {
        q('UPDATE notificaciones SET leida_en = NOW()
           WHERE id = ? AND (usuario_id = ? OR usuario_id IS NULL) AND leida_en IS NULL', [$id, $usuarioId]);
        return;
    }
    q('UPDATE notificaciones SET leida_en = NOW()
       WHERE (usuario_id = ? OR usuario_id IS NULL) AND leida_en IS NULL', [$usuarioId]);
}

/**
 * Manda por correo las alertas que todavía no se avisaron. Lo llama el cron.
 *
 * Se agrupan en un solo correo por corrida: cinco reuniones agendadas en diez
 * minutos son cinco alertas en pantalla, pero un solo correo. Cinco correos
 * seguidos se leen como spam y se terminan ignorando.
 */
function notif_enviar_pendientes(): int
{
    $tipos = notif_tipos();
    $quiere = [];
    foreach ($tipos as $k => $t) {
        if ((string) setting_get($t['ajuste'], $k === 'rebote' ? '0' : '1') === '1') { $quiere[] = $k; }
    }
    if (!$quiere) { return 0; }

    $ph = implode(',', array_fill(0, count($quiere), '?'));
    $filas = q("SELECT * FROM notificaciones
                WHERE email_en IS NULL AND tipo IN ($ph)
                ORDER BY id LIMIT 50", $quiere)->fetchAll();
    if (!$filas) { return 0; }

    // A quién se le avisa: lo cargado en Ajustes, o los administradores activos.
    $a = array_filter(array_map('trim', explode(',', (string) setting_get('avisar_email_a', ''))));
    if (!$a) {
        $a = array_column(q("SELECT email FROM usuarios WHERE activo = 1 AND rol = 'admin'")->fetchAll(), 'email');
    }
    $a = array_values(array_filter($a, 'email_valido'));
    if (!$a || !smtp_configurado()) {
        // Sin destinatario o sin casilla no se puede avisar; se marcan igual
        // para no arrastrarlas indefinidamente en cada corrida.
        q('UPDATE notificaciones SET email_en = NOW() WHERE id IN ('
          . implode(',', array_map('intval', array_column($filas, 'id'))) . ')');
        return 0;
    }

    $bloques = [
        ['tipo' => 'encabezado', 'alto' => 34],
        ['tipo' => 'titulo', 'texto' => count($filas) === 1 ? 'Tenés una novedad' : 'Tenés ' . count($filas) . ' novedades'],
    ];
    foreach ($filas as $n) {
        $texto = $n['titulo'] . ($n['detalle'] ? "\n" . $n['detalle'] : '');
        if ($n['url']) { $texto .= "\n" . url_abs((string)$n['url']); }
        $bloques[] = ['tipo' => 'destacado', 'texto' => $texto];
    }
    $bloques[] = ['tipo' => 'texto',
                  'texto' => 'Podés ver todo junto en ' . url_abs('alertas') . ' , y elegir qué te avisamos '
                           . 'por correo desde Ajustes.'];
    $bloques[] = ['tipo' => 'contacto'];

    $ctx  = contexto_marca();
    $html = bloques_render($bloques, ['marca' => '#663399', 'preencabezado' => $filas[0]['titulo']], $ctx);
    $txt  = bloques_texto($bloques, $ctx);

    $asunto = count($filas) === 1
        ? (string)$filas[0]['titulo']
        : count($filas) . ' novedades en el CRM';

    $err = null;
    $ok  = enviar_correo_simple($a[0], $asunto, $html, $err,
        ['texto' => $txt] + (count($a) > 1 ? ['cc' => array_slice($a, 1)] : []));

    if (!$ok) {
        log_app('notif.email_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => (string)$err]);
        return 0;
    }
    q('UPDATE notificaciones SET email_en = NOW() WHERE id IN ('
      . implode(',', array_map('intval', array_column($filas, 'id'))) . ')');
    return count($filas);
}

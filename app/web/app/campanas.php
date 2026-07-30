<?php
declare(strict_types=1);

require_once __DIR__ . '/envio.php';

// ============================================================
//  Campañas de correo — el motor de Pulsemail llevado a web.
//
//  Diferencia clave con la app de escritorio: acá la campaña NO corre en la
//  máquina de nadie. Se arma una COLA (tabla `envios`) y un cron la despacha
//  de a poco. Si se corta la luz, si cierran el navegador o si se reinicia el
//  servidor, la campaña sigue por donde quedó, sin repetir un solo correo.
// ============================================================

// ------------------------------------------------------------
//  Plantillas
// ------------------------------------------------------------

function plantillas_listar(bool $soloActivas = false): array
{
    $w = 'WHERE eliminado_en IS NULL' . ($soloActivas ? ' AND activo = 1' : '');
    return q("SELECT * FROM plantillas $w ORDER BY carpeta IS NULL, carpeta, nombre")->fetchAll();
}

function plantilla_get(int $id): ?array
{
    return q1('SELECT * FROM plantillas WHERE id = ? AND eliminado_en IS NULL', [$id]);
}

function plantilla_guardar(array $d, ?int $id = null): int
{
    $campos = [
        'nombre'       => trim((string)($d['nombre'] ?? '')),
        'asunto'       => trim((string)($d['asunto'] ?? '')),
        'cuerpo_html'  => (string)($d['cuerpo_html'] ?? ''),
        'cuerpo_texto' => trim((string)($d['cuerpo_texto'] ?? '')) ?: null,
        'carpeta'      => $d['carpeta'] ?: null,
        'activo'       => !empty($d['activo']) ? 1 : 0,
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE plantillas SET $sets WHERE id = ?", [...array_values($campos), $id]);
        log_app('plantilla.editar', ['entidad' => 'plantilla', 'entidad_id' => $id, 'detalle' => $campos['nombre']]);
        return $id;
    }
    $campos['creado_por'] = current_user_id();
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO plantillas ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    log_app('plantilla.crear', ['entidad' => 'plantilla', 'entidad_id' => $nuevo, 'detalle' => $campos['nombre']]);
    return $nuevo;
}

/** Variables que se pueden usar en asunto y cuerpo. */
function variables_plantilla(): array
{
    return [
        '{{nombre}}'      => 'Nombre de pila del destinatario',
        '{{apellido}}'    => 'Apellido',
        '{{nombre_completo}}' => 'Nombre y apellido',
        '{{empresa}}'     => 'Empresa del destinatario',
        '{{cargo}}'       => 'Cargo',
        '{{email}}'       => 'Correo del destinatario',
        '{{remitente}}'   => 'Tu nombre',
        '{{agenda}}'      => 'Enlace a tu agenda para que reserve una reunión',
        '{{baja}}'        => 'Enlace para darse de baja (obligatorio)',
    ];
}

/** Reemplaza las variables de una plantilla por los datos del destinatario. */
function render_plantilla(string $texto, array $datos): string
{
    $nombre   = trim((string)($datos['nombre'] ?? ''));
    $apellido = trim((string)($datos['apellido'] ?? ''));
    $mapa = [
        '{{nombre}}'          => $nombre !== '' ? $nombre : 'Hola',
        '{{apellido}}'        => $apellido,
        '{{nombre_completo}}' => trim($nombre . ' ' . $apellido),
        '{{empresa}}'         => (string)($datos['empresa'] ?? ''),
        '{{cargo}}'           => (string)($datos['cargo'] ?? ''),
        '{{email}}'           => (string)($datos['email'] ?? ''),
        '{{remitente}}'       => (string)($datos['remitente'] ?? ''),
        '{{agenda}}'          => (string)($datos['agenda'] ?? ''),
        '{{baja}}'            => (string)($datos['baja'] ?? ''),
    ];
    return strtr($texto, $mapa);
}

// ------------------------------------------------------------
//  Listas de destinatarios
// ------------------------------------------------------------

function listas_listar(): array
{
    return q('SELECT l.*, (SELECT COUNT(*) FROM lista_miembros m WHERE m.lista_id = l.id) AS miembros
              FROM listas l WHERE l.eliminado_en IS NULL ORDER BY l.nombre')->fetchAll();
}

function lista_get(int $id): ?array
{
    return q1('SELECT * FROM listas WHERE id = ? AND eliminado_en IS NULL', [$id]);
}

function lista_guardar(array $d, ?int $id = null): int
{
    $nombre = trim((string)($d['nombre'] ?? ''));
    if ($id) {
        q('UPDATE listas SET nombre = ?, descripcion = ? WHERE id = ?', [$nombre, $d['descripcion'] ?: null, $id]);
        log_app('lista.editar', ['entidad' => 'lista', 'entidad_id' => $id, 'detalle' => $nombre]);
        return $id;
    }
    q('INSERT INTO listas (nombre, descripcion, creado_por) VALUES (?, ?, ?)',
      [$nombre, $d['descripcion'] ?: null, current_user_id()]);
    $nuevo = last_id();
    log_app('lista.crear', ['entidad' => 'lista', 'entidad_id' => $nuevo, 'detalle' => $nombre]);
    return $nuevo;
}

/**
 * Carga en una lista todos los contactos que cumplen un filtro del CRM.
 * Devuelve cuántos se agregaron (los repetidos se ignoran por la clave única).
 */
function lista_cargar_contactos(int $listaId, array $filtro): int
{
    [$where, $p] = contactos_where($filtro);
    $rows = q("SELECT ct.id, ct.nombre, ct.apellido, ct.email, cu.nombre AS empresa
               FROM contactos ct
               LEFT JOIN cuentas cu ON cu.id = ct.cuenta_id
               $where AND ct.email IS NOT NULL AND ct.email <> ''
                      AND ct.no_email = 0 AND ct.email_rebotado = 0", $p)->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        $st = q('INSERT IGNORE INTO lista_miembros (lista_id, contacto_id, email, nombre, empresa)
                 VALUES (?, ?, ?, ?, ?)',
                [$listaId, (int)$r['id'], normalizar_email($r['email']),
                 trim($r['nombre'] . ' ' . $r['apellido']), $r['empresa']]);
        $n += $st->rowCount();
    }
    log_app('lista.cargar', ['entidad' => 'lista', 'entidad_id' => $listaId,
        'detalle' => $n . ' contactos agregados desde el filtro del CRM']);
    return $n;
}

function lista_miembros(int $listaId, array $f = []): array
{
    $w = ['m.lista_id = ?'];
    $p = [$listaId];
    if (!empty($f['q'])) {
        $w[] = '(m.email LIKE ? OR m.nombre LIKE ? OR m.empresa LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like);
    }
    $where = 'WHERE ' . implode(' AND ', $w);
    $total = (int) qv("SELECT COUNT(*) FROM lista_miembros m $where", $p);
    $pg    = paginar($total, $f['page'] ?? 1, 50);
    $rows  = q("SELECT m.*, (d.email IS NOT NULL) AS dado_de_baja
                FROM lista_miembros m
                LEFT JOIN desuscripciones d ON d.email = m.email
                $where ORDER BY m.empresa, m.nombre LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

// ------------------------------------------------------------
//  Campañas
// ------------------------------------------------------------

function campanas_listar(array $f = []): array
{
    $w = ['c.eliminado_en IS NULL'];
    $p = [];
    if (!empty($f['estado'])) { $w[] = 'c.estado = ?'; $p[] = $f['estado']; }
    if (!empty($f['q']))      { $w[] = 'c.nombre LIKE ?'; $p[] = '%' . $f['q'] . '%'; }
    $where = 'WHERE ' . implode(' AND ', $w);
    $total = (int) qv("SELECT COUNT(*) FROM campanas c $where", $p);
    $pg    = paginar($total, $f['page'] ?? 1, 25);
    $rows  = q("SELECT c.*, l.nombre AS lista, pl.nombre AS plantilla, u.nombre AS propietario
                FROM campanas c
                LEFT JOIN listas l     ON l.id  = c.lista_id
                LEFT JOIN plantillas pl ON pl.id = c.plantilla_id
                LEFT JOIN usuarios u   ON u.id  = c.propietario_id
                $where ORDER BY c.creado_en DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p)->fetchAll();
    return $pg + ['rows' => $rows, 'total' => $total];
}

function campana_get(int $id): ?array
{
    return q1('SELECT c.*, l.nombre AS lista, pl.nombre AS plantilla, pl.asunto AS plantilla_asunto,
                      pl.cuerpo_html, pl.cuerpo_texto, u.nombre AS propietario
               FROM campanas c
               LEFT JOIN listas l      ON l.id  = c.lista_id
               LEFT JOIN plantillas pl ON pl.id = c.plantilla_id
               LEFT JOIN usuarios u    ON u.id  = c.propietario_id
               WHERE c.id = ? AND c.eliminado_en IS NULL', [$id]);
}

function campana_guardar(array $d, ?int $id = null): int
{
    $campos = [
        'nombre'           => trim((string)($d['nombre'] ?? '')),
        'tipo'             => $d['tipo'] ?: 'Correo masivo',
        'plantilla_id'     => $d['plantilla_id'] ?: null,
        'lista_id'         => $d['lista_id'] ?: null,
        'propietario_id'   => $d['propietario_id'] ?: current_user_id(),
        'remitente_nombre' => $d['remitente_nombre'] ?: null,
        'remitente_email'  => normalizar_email($d['remitente_email'] ?? '') ?: null,
        'responder_a'      => normalizar_email($d['responder_a'] ?? '') ?: null,
        'copia_a'          => $d['copia_a'] ?: null,
        'asunto'           => $d['asunto'] ?: null,
        'pausa_min_seg'    => max(5,  (int)($d['pausa_min_seg'] ?? 25)),
        'pausa_max_seg'    => max(10, (int)($d['pausa_max_seg'] ?? 45)),
        'limite_diario'    => max(1,  (int)($d['limite_diario'] ?? 300)),
        'enviar_desde'     => $d['enviar_desde'] ?: null,
        'enviar_hasta'     => $d['enviar_hasta'] ?: null,
        'programada_para'  => $d['programada_para'] ?: null,
        'descripcion'      => $d['descripcion'] ?: null,
    ];
    // La pausa mínima nunca puede superar a la máxima: si el usuario las
    // invierte, se ordenan solas en vez de romper el despachador.
    if ($campos['pausa_min_seg'] > $campos['pausa_max_seg']) {
        [$campos['pausa_min_seg'], $campos['pausa_max_seg']] = [$campos['pausa_max_seg'], $campos['pausa_min_seg']];
    }
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE campanas SET $sets WHERE id = ?", [...array_values($campos), $id]);
        log_app('campana.editar', ['entidad' => 'campana', 'entidad_id' => $id, 'detalle' => $campos['nombre']]);
        return $id;
    }
    $campos['creado_por'] = current_user_id();
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    q("INSERT INTO campanas ($cols) VALUES ($ph)", array_values($campos));
    $nuevo = last_id();
    log_app('campana.crear', ['entidad' => 'campana', 'entidad_id' => $nuevo, 'detalle' => $campos['nombre']]);
    return $nuevo;
}

/**
 * Arma la cola de la campaña a partir de su lista.
 *
 * Reglas de exclusión (en este orden), cada una con su motivo registrado:
 *   1. correo inválido
 *   2. dado de baja (lista negra global)
 *   3. contacto marcado «no incluir en campañas»
 *   4. correo que ya rebotó antes
 *   5. repetido dentro de la misma campaña (lo impide la clave única)
 *
 * Es idempotente: se puede volver a preparar sin duplicar ni reenviar.
 */
function campana_preparar(int $campanaId): array
{
    $c = campana_get($campanaId);
    if (!$c)                 { throw new RuntimeException('La campaña no existe.'); }
    if (!$c['lista_id'])     { throw new RuntimeException('La campaña no tiene una lista de destinatarios.'); }
    if (!$c['plantilla_id']) { throw new RuntimeException('La campaña no tiene una plantilla.'); }

    $res = ['agregados' => 0, 'omitidos' => 0, 'motivos' => []];
    $miembros = q('SELECT m.*, ct.no_email, ct.email_rebotado
                   FROM lista_miembros m
                   LEFT JOIN contactos ct ON ct.id = m.contacto_id
                   WHERE m.lista_id = ?', [(int)$c['lista_id']])->fetchAll();

    foreach ($miembros as $m) {
        $email  = normalizar_email($m['email']);
        $motivo = null;
        if (!email_valido($email))                                   { $motivo = 'Correo inválido'; }
        elseif (qv('SELECT 1 FROM desuscripciones WHERE email = ?', [$email])) { $motivo = 'Se dio de baja'; }
        elseif (!empty($m['no_email']))                              { $motivo = 'Marcado como «no incluir en campañas»'; }
        elseif (!empty($m['email_rebotado']))                        { $motivo = 'El correo rebotó antes'; }

        $st = q('INSERT IGNORE INTO envios (campana_id, contacto_id, email, nombre, empresa, estado, motivo, token, proximo_intento_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$campanaId, $m['contacto_id'] ?: null, $email, $m['nombre'], $m['empresa'],
                 $motivo ? 'omitido' : 'pendiente', $motivo, token32()]);
        if ($st->rowCount() > 0) {
            if ($motivo) {
                $res['omitidos']++;
                $res['motivos'][$motivo] = ($res['motivos'][$motivo] ?? 0) + 1;
            } else {
                $res['agregados']++;
            }
        }
    }

    campana_recontar($campanaId);
    log_app('campana.preparar', ['entidad' => 'campana', 'entidad_id' => $campanaId,
        'detalle' => $res['agregados'] . ' en cola, ' . $res['omitidos'] . ' omitidos']);
    return $res;
}

/** Recalcula los contadores de la campaña desde la cola real. */
function campana_recontar(int $campanaId): void
{
    q("UPDATE campanas c SET
         c.total       = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado <> 'omitido'),
         c.enviados    = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado = 'enviado'),
         c.rebotados   = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado IN ('rebotado','rechazado')),
         c.respondidos = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.respondido_en IS NOT NULL),
         c.errores     = (SELECT COUNT(*) FROM envios e WHERE e.campana_id = c.id AND e.estado = 'error')
       WHERE c.id = ?", [$campanaId]);
}

/** Cambia el estado de una campaña (iniciar, pausar, reanudar, cancelar). */
function campana_estado(int $id, string $estado): void
{
    $validos = ['borrador', 'programada', 'enviando', 'pausada', 'completada', 'cancelada'];
    if (!in_array($estado, $validos, true)) {
        throw new InvalidArgumentException('Estado inválido: ' . $estado);
    }
    $extra = '';
    if ($estado === 'enviando')   { $extra = ', inicio_en = COALESCE(inicio_en, NOW())'; }
    if ($estado === 'completada') { $extra = ', fin_en = NOW()'; }
    q("UPDATE campanas SET estado = ? $extra WHERE id = ?", [$estado, $id]);
    log_app('campana.' . $estado, ['entidad' => 'campana', 'entidad_id' => $id,
        'detalle' => 'La campaña pasó a «' . $estado . '»']);
}

/** ¿Estamos dentro de la ventana horaria permitida de la campaña? */
function campana_en_horario(array $c): bool
{
    if (empty($c['enviar_desde']) || empty($c['enviar_hasta'])) {
        return true;
    }
    $ahora = date('H:i:s');
    return $ahora >= $c['enviar_desde'] && $ahora <= $c['enviar_hasta'];
}

/** Cuántos correos se mandaron hoy en esta campaña (para el límite diario). */
function campana_enviados_hoy(int $campanaId): int
{
    return (int) qv('SELECT COUNT(*) FROM envios WHERE campana_id = ? AND DATE(enviado_en) = CURDATE()',
                    [$campanaId]);
}

/**
 * Arma el mensaje concreto para un destinatario de la campaña.
 * Se separa del despacho para poder mostrar una vista previa real en pantalla.
 */
function campana_armar_mensaje(array $c, array $envio): array
{
    $baja   = url_abs('baja?t=' . $envio['token']);
    $agenda = '';
    if (!empty($c['propietario_id'])) {
        $tipo = q1('SELECT t.slug, u.email FROM agenda_tipos t
                    JOIN usuarios u ON u.id = t.usuario_id
                    WHERE t.usuario_id = ? AND t.activo = 1 ORDER BY t.id LIMIT 1',
                   [(int)$c['propietario_id']]);
        if ($tipo) {
            $agenda = url_abs('r/' . explode('@', (string)$tipo['email'])[0] . '/' . $tipo['slug']);
        }
    }

    $partes = preg_split('/\s+/', trim((string)$envio['nombre'])) ?: [];
    $datos = [
        'nombre'    => $partes[0] ?? '',
        'apellido'  => count($partes) > 1 ? implode(' ', array_slice($partes, 1)) : '',
        'empresa'   => (string)($envio['empresa'] ?? ''),
        'email'     => (string)$envio['email'],
        'remitente' => (string)($c['remitente_nombre'] ?: $c['propietario'] ?: 'INTELLIHELP'),
        'agenda'    => $agenda,
        'baja'      => $baja,
    ];

    $asunto = render_plantilla((string)($c['asunto'] ?: $c['plantilla_asunto'] ?: ''), $datos);
    $html   = render_plantilla((string)($c['cuerpo_html'] ?? ''), $datos);
    $texto  = render_plantilla((string)($c['cuerpo_texto'] ?? ''), $datos);

    // El pie de baja es obligatorio: si la plantilla no usó {{baja}}, se agrega.
    if (stripos($html, $baja) === false) {
        $html .= '<hr style="border:none;border-top:1px solid #e7e4ef;margin:24px 0 12px">'
               . '<p style="font-family:Arial,sans-serif;font-size:11px;color:#8a8596;line-height:1.5">'
               . 'Recibís este correo porque estás en nuestra base comercial. '
               . '<a href="' . e($baja) . '" style="color:#663399">Darme de baja</a>.</p>';
    }
    if (trim($texto) === '') {
        $texto = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
    }
    if (stripos($texto, $baja) === false) {
        $texto .= "\n\n---\nPara no recibir más correos: " . $baja;
    }

    return [
        'para'        => (string)$envio['email'],
        'para_nombre' => (string)($envio['nombre'] ?? ''),
        'asunto'      => $asunto,
        'html'        => $html,
        'texto'       => $texto,
        'de'          => $c['remitente_email'] ?: null,
        'de_nombre'   => $c['remitente_nombre'] ?: null,
        'responder_a' => $c['responder_a'] ?: null,
        'cc'          => array_filter(array_map('trim', preg_split('/[;,\s]+/', (string)($c['copia_a'] ?? '')) ?: [])),
        'baja_url'    => $baja,
    ];
}

/**
 * Despacha correos pendientes. Lo llama el cron.
 *
 * Manda UNO por corrida y por campaña, y programa el siguiente con una pausa
 * aleatoria dentro del rango configurado. Ese rango (25–45 s por defecto) no es
 * capricho: por debajo, Gmail interpreta el ritmo como robótico y empieza a
 * marcar spam; por encima, la campaña se vuelve eterna.
 *
 * @return array resumen de lo hecho
 */
function campanas_despachar(int $maxPorCorrida = 5): array
{
    $res = ['enviados' => 0, 'rebotados' => 0, 'errores' => 0, 'omitidos' => 0, 'campanas' => []];

    $campanas = q("SELECT * FROM campanas
                   WHERE eliminado_en IS NULL AND estado IN ('enviando','programada')
                   ORDER BY inicio_en IS NULL, inicio_en")->fetchAll();

    $fp = null;
    foreach ($campanas as $c) {
        $cid = (int)$c['id'];

        // Programada que todavía no llegó su hora: no se toca.
        if ($c['estado'] === 'programada') {
            if (!empty($c['programada_para']) && strtotime((string)$c['programada_para']) > time()) {
                continue;
            }
            campana_estado($cid, 'enviando');
            $c['estado'] = 'enviando';
        }

        if (!campana_en_horario($c))                                    { continue; }
        if (campana_enviados_hoy($cid) >= (int)$c['limite_diario'])     { continue; }

        $pendientes = q("SELECT * FROM envios
                         WHERE campana_id = ? AND estado = 'pendiente'
                           AND (proximo_intento_en IS NULL OR proximo_intento_en <= NOW())
                         ORDER BY id LIMIT ?", [$cid, $maxPorCorrida])->fetchAll();

        if (!$pendientes) {
            $quedan = (int) qv("SELECT COUNT(*) FROM envios WHERE campana_id = ? AND estado = 'pendiente'", [$cid]);
            if ($quedan === 0) {
                campana_recontar($cid);
                campana_estado($cid, 'completada');
            }
            continue;
        }

        // Datos completos de la campaña (plantilla incluida) una sola vez.
        $completa = campana_get($cid);
        if (!$completa) { continue; }

        foreach ($pendientes as $envio) {
            // Chequeo de última hora: entre que se armó la cola y ahora, la
            // persona pudo haberse dado de baja.
            if (qv('SELECT 1 FROM desuscripciones WHERE email = ?', [$envio['email']])) {
                q("UPDATE envios SET estado = 'omitido', motivo = 'Se dio de baja antes del envío' WHERE id = ?",
                  [(int)$envio['id']]);
                $res['omitidos']++;
                continue;
            }

            // Modo testing: nunca sale un correo a un destinatario real.
            if (es_testing() && !destinatario_de_prueba((string)$envio['email'])) {
                q("UPDATE envios SET estado = 'omitido', motivo = 'Modo testing: destinatario no autorizado' WHERE id = ?",
                  [(int)$envio['id']]);
                $res['omitidos']++;
                continue;
            }

            if ($fp === null) {
                $err = null;
                $fp  = smtp_abrir($err);
                if (!$fp) {
                    log_app('campana.smtp_error', ['tipo' => 'error', 'entidad' => 'campana', 'entidad_id' => $cid,
                        'detalle' => (string)$err]);
                    return $res;   // sin SMTP no hay nada que hacer en esta corrida
                }
            }

            $msg = campana_armar_mensaje($completa, $envio);
            $msg['message_id'] = nuevo_message_id();
            $err = null;
            q("UPDATE envios SET estado = 'enviando', intentos = intentos + 1 WHERE id = ?", [(int)$envio['id']]);
            $resultado = smtp_enviar($fp, $msg, $err);

            if ($resultado === 'enviado') {
                q("UPDATE envios SET estado = 'enviado', enviado_en = NOW(), message_id = ?, motivo = NULL WHERE id = ?",
                  [$msg['message_id'], (int)$envio['id']]);
                q("INSERT INTO envio_eventos (envio_id, tipo) VALUES (?, 'enviado')", [(int)$envio['id']]);
                if (!empty($envio['contacto_id'])) {
                    q('UPDATE contactos SET ultima_actividad_en = NOW() WHERE id = ?', [(int)$envio['contacto_id']]);
                }
                $res['enviados']++;
            } elseif ($resultado === 'rechazado') {
                q("UPDATE envios SET estado = 'rechazado', motivo = ?, rebotado_en = NOW() WHERE id = ?",
                  [recortar($err, 250), (int)$envio['id']]);
                q("INSERT INTO envio_eventos (envio_id, tipo, detalle) VALUES (?, 'rebote', ?)",
                  [(int)$envio['id'], recortar($err, 400)]);
                marcar_rebote((string)$envio['email'], 'rechazo directo del servidor');
                $res['rebotados']++;
            } else {
                // Error temporal: se reintenta más tarde, con el tope de Pulsemail.
                $intentos = (int)$envio['intentos'] + 1;
                if ($intentos >= 20) {
                    q("UPDATE envios SET estado = 'error', motivo = ? WHERE id = ?",
                      [recortar('Sin éxito tras ' . $intentos . ' intentos: ' . $err, 250), (int)$envio['id']]);
                    $res['errores']++;
                } else {
                    q("UPDATE envios SET estado = 'pendiente', motivo = ?, proximo_intento_en = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?",
                      [recortar($err, 250), min(60, $intentos * 5), (int)$envio['id']]);
                }
                // Un error de conexión invalida la sesión SMTP: se reabre.
                smtp_cerrar($fp);
                $fp = null;
            }

            // Pausa aleatoria antes del siguiente de ESTA campaña.
            $pausa = random_int((int)$c['pausa_min_seg'], (int)$c['pausa_max_seg']);
            q('UPDATE envios SET proximo_intento_en = DATE_ADD(NOW(), INTERVAL ? SECOND)
               WHERE campana_id = ? AND estado = \'pendiente\'', [$pausa, $cid]);
            break;   // uno por campaña y por corrida: el ritmo lo marca el cron
        }

        campana_recontar($cid);
        $res['campanas'][] = $cid;
    }

    if ($fp) { smtp_cerrar($fp); }
    return $res;
}

/** En modo testing sólo se le puede escribir a la lista blanca interna. */
function destinatario_de_prueba(string $email): bool
{
    $lista = array_filter(array_map('trim', explode(',', (string)setting_get('testing_destinatarios', ''))));
    return in_array(normalizar_email($email), array_map('normalizar_email', $lista), true);
}

// ------------------------------------------------------------
//  Bajas y rebotes
// ------------------------------------------------------------

/** Da de baja un correo de todas las campañas presentes y futuras. */
function desuscribir(string $email, string $motivo = 'baja', ?int $campanaId = null, ?string $detalle = null): void
{
    $email = normalizar_email($email);
    q('INSERT INTO desuscripciones (email, motivo, campana_id, detalle, ip) VALUES (?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE motivo = VALUES(motivo), detalle = VALUES(detalle)',
      [$email, $motivo, $campanaId, $detalle, client_ip()]);
    q('UPDATE contactos SET no_email = 1 WHERE email = ?', [$email]);
    q('UPDATE posibles_clientes SET no_email = 1 WHERE email = ?', [$email]);
    q("UPDATE envios SET estado = 'omitido', motivo = 'Se dio de baja'
       WHERE email = ? AND estado = 'pendiente'", [$email]);
    if ($campanaId) {
        q('UPDATE campanas SET desuscriptos = desuscriptos + 1 WHERE id = ?', [$campanaId]);
    }
    log_app('campana.baja', ['tipo' => 'sistema', 'actor' => $email,
        'detalle' => 'Baja de las campañas · ' . $motivo . ($detalle ? ' · ' . $detalle : '')]);
}

/** Marca un correo como rebotado en todo el CRM. */
function marcar_rebote(string $email, string $detalle = ''): void
{
    $email = normalizar_email($email);
    q('UPDATE contactos SET email_rebotado = 1 WHERE email = ?', [$email]);
    q('UPDATE posibles_clientes SET email_rebotado = 1 WHERE email = ?', [$email]);
    q("UPDATE envios SET estado = 'rebotado', rebotado_en = NOW(), motivo = ?
       WHERE email = ? AND estado IN ('enviado','enviando')", [recortar($detalle, 250), $email]);
    log_app('campana.rebote', ['tipo' => 'sistema', 'actor' => $email,
        'detalle' => 'Rebote detectado' . ($detalle ? ': ' . $detalle : '')]);
}

/** Marca que un destinatario respondió (lo llama el lector IMAP). */
function marcar_respuesta(string $email, string $asunto = ''): void
{
    $email = normalizar_email($email);
    $envio = q1("SELECT id, campana_id FROM envios
                 WHERE email = ? AND estado = 'enviado' AND respondido_en IS NULL
                 ORDER BY enviado_en DESC LIMIT 1", [$email]);
    if (!$envio) { return; }
    q('UPDATE envios SET respondido_en = NOW() WHERE id = ?', [(int)$envio['id']]);
    q("INSERT INTO envio_eventos (envio_id, tipo, detalle) VALUES (?, 'respuesta', ?)",
      [(int)$envio['id'], recortar($asunto, 400)]);
    campana_recontar((int)$envio['campana_id']);
    log_app('campana.respuesta', ['tipo' => 'sistema', 'entidad' => 'campana',
        'entidad_id' => (int)$envio['campana_id'], 'actor' => $email,
        'detalle' => 'Respondió' . ($asunto !== '' ? ': ' . recortar($asunto, 120) : '')]);
}

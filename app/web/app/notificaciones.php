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
//
//  Tabla `notificaciones`: usuario_id (NULL = equipo), tipo, titulo, detalle,
//  entidad/entidad_id, url (ruta interna, sin BASE_PATH), creada_en,
//  leida_en (NULL = sin leer; sólo para las personales) y email_en (cuándo
//  salió por correo, o cuándo se descartó para el correo).
//  Tabla `notificacion_lecturas` (migración 010): quién leyó cada alerta del
//  equipo, una fila por alerta y persona.
//
//  Quién la usa: agenda.php, crm.php, campanas.php y feriados.php llaman a
//  notificar(); la campana del layout y pages/api-alertas.php cuentan las
//  sin leer; pages/alertas.php las lista; app/cron.php llama a
//  notif_enviar_pendientes().
//
//  Lectura por persona: una alerta del equipo que marca como leída una
//  persona sigue sin leer para las demás (tabla `notificacion_lecturas`).
//  Si la migración 010 todavía no se aplicó, se vuelve al comportamiento
//  anterior (la columna única `leida_en`, leída para todos).
// ============================================================

/**
 * Los tipos que existen, con su ícono y su clave de configuración.
 *
 * `ajuste` es la clave de `app_settings` que decide si ese tipo se avisa por
 * correo ('1' sí). Todas vienen encendidas salvo 'rebote'.
 *
 * @return array<string,array{nombre:string,icono:string,ajuste:string,ayuda:string}>
 */
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
                                  . 'sin reservar un horario. Le llega a quien atiende las consultas de la web '
                                  . '(o, si no hay nadie asignado, a la lista de avisos).'],
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
 *
 * @param string $tipo   una clave de notif_tipos()
 * @param string $titulo se recorta a 200 caracteres
 * @param array  $d      opcionales: usuario_id (sin él, es para el equipo),
 *                       detalle (500 car.), entidad, entidad_id, url (ruta
 *                       interna como 'contacto-ver?id=3')
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

/**
 * ¿Existe la tabla de lecturas por persona (migración 010)? Se pregunta una
 * vez por pedido. Sin ella, las alertas del equipo usan la columna única.
 *
 * @param bool $olvidar vuelve a preguntar (para las pruebas)
 */
function notif_lecturas_disponible(bool $olvidar = false): bool
{
    static $ok = null;
    if ($olvidar) { $ok = null; }
    if ($ok !== null) { return $ok; }
    try {
        q('SELECT 1 FROM notificacion_lecturas LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/**
 * Condición SQL de «sin leer para esta persona» sobre la alias `n`. Lleva un
 * `?` para el id del usuario cuando hay tabla de lecturas.
 *
 * @return array{0:string,1:array} SQL y parámetros
 */
function notif_sql_sin_leer(int $usuarioId): array
{
    if (!notif_lecturas_disponible()) {
        return ['n.leida_en IS NULL', []];
    }
    return ['((n.usuario_id IS NOT NULL AND n.leida_en IS NULL)
              OR (n.usuario_id IS NULL AND NOT EXISTS (
                    SELECT 1 FROM notificacion_lecturas l
                    WHERE l.notificacion_id = n.id AND l.usuario_id = ?)))', [$usuarioId]];
}

/**
 * Alertas que le corresponden a una persona: las suyas y las del equipo.
 *
 * Cada fila trae `leida_en` tal como la ve ESA persona: en una alerta del
 * equipo es cuándo la leyó ella (o null), no si la leyó otro.
 *
 * @param bool $soloSinLeer sólo las que no leyó
 * @param int  $limite      cuántas, de la más nueva a la más vieja
 */
function notif_de(int $usuarioId, bool $soloSinLeer = false, int $limite = 30): array
{
    $cols = 'n.id, n.usuario_id, n.tipo, n.titulo, n.detalle, n.entidad, n.entidad_id, n.url,
             n.email_en, n.creada_en';
    $params = [];
    if (notif_lecturas_disponible()) {
        $sel = "$cols, CASE WHEN n.usuario_id IS NULL THEN
                    (SELECT l.leida_en FROM notificacion_lecturas l
                     WHERE l.notificacion_id = n.id AND l.usuario_id = ?)
                    ELSE n.leida_en END AS leida_en";
        $params[] = $usuarioId;
    } else {
        $sel = "$cols, n.leida_en";
    }
    $params[] = $usuarioId;
    $w = '';
    if ($soloSinLeer) {
        [$cond, $p] = notif_sql_sin_leer($usuarioId);
        $w = ' AND ' . $cond;
        $params = array_merge($params, $p);
    }
    return q("SELECT $sel FROM notificaciones n
              WHERE (n.usuario_id = ? OR n.usuario_id IS NULL)$w
              ORDER BY n.id DESC LIMIT " . max(1, $limite), $params)->fetchAll();
}

/** Cuántas alertas sin leer tiene una persona (las suyas más las del equipo). */
function notif_sin_leer(int $usuarioId): int
{
    [$cond, $p] = notif_sql_sin_leer($usuarioId);
    return (int) qv("SELECT COUNT(*) FROM notificaciones n
                     WHERE (n.usuario_id = ? OR n.usuario_id IS NULL) AND $cond",
                    array_merge([$usuarioId], $p));
}

/**
 * Marca como leídas una alerta ($id) o todas las de la persona (sin $id).
 *
 * Las personales se marcan en `leida_en`; la condición de usuario impide
 * marcar las de otro. Las del equipo se marcan sólo para esta persona, en
 * `notificacion_lecturas`. Sin esa tabla (migración 010 sin aplicar) se
 * marcan en `leida_en`, para todos, como antes.
 */
function notif_marcar_leidas(int $usuarioId, ?int $id = null): void
{
    $porPersona = notif_lecturas_disponible();
    if ($id) {
        q('UPDATE notificaciones SET leida_en = NOW()
           WHERE id = ? AND ' . ($porPersona ? 'usuario_id = ?' : '(usuario_id = ? OR usuario_id IS NULL)')
          . ' AND leida_en IS NULL', [$id, $usuarioId]);
        if ($porPersona) {
            q('INSERT IGNORE INTO notificacion_lecturas (notificacion_id, usuario_id, leida_en)
               SELECT id, ?, NOW() FROM notificaciones WHERE id = ? AND usuario_id IS NULL',
              [$usuarioId, $id]);
        }
        return;
    }
    q('UPDATE notificaciones SET leida_en = NOW()
       WHERE ' . ($porPersona ? 'usuario_id = ?' : '(usuario_id = ? OR usuario_id IS NULL)')
      . ' AND leida_en IS NULL', [$usuarioId]);
    if ($porPersona) {
        q('INSERT IGNORE INTO notificacion_lecturas (notificacion_id, usuario_id, leida_en)
           SELECT n.id, ?, NOW() FROM notificaciones n
           WHERE n.usuario_id IS NULL AND NOT EXISTS (
                 SELECT 1 FROM notificacion_lecturas l
                 WHERE l.notificacion_id = n.id AND l.usuario_id = ?)',
          [$usuarioId, $usuarioId]);
    }
}

/**
 * Manda por correo las alertas que todavía no se avisaron. Lo llama el cron.
 *
 * Toma hasta 50 por corrida, sólo de los tipos con el aviso encendido.
 * A quién va cada una:
 *   · una alerta PERSONAL (con usuario_id) va a su dueño, si está activo y
 *     tiene un correo válido; si no, a la lista del equipo;
 *   · una alerta del EQUIPO va a la lista `avisar_email_a` de Ajustes
 *     (separada por comas) o, si no tiene ninguna dirección válida, a todos los administradores
 *     activos: el primero como destinatario y el resto en copia.
 * Se arma un correo por destinatario (notif_agrupar_destinos).
 *
 * Si falla un envío, sus alertas no se marcan y se reintentan en la corrida
 * siguiente. Las de tipos con el aviso apagado se marcan como ya tratadas
 * (email_en) sin mandarlas: así, si después se enciende el aviso, no sale de
 * golpe un correo con todo lo acumulado.
 *
 * @return int cuántas alertas se avisaron (0 si no se mandó nada)
 *
 * Se agrupan en un solo correo por destinatario y corrida: cinco reuniones
 * agendadas en diez minutos son cinco alertas en pantalla, pero un solo
 * correo. Cinco correos seguidos se leen como spam y se terminan ignorando.
 */
function notif_enviar_pendientes(): int
{
    $tipos = notif_tipos();
    $quiere = [];
    foreach ($tipos as $k => $t) {
        if ((string) setting_get($t['ajuste'], $k === 'rebote' ? '0' : '1') === '1') { $quiere[] = $k; }
    }

    // Lo de los tipos apagados (o de tipos que ya no existen) no se va a mandar
    // nunca: se marca para que no se acumule.
    if ($quiere) {
        $ph = implode(',', array_fill(0, count($quiere), '?'));
        q("UPDATE notificaciones SET email_en = NOW() WHERE email_en IS NULL AND tipo NOT IN ($ph)", $quiere);
    } else {
        q('UPDATE notificaciones SET email_en = NOW() WHERE email_en IS NULL');
        return 0;
    }

    $filas = q("SELECT * FROM notificaciones
                WHERE email_en IS NULL AND tipo IN ($ph)
                ORDER BY id LIMIT 50", $quiere)->fetchAll();
    if (!$filas) { return 0; }

    // La lista del equipo: lo cargado en Ajustes, o los administradores activos.
    // Sólo las direcciones VÁLIDAS cuentan: si no queda ninguna (campo vacío o
    // todas mal escritas) se pasa a los administradores. Antes una lista toda
    // inválida dejaba los avisos marcados como enviados sin mandarlos.
    $equipo = array_values(array_filter(array_map('trim', explode(',', (string) setting_get('avisar_email_a', ''))), 'email_valido'));
    if (!$equipo) {
        $equipo = array_values(array_filter(array_column(
            q("SELECT email FROM usuarios WHERE activo = 1 AND rol = 'admin'")->fetchAll(), 'email'), 'email_valido'));
    }

    // El correo de cada dueño de alerta personal (sólo usuarios activos).
    $duenos = [];
    $ids = array_values(array_unique(array_filter(array_map(fn($f) => (int)($f['usuario_id'] ?? 0), $filas))));
    if ($ids) {
        $m = implode(',', array_fill(0, count($ids), '?'));
        foreach (q("SELECT id, email FROM usuarios WHERE activo = 1 AND id IN ($m)", $ids)->fetchAll() as $u) {
            if (email_valido((string)$u['email'])) { $duenos[(int)$u['id']] = (string)$u['email']; }
        }
    }

    $grupos = notif_agrupar_destinos($filas, $duenos, $equipo);
    $marcar = static function (array $alertas): void {
        if (!$alertas) { return; }
        q('UPDATE notificaciones SET email_en = NOW() WHERE id IN ('
          . implode(',', array_map('intval', array_column($alertas, 'id'))) . ')');
    };

    // Sin casilla de salida no se puede avisar a nadie; las que no tienen a
    // quién ir tampoco. Se marcan igual para no arrastrarlas en cada corrida.
    if (!smtp_configurado()) {
        $marcar($filas);
        return 0;
    }
    $marcar($grupos['']['alertas'] ?? []);
    unset($grupos['']);

    $avisadas = 0;
    foreach ($grupos as $g) {
        if (notif_mandar_correo($g['a'], $g['alertas'])) {
            $marcar($g['alertas']);
            $avisadas += count($g['alertas']);
        }
    }
    return $avisadas;
}

/**
 * Reparte las alertas por destinatario. Función pura (sin base ni correo).
 *
 * @param array<int,array>   $filas  alertas (usuario_id, …)
 * @param array<int,string>  $duenos usuario_id => correo (sólo activos y válidos)
 * @param string[]           $equipo la lista del equipo (puede estar vacía)
 * @return array<string,array{a:string[],alertas:array}> clave '' = sin destinatario
 */
function notif_agrupar_destinos(array $filas, array $duenos, array $equipo): array
{
    $grupos = [];
    foreach ($filas as $f) {
        $uid = (int)($f['usuario_id'] ?? 0);
        $a = ($uid && isset($duenos[$uid])) ? [$duenos[$uid]] : $equipo;
        $clave = implode(',', array_map('strtolower', $a));
        $grupos[$clave] ??= ['a' => $a, 'alertas' => []];
        $grupos[$clave]['alertas'][] = $f;
    }
    return $grupos;
}

/** Arma y manda el correo de un grupo de alertas. True si salió. */
function notif_mandar_correo(array $a, array $filas): bool
{
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

    // El color de marca sale de `app.brand.primary` del config, como en las
    // pantallas; si falta o no es un color, el violeta de INTELLIHELP.
    $marca = (string)($GLOBALS['CONFIG']['app']['brand']['primary'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $marca)) { $marca = '#663399'; }
    $ctx  = contexto_marca();
    $html = bloques_render($bloques, ['marca' => $marca, 'preencabezado' => $filas[0]['titulo']], $ctx);
    $txt  = bloques_texto($bloques, $ctx);

    $asunto = count($filas) === 1
        ? (string)$filas[0]['titulo']
        : count($filas) . ' novedades en el CRM';

    $err = null;
    $ok  = enviar_correo_simple($a[0], $asunto, $html, $err,
        ['texto' => $txt] + (count($a) > 1 ? ['cc' => array_slice($a, 1)] : []));
    if (!$ok) {
        log_app('notif.email_error', ['tipo' => 'error', 'actor' => 'cron', 'detalle' => (string)$err]);
    }
    return $ok;
}

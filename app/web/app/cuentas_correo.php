<?php
declare(strict_types=1);

// ============================================================
//  Cuentas de correo.
//
//  Con varias cuentas se pueden correr campañas en paralelo, por dos motivos:
//
//   · Cada casilla tiene su propio tope diario: repartir el envío entre
//     cuentas multiplica el volumen sin forzar ninguna.
//   · La reputación es por remitente: si una campaña sale mal y una casilla
//     se quema, las otras siguen limpias.
//
//  Las contraseñas se guardan cifradas con el secreto de la aplicación:
//  son contraseñas de aplicación de Google, no hay que verlas nunca más.
// ============================================================

function cuentas_correo_listar(bool $soloActivas = false): array
{
    $w = 'WHERE cc.eliminado_en IS NULL' . ($soloActivas ? ' AND cc.activo = 1' : '');
    return q("SELECT cc.*, u.nombre AS usuario,
                     (SELECT COUNT(*) FROM envios e
                      WHERE e.cuenta_correo_id = cc.id AND DATE(e.enviado_en) = CURDATE()) AS enviados_hoy
              FROM cuentas_correo cc
              LEFT JOIN usuarios u ON u.id = cc.usuario_id
              $w ORDER BY cc.predeterminada DESC, cc.nombre")->fetchAll();
}

function cuenta_correo_get(int $id): ?array
{
    return q1('SELECT * FROM cuentas_correo WHERE id = ? AND eliminado_en IS NULL', [$id]);
}

/** La cuenta que se usa cuando la campaña no eligió ninguna. */
function cuenta_correo_predeterminada(): ?array
{
    return q1('SELECT * FROM cuentas_correo WHERE activo = 1 AND eliminado_en IS NULL
               ORDER BY predeterminada DESC, id LIMIT 1');
}

function cuenta_correo_guardar(array $d, ?int $id = null): int
{
    $campos = [
        'nombre'           => trim((string)($d['nombre'] ?? '')),
        'remitente'        => normalizar_email($d['remitente'] ?? ''),
        'remitente_nombre' => trim((string)($d['remitente_nombre'] ?? '')) ?: null,
        // Se guarda tal cual la escriben, con saltos de línea. Se convierte a
        // HTML recién al mandar: acá se conserva lo que la persona ve al editar.
        'firma_html'       => trim((string)($d['firma_html'] ?? '')) ?: null,
        'responder_a'      => normalizar_email($d['responder_a'] ?? '') ?: null,
        'smtp_host'        => trim((string)($d['smtp_host'] ?? 'smtp.gmail.com')),
        'smtp_port'        => max(1, (int)($d['smtp_port'] ?? 587)),
        'smtp_seguridad'   => ($d['smtp_seguridad'] ?? 'tls') === 'ssl' ? 'ssl' : 'tls',
        'smtp_user'        => trim((string)($d['smtp_user'] ?? '')),
        'imap_host'        => trim((string)($d['imap_host'] ?? 'imap.gmail.com')) ?: null,
        'imap_port'        => max(1, (int)($d['imap_port'] ?? 993)),
        'imap_user'        => trim((string)($d['imap_user'] ?? '')) ?: null,
        'limite_diario'    => max(1, (int)($d['limite_diario'] ?? 300)),
        'usuario_id'       => ($d['usuario_id'] ?? null) ?: null,
        'activo'           => !empty($d['activo']) ? 1 : 0,
        'predeterminada'   => !empty($d['predeterminada']) ? 1 : 0,
    ];

    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE cuentas_correo SET $sets WHERE id = ?", [...array_values($campos), $id]);
    } else {
        $cols = implode(', ', array_keys($campos));
        $ph   = implode(', ', array_fill(0, count($campos), '?'));
        q("INSERT INTO cuentas_correo ($cols) VALUES ($ph)", array_values($campos));
        $id = last_id();
    }

    // Las contraseñas sólo se pisan si escribieron una nueva: así el campo
    // puede quedar vacío en pantalla sin borrar la que ya estaba.
    foreach (['smtp_pass' => 'smtp_pass', 'imap_pass' => 'imap_pass'] as $campo => $col) {
        $v = trim((string)($d[$campo] ?? ''));
        if ($v !== '') {
            q("UPDATE cuentas_correo SET $col = ? WHERE id = ?", [cifrar($v), $id]);
        }
    }

    // Una sola predeterminada.
    if ($campos['predeterminada']) {
        q('UPDATE cuentas_correo SET predeterminada = 0 WHERE id <> ?', [$id]);
    }

    log_app('cuenta_correo.guardar', ['entidad' => 'cuenta_correo', 'entidad_id' => $id,
        'detalle' => $campos['nombre'] . ' · ' . $campos['remitente']]);
    return $id;
}

/** Traduce una fila de `cuentas_correo` a la forma que entiende el motor SMTP. */
function cuenta_a_config(array $c): array
{
    return [
        'host'        => $c['smtp_host'],
        'port'        => (int)$c['smtp_port'],
        'seguridad'   => $c['smtp_seguridad'],
        'user'        => $c['smtp_user'],
        'pass'        => descifrar((string)$c['smtp_pass']),
        'from'        => $c['remitente'],
        'from_name'   => ($c['remitente_nombre'] ?? null) ?: ($c['nombre'] ?? ''),
        'responder_a' => $c['responder_a'] ?? null,
        // Viaja con la configuración para que quien manda no tenga que ir a
        // buscarla: la firma es de la casilla, sale con todo lo que salga de ahí.
        'firma'       => $c['firma_html'] ?? null,
    ];
}

/** Config IMAP de una cuenta. Si no tiene propia, usa la de salida. */
function cuenta_a_imap(array $c): array
{
    return [
        'host' => ($c['imap_host'] ?? null) ?: 'imap.gmail.com',
        'port' => (int)(($c['imap_port'] ?? null) ?: 993),
        'user' => ($c['imap_user'] ?? null) ?: $c['smtp_user'],
        'pass' => descifrar((string)(($c['imap_pass'] ?? null) ?: $c['smtp_pass'])),
        // Viaja con la configuración para que, al guardar una respuesta, quede
        // anotado por qué casilla entró: contestarla tiene que salir por esa
        // misma, no por la de siempre.
        'cuenta_id' => (int)($c['id'] ?? 0) ?: null,
    ];
}

/** ¿A la cuenta le queda cupo hoy? */
function cuenta_tiene_cupo(array $c): bool
{
    $hoy = (int) qv('SELECT COUNT(*) FROM envios WHERE cuenta_correo_id = ? AND DATE(enviado_en) = CURDATE()',
                    [(int)$c['id']]);
    return $hoy < (int)$c['limite_diario'];
}

/** Prueba la conexión de una cuenta y deja constancia del resultado. */
function cuenta_probar(int $id, ?string &$err = null): bool
{
    $c = cuenta_correo_get($id);
    if (!$c) { $err = 'La cuenta no existe.'; return false; }
    $fp = smtp_abrir($err, cuenta_a_config($c));
    if ($fp) {
        smtp_cerrar($fp);

        // También la casilla de entrada: probando sólo la salida, una
        // contraseña mal cargada en la entrada daría «cuenta verificada» y
        // los rebotes no se leerían nunca.
        $errIn = null;
        $imap  = cuenta_a_imap($c);
        $entrada = (!empty($imap['user']) && !empty($imap['pass']))
                 ? imap_probar($errIn, $imap)
                 : true;

        q('UPDATE cuentas_correo SET verificada_en = NOW(), ultimo_error = ? WHERE id = ?',
          [$entrada ? null : recortar('Casilla de entrada: ' . $errIn, 250), $id]);
        log_app('cuenta_correo.probar', ['entidad' => 'cuenta_correo', 'entidad_id' => $id,
            'detalle' => 'Salida correcta con ' . $c['remitente']
                       . ($entrada ? ' · entrada correcta' : ' · la entrada falló: ' . $errIn)]);
        if (!$entrada) {
            $err = 'El envío funciona, pero no se pudo abrir la casilla de entrada: ' . $errIn
                 . ' Sin eso no se detectan los rebotes.';
            return false;
        }
        return true;
    }
    q('UPDATE cuentas_correo SET ultimo_error = ? WHERE id = ?', [recortar($err, 250), $id]);
    log_app('cuenta_correo.error', ['tipo' => 'error', 'entidad' => 'cuenta_correo', 'entidad_id' => $id,
        'detalle' => (string)$err]);
    return false;
}

function cuenta_correo_eliminar(int $id): void
{
    q('UPDATE cuentas_correo SET eliminado_en = NOW(), activo = 0 WHERE id = ?', [$id]);
    log_app('cuenta_correo.eliminar', ['entidad' => 'cuenta_correo', 'entidad_id' => $id,
        'detalle' => 'Cuenta de correo dada de baja']);
}

// ------------------------------------------------------------
//  Categorías (segmentos)
// ------------------------------------------------------------

function categorias_listar(): array
{
    return q('SELECT c.*, (SELECT COUNT(*) FROM listas l
                           WHERE l.categoria_id = c.id AND l.eliminado_en IS NULL) AS listas
              FROM categorias c ORDER BY c.orden, c.nombre')->fetchAll();
}

function categoria_asegurar(string $nombre): ?int
{
    $nombre = trim($nombre);
    if ($nombre === '') { return null; }
    $c = q1('SELECT id FROM categorias WHERE nombre = ?', [$nombre]);
    if ($c) { return (int)$c['id']; }
    q('INSERT INTO categorias (nombre) VALUES (?)', [mb_substr($nombre, 0, 80)]);
    return last_id();
}

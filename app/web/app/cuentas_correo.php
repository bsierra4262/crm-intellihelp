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
//
//  Cifrado: cifrar()/descifrar() de google.php, AES-256-GCM con la clave
//  derivada (sha256) de `app.secret` de la configuración. Si ese secreto no
//  está, se deriva del nombre y el usuario de la base (débil). Si el secreto
//  cambia, descifrar() devuelve '' y las cuentas quedan «sin contraseña»: la
//  pantalla de cuentas lo marca con «Recargar contraseña» en cada una, y hay
//  que volver a cargarlas.
//
//  Módulo: Campañas. Quién lo usa: pages/cuentas-correo.php (alta, edición,
//  prueba y baja; sólo administradores), envio.php (smtp_cfg), imap.php,
//  mensajes.php, el despachador de campañas, Ajustes y campana-form.
//
//  Tablas: `cuentas_correo` (migracion-002-campanas.sql, + firma_html en la
//  005); lee `envios` para el cupo diario y `usuarios` para el dueño. Al final
//  están también las funciones de `categorias` (segmentos de listas), que no
//  tienen que ver con el correo pero viven acá desde la migración 002.
//
//  Decisiones:
//    · `remitente` tiene clave única y la baja es lógica (eliminado_en): una
//      cuenta dada de baja sigue ocupando su dirección. Volver a cargarla la
//      REACTIVA (misma fila, datos nuevos) en vez de chocar con la clave; una
//      dirección que ya usa otra cuenta viva da un error claro
//      (CuentaCorreoRepetida), nunca un 500.
//    · El alta escribe `smtp_pass` en el mismo INSERT ('' si no vino): la
//      columna es NOT NULL y, con MySQL en modo estricto, omitirla fallaba.
// ============================================================

/** Error de validación de una cuenta (dirección repetida): el texto se puede mostrar tal cual. */
class CuentaCorreoRepetida extends RuntimeException {}

/**
 * Las cuentas de correo no dadas de baja, con el nombre del dueño y cuántos
 * correos salieron hoy por cada una (subconsulta por fila sobre `envios`).
 * Primero la predeterminada, después por nombre.
 *
 * @param bool $soloActivas true = sólo las que tienen activo = 1
 * @return array<int,array> filas completas (incluye las contraseñas cifradas)
 */
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

/** Una cuenta por id, si no está dada de baja (activa o no). Null si no existe. */
function cuenta_correo_get(int $id): ?array
{
    return q1('SELECT * FROM cuentas_correo WHERE id = ? AND eliminado_en IS NULL', [$id]);
}

/**
 * La cuenta que se usa cuando la campaña no eligió ninguna: la marcada como
 * predeterminada o, si ninguna activa lo está, la activa más antigua.
 */
function cuenta_correo_predeterminada(): ?array
{
    return q1('SELECT * FROM cuentas_correo WHERE activo = 1 AND eliminado_en IS NULL
               ORDER BY predeterminada DESC, id LIMIT 1');
}

/**
 * Da de alta o actualiza una cuenta de correo con lo que vino del formulario.
 *
 * No valida nombre ni formato: eso lo chequea la página antes de llamar.
 * Las contraseñas se cifran y sólo se escriben si vinieron con algo (en el
 * alta, sin contraseña queda ''). Si la cuenta queda como predeterminada, se
 * les saca la marca a todas las demás. Deja registro en la auditoría.
 *
 * Remitente repetido:
 *   · alta con la dirección de una cuenta dada de baja → se reactiva esa
 *     fila con los datos nuevos (las contraseñas viejas se conservan si no
 *     se escribieron otras) y se devuelve su id;
 *   · alta o edición con la dirección de otra cuenta viva, o edición con la
 *     de una dada de baja → CuentaCorreoRepetida con un texto para mostrar.
 *
 * @param array    $d  campos del formulario ($_POST)
 * @param int|null $id cuenta a editar; null = alta
 * @return int id de la cuenta
 * @throws CuentaCorreoRepetida si la dirección ya es de otra cuenta
 */
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

    // ¿La dirección ya la tiene otra fila (viva o dada de baja)?
    $otra = q1('SELECT id, eliminado_en FROM cuentas_correo WHERE remitente = ? AND id <> ?',
               [$campos['remitente'], (int)$id]);
    $reactivada = false;
    if ($otra) {
        if (!$id && $otra['eliminado_en'] !== null) {
            // Alta de una casilla que se había dado de baja: se reactiva.
            $id = (int)$otra['id'];
            $reactivada = true;
        } elseif ($otra['eliminado_en'] !== null) {
            throw new CuentaCorreoRepetida('Esa dirección es de una cuenta dada de baja. Para volver a usarla, '
                . 'cargala como cuenta nueva: se reactiva con los datos que pongas.');
        } else {
            throw new CuentaCorreoRepetida('Ya hay otra cuenta con la dirección ' . $campos['remitente'] . '.');
        }
    }

    // Las contraseñas sólo se pisan si escribieron una nueva: así el campo
    // puede quedar vacío en pantalla sin borrar la que ya estaba.
    $claves = [];
    foreach (['smtp_pass', 'imap_pass'] as $col) {
        $v = trim((string)($d[$col] ?? ''));
        if ($v !== '') { $claves[$col] = cifrar($v); }
    }

    // Los nombres de columna salen de las claves fijas de $campos, nunca del
    // formulario: armar el SQL con ellos no abre una inyección.
    try {
        if ($id) {
            $todo = $campos + $claves;
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($todo)))
                  . ($reactivada ? ', eliminado_en = NULL' : '');
            q("UPDATE cuentas_correo SET $sets WHERE id = ?", [...array_values($todo), $id]);
        } else {
            // smtp_pass es NOT NULL: va siempre en el INSERT.
            $todo = $campos + $claves + ['smtp_pass' => ''];
            $cols = implode(', ', array_keys($todo));
            $ph   = implode(', ', array_fill(0, count($todo), '?'));
            q("INSERT INTO cuentas_correo ($cols) VALUES ($ph)", array_values($todo));
            $id = last_id();
        }
    } catch (\PDOException $e) {
        // Dos altas simultáneas con la misma dirección: la segunda choca con
        // la clave única. Se informa igual que arriba.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new CuentaCorreoRepetida('Ya hay otra cuenta con la dirección ' . $campos['remitente'] . '.');
        }
        throw $e;
    }

    // Una sola predeterminada.
    if ($campos['predeterminada']) {
        q('UPDATE cuentas_correo SET predeterminada = 0 WHERE id <> ?', [$id]);
    }

    log_app($reactivada ? 'cuenta_correo.reactivar' : 'cuenta_correo.guardar',
        ['entidad' => 'cuenta_correo', 'entidad_id' => $id,
         'detalle' => $campos['nombre'] . ' · ' . $campos['remitente'] . ($reactivada ? ' (reactivada)' : '')]);
    return $id;
}

/**
 * Traduce una fila de `cuentas_correo` a la forma que entiende el motor SMTP
 * (envio.php). La contraseña sale ya descifrada: el arreglo devuelto no se
 * tiene que guardar ni mostrar. 'firma' es texto plano con saltos de línea,
 * aunque la columna se llame firma_html.
 */
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

/**
 * Config IMAP de una cuenta. Si no tiene propia, usa la de salida (usuario y
 * contraseña de SMTP). La contraseña sale descifrada. La columna
 * `imap_ultimo_uid` de la tabla no se usa: la marca de lectura vive en
 * `imap_estado`, por dirección de casilla.
 *
 * @return array{host:string, port:int, user:string, pass:string, cuenta_id:?int}
 */
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

/**
 * ¿A la cuenta le queda cupo hoy? Cuenta los envíos con enviado_en de hoy
 * (fecha del servidor de base, que db.php alinea con la zona de la app)
 * contra `limite_diario`. Lo usa el despachador antes de cada correo.
 */
function cuenta_tiene_cupo(array $c): bool
{
    $hoy = (int) qv('SELECT COUNT(*) FROM envios WHERE cuenta_correo_id = ? AND DATE(enviado_en) = CURDATE()',
                    [(int)$c['id']]);
    return $hoy < (int)$c['limite_diario'];
}

/**
 * Prueba la conexión de una cuenta y deja constancia del resultado.
 *
 * Abre SMTP (autentica y cierra, sin mandar nada) y, si anda, abre también la
 * casilla IMAP. Graba `verificada_en` y `ultimo_error` en la cuenta y deja
 * registro en la auditoría. Puede tardar: son dos conexiones de red con sus
 * tiempos de espera, dentro del pedido web.
 *
 * Ojo: si falla sólo la entrada, `verificada_en` igual se actualiza (con el
 * error anotado): la pantalla muestra «Con error» porque mira primero
 * `ultimo_error`.
 *
 * @param int         $id  cuenta a probar
 * @param string|null $err por referencia: el motivo si falla
 * @return bool true si salida y entrada funcionan
 */
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

/**
 * Da de baja una cuenta: baja lógica (eliminado_en) y desactivada. No se
 * borran las contraseñas cifradas ni se tocan las campañas que la usaban;
 * esas dejan de poder mandar. Si era la predeterminada, ninguna queda
 * marcada y manda la activa más antigua (cuenta_correo_predeterminada).
 */
function cuenta_correo_eliminar(int $id): void
{
    q('UPDATE cuentas_correo SET eliminado_en = NOW(), activo = 0 WHERE id = ?', [$id]);
    log_app('cuenta_correo.eliminar', ['entidad' => 'cuenta_correo', 'entidad_id' => $id,
        'detalle' => 'Cuenta de correo dada de baja']);
}

// ------------------------------------------------------------
//  Categorías (segmentos)
// ------------------------------------------------------------

/**
 * Todas las categorías (segmentos) con cuántas listas vivas tiene cada una,
 * en el orden manual y después por nombre. Las usa el formulario de listas.
 */
function categorias_listar(): array
{
    return q('SELECT c.*, (SELECT COUNT(*) FROM listas l
                           WHERE l.categoria_id = c.id AND l.eliminado_en IS NULL) AS listas
              FROM categorias c ORDER BY c.orden, c.nombre')->fetchAll();
}

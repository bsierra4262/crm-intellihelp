<?php
declare(strict_types=1);

// ============================================================
//  Piezas comunes a todos los módulos: notas, etiquetas y adjuntos.
//  Son polimórficas (entidad + entidad_id) para no repetir una tabla y un
//  formulario por cada módulo del CRM.
//
//  Tablas: `notas`, `etiquetas` + `etiqueta_registro`, `adjuntos`. Como la
//  relación es polimórfica, no hay clave foránea: nada impide guardar una
//  nota para un id que no existe, y quien llama tiene que validar.
//
//  Lo usan las fichas (contacto-ver, cuenta-ver, posible-ver, negocio-ver)
//  a través de las vistas parciales _notas, _etiquetas y _adjuntos, y los
//  controladores nota-guardar, nota-eliminar, etiquetas-guardar, adjunto y
//  adjunto-subir. También los badges de estado de campañas y envíos.
//  Los adjuntos son un módulo apagable ('adjuntos' en modulos.php).
// ============================================================

/**
 * Entidades que aceptan notas, etiquetas y adjuntos.
 * Es la lista blanca contra la que se valida lo que llega por POST.
 */
function entidades_validas(): array
{
    return ['contacto', 'cuenta', 'posible_cliente', 'negocio', 'campana'];
}

/** ¿La entidad está en la lista blanca? */
function entidad_valida(string $e): bool
{
    return in_array($e, entidades_validas(), true);
}

/**
 * URL de la ficha de un registro cualquiera (ya con BASE_PATH, lista para
 * una cabecera Location o, envuelta en e(), para un href).
 * Una entidad desconocida lleva al panel.
 */
function url_registro(string $entidad, int $id): string
{
    return url(match ($entidad) {
        'contacto'        => 'contacto-ver?id=' . $id,
        'cuenta'          => 'cuenta-ver?id=' . $id,
        'posible_cliente' => 'posible-ver?id=' . $id,
        'negocio'         => 'negocio-ver?id=' . $id,
        'campana'         => 'campana-ver?id=' . $id,
        default           => 'dashboard',
    });
}

// ---- Notas ----

/** Notas no borradas de un registro, de la más nueva a la más vieja, con el nombre del autor. */
function notas_de(string $entidad, int $id, int $limite = 50): array
{
    return q('SELECT n.*, u.nombre AS autor FROM notas n
              LEFT JOIN usuarios u ON u.id = n.creado_por
              WHERE n.entidad = ? AND n.entidad_id = ? AND n.eliminado_en IS NULL
              ORDER BY n.creado_en DESC LIMIT ?', [$entidad, $id, $limite])->fetchAll();
}

/**
 * Crea una nota a nombre del usuario de la sesión, la deja en la auditoría y
 * devuelve su id. No valida la entidad ni que el registro exista.
 */
function nota_crear(string $entidad, int $id, string $cuerpo, ?string $titulo = null): int
{
    q('INSERT INTO notas (entidad, entidad_id, titulo, cuerpo, creado_por) VALUES (?, ?, ?, ?, ?)',
      [$entidad, $id, $titulo ?: null, $cuerpo, current_user_id()]);
    $nuevo = last_id();
    log_app($entidad . '.nota', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => 'Nota: ' . recortar($cuerpo, 120)]);
    return $nuevo;
}

/**
 * Borrado lógico de una nota (eliminado_en) y registro en la auditoría.
 *
 * No controla quién la borra: el permiso (autor o administrador) lo
 * comprueba pages/nota-eliminar.php. Las notas no figuran en la papelera,
 * así que no hay forma de restaurarlas desde la interfaz.
 */
function nota_eliminar(int $notaId): void
{
    $n = q1('SELECT entidad, entidad_id FROM notas WHERE id = ?', [$notaId]);
    q('UPDATE notas SET eliminado_en = NOW() WHERE id = ?', [$notaId]);
    if ($n) {
        log_app($n['entidad'] . '.nota_eliminar',
            ['entidad' => $n['entidad'], 'entidad_id' => (int)$n['entidad_id'], 'detalle' => 'Nota eliminada']);
    }
}

// ---- Etiquetas ----

/** Todas las etiquetas que existen, por nombre (para sugerir al escribir). */
function etiquetas_todas(): array
{
    return q('SELECT * FROM etiquetas ORDER BY nombre')->fetchAll();
}

/** Etiquetas asignadas a un registro. */
function etiquetas_de(string $entidad, int $id): array
{
    return q('SELECT et.* FROM etiquetas et
              JOIN etiqueta_registro er ON er.etiqueta_id = et.id
              WHERE er.entidad = ? AND er.entidad_id = ? ORDER BY et.nombre', [$entidad, $id])->fetchAll();
}

/**
 * Reemplaza las etiquetas de un registro por la lista dada (por nombre).
 *
 * Borra todas las asignaciones y vuelve a crear las de la lista; las
 * etiquetas que no existen se crean (nombre recortado a 60 caracteres). Las
 * etiquetas que quedan sin uso no se borran. No va en una transacción: si
 * falla a mitad, el registro queda con parte de las etiquetas.
 *
 * @param array<int,string> $nombres
 */
function etiquetas_asignar(string $entidad, int $id, array $nombres): void
{
    q('DELETE FROM etiqueta_registro WHERE entidad = ? AND entidad_id = ?', [$entidad, $id]);
    foreach (array_unique(array_filter(array_map('trim', $nombres))) as $nombre) {
        $et = q1('SELECT id FROM etiquetas WHERE nombre = ?', [$nombre]);
        if (!$et) {
            q('INSERT INTO etiquetas (nombre) VALUES (?)', [mb_substr($nombre, 0, 60)]);
            $etId = last_id();
        } else {
            $etId = (int)$et['id'];
        }
        q('INSERT IGNORE INTO etiqueta_registro (etiqueta_id, entidad, entidad_id) VALUES (?, ?, ?)',
          [$etId, $entidad, $id]);
    }
}

// ---- Presentación ----

/**
 * Badge de estado de un envío de campaña (HTML listo para imprimir).
 * Un estado desconocido se muestra tal cual, escapado.
 */
function badge_envio(string $estado): string
{
    $mapa = [
        'pendiente' => ['badge-neutral', 'En cola'],
        'enviando'  => ['badge-info',    'Enviando'],
        'enviado'   => ['badge-ok',      'Enviado'],
        'rebotado'  => ['badge-rej',     'Rebotó'],
        'rechazado' => ['badge-rej',     'Rechazado'],
        'omitido'   => ['badge-neutral', 'Omitido'],
        'error'     => ['badge-pend',    'Error'],
    ];
    [$clase, $texto] = $mapa[$estado] ?? ['badge-neutral', $estado];
    return '<span class="badge ' . $clase . '">' . e($texto) . '</span>';
}

/** Badge de estado de una campaña (HTML listo para imprimir). */
function badge_campana(string $estado): string
{
    $mapa = [
        'borrador'   => ['badge-neutral', 'Borrador'],
        'programada' => ['badge-info',    'Programada'],
        'enviando'   => ['badge-ok',      'Enviando'],
        'pausada'    => ['badge-pend',    'Pausada'],
        'completada' => ['badge-ok',      'Completada'],
        'cancelada'  => ['badge-rej',     'Cancelada'],
    ];
    [$clase, $texto] = $mapa[$estado] ?? ['badge-neutral', $estado];
    return '<span class="badge ' . $clase . '">' . e($texto) . '</span>';
}

// ------------------------------------------------------------
//  Adjuntos
//  Los archivos viven fuera del web root: se sirven por una ruta que
//  comprueba la sesión, para que un presupuesto no quede accesible con sólo
//  adivinar la dirección.
// ------------------------------------------------------------

/** Carpeta raíz de los adjuntos (STORAGE/adjuntos). */
function adjuntos_dir(): string
{
    return rtrim(STORAGE, '/') . '/adjuntos';
}

/** Adjuntos no borrados de un registro, del más nuevo al más viejo. */
function adjuntos_de(string $entidad, int $id): array
{
    return q('SELECT * FROM adjuntos
              WHERE entidad = ? AND entidad_id = ? AND eliminado_en IS NULL
              ORDER BY creado_en DESC', [$entidad, $id])->fetchAll();
}

/**
 * Guarda un archivo subido y lo registra en `adjuntos`.
 *
 * En disco queda como STORAGE/adjuntos/AAAA/MM/<32 hex>.<ext>; la columna
 * `ruta` guarda la parte relativa y `nombre` el nombre original (sólo para
 * mostrar y para la descarga). No hay lista de extensiones permitidas: la
 * protección es que el archivo queda fuera del web root, con nombre
 * aleatorio, y se descarga siempre como octet-stream (pages/adjunto.php).
 * El `mime` que se guarda es el que declaró el navegador.
 *
 * @param array $archivo una entrada de $_FILES
 * @return int id del adjunto
 * @throws RuntimeException sin archivo, más de 10 MB o error al moverlo
 */
function adjunto_guardar(string $entidad, int $id, array $archivo): int
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No llegó ningún archivo.');
    }
    if ($archivo['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('El archivo pesa ' . peso_legible((int)$archivo['size'])
            . '. El máximo son 10 MB.');
    }

    // El nombre del archivo en disco lo elegimos nosotros: el que manda el
    // navegador puede traer barras o «..» y salirse de la carpeta.
    $dir = adjuntos_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $ext  = strtolower(pathinfo((string)$archivo['name'], PATHINFO_EXTENSION));
    $ext  = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
    $ruta = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    if (!is_dir($dir . '/' . dirname($ruta))) { @mkdir($dir . '/' . dirname($ruta), 0750, true); }
    if (!@move_uploaded_file($archivo['tmp_name'], $dir . '/' . $ruta)) {
        throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
    }

    q('INSERT INTO adjuntos (entidad, entidad_id, nombre, ruta, mime, bytes, creado_por)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$entidad, $id, mb_substr((string)$archivo['name'], 0, 255), $ruta,
       mb_substr((string)($archivo['type'] ?? ''), 0, 100), (int)$archivo['size'], current_user_id()]);
    $nuevo = last_id();
    log_app($entidad . '.adjunto', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => 'Se subió ' . $archivo['name'] . ' (' . peso_legible((int)$archivo['size']) . ')']);
    return $nuevo;
}

/**
 * Borra un adjunto: el archivo se elimina del disco en el acto y la fila
 * queda marcada con eliminado_en (sólo para el historial). No pasa por la
 * papelera, no se puede restaurar, y no controla quién lo borra.
 */
function adjunto_borrar(int $adjuntoId): void
{
    $a = q1('SELECT * FROM adjuntos WHERE id = ?', [$adjuntoId]);
    if (!$a) { return; }
    @unlink(adjuntos_dir() . '/' . $a['ruta']);
    q('UPDATE adjuntos SET eliminado_en = NOW() WHERE id = ?', [$adjuntoId]);
    log_app($a['entidad'] . '.adjunto_borrar', ['entidad' => $a['entidad'],
        'entidad_id' => (int)$a['entidad_id'], 'detalle' => 'Se borró ' . $a['nombre']]);
}

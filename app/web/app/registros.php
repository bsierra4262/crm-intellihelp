<?php
declare(strict_types=1);

// ============================================================
//  Piezas comunes a todos los módulos: notas, etiquetas y adjuntos.
//  Son polimórficas (entidad + entidad_id) para no repetir una tabla y un
//  formulario por cada módulo del CRM.
// ============================================================

/** Entidades que aceptan notas, etiquetas y adjuntos. */
function entidades_validas(): array
{
    return ['contacto', 'cuenta', 'posible_cliente', 'negocio', 'campana'];
}

function entidad_valida(string $e): bool
{
    return in_array($e, entidades_validas(), true);
}

/** URL de la ficha de un registro cualquiera. */
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

function notas_de(string $entidad, int $id, int $limite = 50): array
{
    return q('SELECT n.*, u.nombre AS autor FROM notas n
              LEFT JOIN usuarios u ON u.id = n.creado_por
              WHERE n.entidad = ? AND n.entidad_id = ? AND n.eliminado_en IS NULL
              ORDER BY n.creado_en DESC LIMIT ?', [$entidad, $id, $limite])->fetchAll();
}

function nota_crear(string $entidad, int $id, string $cuerpo, ?string $titulo = null): int
{
    q('INSERT INTO notas (entidad, entidad_id, titulo, cuerpo, creado_por) VALUES (?, ?, ?, ?, ?)',
      [$entidad, $id, $titulo ?: null, $cuerpo, current_user_id()]);
    $nuevo = last_id();
    log_app($entidad . '.nota', ['entidad' => $entidad, 'entidad_id' => $id,
        'detalle' => 'Nota: ' . recortar($cuerpo, 120)]);
    return $nuevo;
}

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

function etiquetas_todas(): array
{
    return q('SELECT * FROM etiquetas ORDER BY nombre')->fetchAll();
}

function etiquetas_de(string $entidad, int $id): array
{
    return q('SELECT et.* FROM etiquetas et
              JOIN etiqueta_registro er ON er.etiqueta_id = et.id
              WHERE er.entidad = ? AND er.entidad_id = ? ORDER BY et.nombre', [$entidad, $id])->fetchAll();
}

/** Reemplaza las etiquetas de un registro por la lista dada (por nombre). */
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

/** Badge de estado de un envío de campaña. */
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

/** Badge de estado de una campaña. */
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

function adjuntos_dir(): string
{
    return rtrim(STORAGE, '/') . '/adjuntos';
}

function adjuntos_de(string $entidad, int $id): array
{
    return q('SELECT * FROM adjuntos
              WHERE entidad = ? AND entidad_id = ? AND eliminado_en IS NULL
              ORDER BY creado_en DESC', [$entidad, $id])->fetchAll();
}

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

function adjunto_borrar(int $adjuntoId): void
{
    $a = q1('SELECT * FROM adjuntos WHERE id = ?', [$adjuntoId]);
    if (!$a) { return; }
    @unlink(adjuntos_dir() . '/' . $a['ruta']);
    q('UPDATE adjuntos SET eliminado_en = NOW() WHERE id = ?', [$adjuntoId]);
    log_app($a['entidad'] . '.adjunto_borrar', ['entidad' => $a['entidad'],
        'entidad_id' => (int)$a['entidad_id'], 'detalle' => 'Se borró ' . $a['nombre']]);
}

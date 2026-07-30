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

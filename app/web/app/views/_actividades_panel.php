<?php
// ============================================================
//  Parcial: panel lateral de actividades de un registro.
//
//  Lo incluyen las fichas (contacto-ver, cuenta-ver, posible-ver,
//  negocio-ver) con `include`, así que ve las variables de la vista que lo
//  incluye. Espera $actividades (de actividades_de(): las pendientes
//  primero, hasta 20).
//
//  A qué registro pertenece no se pasa: se deduce de la ruta actual y del
//  `id` de la dirección, igual que en _notas.php. Incluido desde otra ruta,
//  el botón «Agendar» no aparece.
//
//  Cada fila pendiente tiene su botón de completar (POST a
//  `actividad-completar`, que vuelve a la ficha por el Referer). Las
//  completadas no tienen botón de reabrir acá; eso se hace en el listado.
// ============================================================
/** Panel lateral de actividades de un registro. Espera $actividades. */
/** @var array $actividades */
// Columna de `actividades` que corresponde a la ficha actual; también es el
// parámetro con el que actividad-form precarga el vínculo.
$param = match (current_route()) {
    'contacto-ver' => 'contacto_id',
    'cuenta-ver'   => 'cuenta_id',
    'posible-ver'  => 'posible_cliente_id',
    'negocio-ver'  => 'negocio_id',
    default        => '',
};
$entId = (int)($_GET['id'] ?? 0);
?>
<div class="card">
    <div class="card-head">
        <h2>Actividades</h2>
        <?php if ($param): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('actividad-form?' . $param . '=' . $entId)) ?>"><?= icon('mas', 14) ?>Agendar</a>
        <?php endif; ?>
    </div>
    <?php if (!$actividades): ?>
        <p class="muted small">No hay tareas, llamadas ni reuniones asociadas.</p>
    <?php else: ?>
        <?php foreach ($actividades as $a): [$txt, $cls] = actividad_vencimiento($a); ?>
            <div class="act-row">
                <span class="ic">
                    <?php // Ícono según el tipo; un tipo desconocido usa el de tareas. ?>
                    <?= icon(['tarea' => 'tareas', 'llamada' => 'llamadas', 'reunion' => 'agenda'][$a['tipo']] ?? 'tareas', 16) ?>
                </span>
                <div class="grow">
                    <a href="<?= e(url('actividad-form?id=' . (int)$a['id'])) ?>" class="strong"><?= e($a['asunto']) ?></a>
                    <div class="small muted"><?= e($a['propietario'] ?: 'sin asignar') ?></div>
                </div>
                <span class="badge <?= e($cls) ?>"><?= e($txt) ?></span>
                <?php if (empty($a['completada_en'])): ?>
                    <form class="inline-form" method="post" action="<?= e(url('actividad-completar')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="act" title="Marcar como completada"><?= icon('ok', 15) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

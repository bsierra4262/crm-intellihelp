<?php
/**
 * Paginador reutilizable. Espera $data con page/paginas/total.
 * Mantiene los filtros actuales en la URL (query_con).
 */
/** @var array $data */
$page = (int)($data['page'] ?? 1);
$max  = (int)($data['paginas'] ?? 1);
$per  = (int)($data['per'] ?? filas_por_pagina());
$tot  = (int)($data['total'] ?? 0);
// Se muestra siempre que haya algo que listar. Escondiéndolo con pocas filas
// pasa lo de siempre: quien lo busca no lo encuentra y cree que no existe. Y
// con una sola página igual sirve, porque deja subir a 50 o 100 ANTES de
// agregar registros, que es cuando uno se acuerda de configurarlo.
?>
<div class="pager">
    <label class="pager-por">
        <span>Ver</span>
        <select onchange="window.location = this.value">
            <?php foreach ([25, 50, 100] as $n): ?>
                <option value="<?= e(query_con(['por' => $n, 'page' => null])) ?>" <?= $per === $n ? 'selected' : '' ?>>
                    <?= $n ?> por página
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if ($max > 1): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(query_con(['page' => $page - 1])) ?>"
           <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>><?= icon('izq', 15) ?>Anterior</a>
        <span class="small muted">Página <?= $page ?> de <?= $max ?> · <?= number_format($tot, 0, ',', '.') ?> registros</span>
        <a class="btn btn-ghost btn-sm" href="<?= e(query_con(['page' => $page + 1])) ?>"
           <?= $page >= $max ? 'aria-disabled="true"' : '' ?>>Siguiente<?= icon('der', 15) ?></a>
    <?php else: ?>
        <span class="small muted"><?= number_format($tot, 0, ',', '.') ?> registros</span>
    <?php endif; ?>
</div>

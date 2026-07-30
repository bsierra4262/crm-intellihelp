<?php
/**
 * Paginador reutilizable. Espera $data con page/paginas/total.
 * Mantiene los filtros actuales en la URL (query_con).
 */
/** @var array $data */
if (($data['paginas'] ?? 1) <= 1) { return; }
$page = (int)$data['page'];
$max  = (int)$data['paginas'];
?>
<div class="pager">
    <a class="btn btn-ghost btn-sm" href="<?= e(query_con(['page' => $page - 1])) ?>"
       <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>><?= icon('izq', 15) ?>Anterior</a>
    <span class="small muted">Página <?= $page ?> de <?= $max ?> · <?= number_format((int)$data['total'], 0, ',', '.') ?> registros</span>
    <a class="btn btn-ghost btn-sm" href="<?= e(query_con(['page' => $page + 1])) ?>"
       <?= $page >= $max ? 'aria-disabled="true"' : '' ?>>Siguiente<?= icon('der', 15) ?></a>
</div>

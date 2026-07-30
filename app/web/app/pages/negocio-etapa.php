<?php
declare(strict_types=1);
require_login();

if (!is_post()) { redirect('embudo'); }
verify_csrf();

$id      = (int)($_POST['id'] ?? 0);
$etapaId = (int)($_POST['etapa_id'] ?? 0);
$motivo  = trim((string)($_POST['motivo_perdida'] ?? '')) ?: null;

try {
    negocio_mover($id, $etapaId, $motivo);
    flash('Negocio movido de etapa.', 'success');
} catch (\Throwable $e) {
    flash('No se pudo mover el negocio: ' . $e->getMessage(), 'error');
}

redirect('negocio-ver?id=' . $id);

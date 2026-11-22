<?php
declare(strict_types=1);
// ============================================================
//  Ruta `negocio-etapa` (sólo POST): mueve un negocio a otra etapa.
//
//  La llama el formulario «Mover de etapa» de la ficha del negocio
//  (views/negocio-ver.php) con id, etapa_id y, opcionalmente, motivo_perdida.
//  Usa negocio_mover(), que verifica que la etapa sea del mismo embudo y esté
//  activa, toma la probabilidad de la etapa nueva, abre un tramo en
//  negocio_etapas y deja constancia en el log, todo en una transacción.
//  Siempre vuelve a la ficha con un aviso; un GET vuelve al embudo.
//
//  Módulo «negocios». Cualquier usuario con sesión puede mover cualquier
//  negocio (decisión del equipo: la base se comparte).
// ============================================================
require_login();

if (!is_post()) { redirect('embudo'); }
verify_csrf();

$id      = (int)($_POST['id'] ?? 0);
$etapaId = (int)($_POST['etapa_id'] ?? 0);
$motivo  = trim((string)($_POST['motivo_perdida'] ?? '')) ?: null;

try {
    flash(negocio_mover($id, $etapaId, $motivo) ? 'Negocio movido de etapa.' : 'El negocio ya estaba en esa etapa.',
          'success');
} catch (\RuntimeException $e) {
    // Las de negocio_mover() traen un mensaje pensado para mostrar.
    flash('No se pudo mover el negocio: ' . $e->getMessage(), 'error');
} catch (\Throwable $e) {
    // Un error de base no se muestra: se registra y se avisa algo claro.
    log_error('negocio.mover', $e->getMessage(), $e->getFile(), $e->getLine());
    flash('No se pudo mover el negocio. Probá de nuevo; si sigue pasando, avisale a un administrador.', 'error');
}

redirect('negocio-ver?id=' . $id);

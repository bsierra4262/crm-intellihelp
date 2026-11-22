<?php
declare(strict_types=1);
// ============================================================
//  Ruta `negocio-etapa` (sólo POST): mueve un negocio a otra etapa.
//
//  La llama el formulario «Mover de etapa» de la ficha del negocio
//  (views/negocio-ver.php) con id, etapa_id y, opcionalmente, motivo_perdida.
//  Usa negocio_mover(), que actualiza el negocio, toma la probabilidad de la
//  etapa nueva, abre un tramo en negocio_etapas y deja constancia en el log.
//  Siempre vuelve a la ficha con un aviso; un GET vuelve al embudo.
//
//  Módulo «negocios». No verifica que la etapa sea del mismo embudo ni que
//  esté activa, ni que quien mueve sea el propietario.
// ============================================================
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
    // El mensaje de la excepción se muestra tal cual: puede ser uno de base de
    // datos si algo falla adentro.
    flash('No se pudo mover el negocio: ' . $e->getMessage(), 'error');
}

redirect('negocio-ver?id=' . $id);

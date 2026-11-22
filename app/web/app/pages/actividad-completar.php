<?php
declare(strict_types=1);

// ============================================================
//  Página `actividad-completar` (sólo POST): marca una actividad como
//  completada o la reabre, desde el listado o el panel de una ficha.
//
//  Campos: `id`, `reabrir=1` para reabrir, `resultado` opcional (el de una
//  llamada). Llama a actividad_completar() (actividades.php), que además
//  deja la marca en la línea de tiempo del registro vinculado y actualiza
//  la fecha de último contacto.
//
//  Vuelve a la página de donde vino (cabecera Referer) o a `actividades`.
//  El Referer lo pone el navegador; como el pedido exige token CSRF, sólo
//  puede venir de una pantalla propia.
//
//  Módulo: `actividades`. Permisos: cualquier usuario con sesión completa
//  cualquier actividad, sea o no el responsable. No se comprueba que la
//  actividad exista ni que no esté en la papelera.
// ============================================================

require_login();

// Un GET (un enlace, un prefetch) no cambia nada.
if (!is_post()) { redirect('actividades'); }
verify_csrf();

$id        = (int)($_POST['id'] ?? 0);
$reabrir   = ($_POST['reabrir'] ?? '') === '1';
$resultado = trim((string)($_POST['resultado'] ?? '')) ?: null;

if ($id) {
    actividad_completar($id, !$reabrir, $resultado);
    flash($reabrir ? 'Actividad reabierta.' : 'Actividad completada.', 'success');
}

// No se usa redirect() porque el Referer ya es una dirección absoluta.
$volver = (string)($_SERVER['HTTP_REFERER'] ?? '');
header('Location: ' . ($volver !== '' ? $volver : url('actividades')));
exit;

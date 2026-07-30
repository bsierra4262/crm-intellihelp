<?php
declare(strict_types=1);

// ============================================================
//  Baja de suscripción. Es PÚBLICA y tiene que funcionar siempre:
//  un enlace de baja roto es la vía más rápida a una denuncia de spam.
// ============================================================

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$envio = $token !== ''
    ? q1('SELECT e.*, c.nombre AS campana FROM envios e
          LEFT JOIN campanas c ON c.id = e.campana_id WHERE e.token = ?', [$token])
    : null;

$hecho = false;

// Gmail y Outlook hacen un POST automático a List-Unsubscribe (un clic): en ese
// caso damos de baja sin pedir confirmación y devolvemos 200 sin HTML.
$unClic = is_post() && !isset($_POST['confirmar']);

if ($envio && (is_post())) {
    desuscribir((string)$envio['email'], 'baja', (int)$envio['campana_id'], 'Baja desde el enlace del correo');
    q("INSERT INTO envio_eventos (envio_id, tipo, ip) VALUES (?, 'baja', ?)", [(int)$envio['id'], client_ip()]);
    $hecho = true;
    if ($unClic) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK';
        exit;
    }
}

$bodyClass = 'publica';
render('baja', ['envio' => $envio, 'hecho' => $hecho, 'bodyClass' => $bodyClass], 'Baja de suscripción');

<?php
declare(strict_types=1);

// ============================================================
//  Baja de suscripción. Es PÚBLICA y tiene que funcionar siempre:
//  un enlace de baja roto es la vía más rápida a una denuncia de spam.
//
//  Ruta: /baja?t=<token del envío>  (pública; NO pertenece a ningún módulo,
//  así que responde aunque Campañas esté apagado en producción).
//  La arma campana_armar_mensaje() en el pie del correo y en la cabecera
//  List-Unsubscribe.
//    · GET  → muestra la pantalla con el botón «Sí, quiero darme de baja».
//             No da de baja: los antivirus y los clientes que abren los
//             enlaces solos no pueden desuscribir a nadie.
//    · POST con `confirmar` → la persona apretó el botón.
//    · POST sin `confirmar` → el «un clic» de Gmail/Outlook (RFC 8058).
//  En los dos POST se llama a desuscribir() (desuscripciones, contactos,
//  posibles_clientes, envios, campanas) y se registra el evento 'baja'.
//
//  Sin CSRF a propósito: el POST automático de Gmail no puede traer token. El
//  token del envío (32 hexadecimales al azar) es lo que autoriza la baja.
//  Si la campaña se purgó de la papelera, su cola y sus tokens ya no existen
//  y el enlace muestra «no válido».
// ============================================================

// Del token sólo se conservan caracteres hexadecimales: lo demás se descarta
// antes de consultar.

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$envio = $token !== ''
    ? q1('SELECT e.*, c.nombre AS campana FROM envios e
          LEFT JOIN campanas c ON c.id = e.campana_id WHERE e.token = ?', [$token])
    : null;

$hecho = false;

// Gmail y Outlook hacen un POST automático a List-Unsubscribe (un clic): en ese
// caso damos de baja sin pedir confirmación y devolvemos 200 sin HTML.
$unClic = is_post() && !isset($_POST['confirmar']);

// No se mira si ya estaba dado de baja: cada POST repetido vuelve a sumar en
// `campanas.desuscriptos` y deja otro evento.
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

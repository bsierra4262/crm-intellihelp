<?php
declare(strict_types=1);

// ============================================================
//  Casos sin base: el desvío de correos de la instalación de pruebas
//  (correo_desvio / correo_aplicar_desvio de envio.php). Con
//  `mail.desviar_a` en la config, ningún correo sale a un contacto real.
//  Las variables llevan el prefijo $h.
// ============================================================

echo "== Pruebas · Desvío de todos los correos ==\n";
$hMsg = ['para' => 'cliente@empresa.com', 'para_nombre' => 'Cliente', 'cc' => ['jefe@empresa.com'],
         'asunto' => 'Propuesta', 'html' => '<p>Hola</p>'];

eq('sin desvío el mensaje queda igual', correo_aplicar_desvio($hMsg, null), $hMsg);
$hD = correo_aplicar_desvio($hMsg, 'yo@intellihelp.tech');
eq('con desvío va sólo a la casilla de desvío', $hD['para'], 'yo@intellihelp.tech');
eq('sin copias', $hD['cc'], []);
eq('sin el nombre del contacto en el To', $hD['para_nombre'], '');
eq('el asunto dice a quién iba', $hD['asunto'], '[PRUEBAS → cliente@empresa.com + cc jefe@empresa.com] Propuesta');
eq('queda anotado el destinatario original', $hD['desviado_de'], 'cliente@empresa.com');
eq('el cuerpo no se toca', $hD['html'], '<p>Hola</p>');

$hAntes = $GLOBALS['CONFIG']['mail'] ?? null;
$GLOBALS['CONFIG']['mail'] = ['desviar_a' => ' Yo@IntelliHelp.tech '];
eq('la casilla de desvío sale de la config', correo_desvio(), 'yo@intellihelp.tech');
$GLOBALS['CONFIG']['mail'] = ['desviar_a' => 'no es un correo'];
eq('una dirección inválida no desvía (y no se traga correos)', correo_desvio(), null);
$GLOBALS['CONFIG']['mail'] = [];
eq('sin la clave no hay desvío (producción)', correo_desvio(), null);
if ($hAntes === null) { unset($GLOBALS['CONFIG']['mail']); } else { $GLOBALS['CONFIG']['mail'] = $hAntes; }

$hSrc = getenv('CRM_SRC') ?: __DIR__ . '/../../app/web/app';
$hEnvio = (string) file_get_contents($hSrc . '/envio.php');
ok('smtp_enviar aplica el desvío antes del RCPT',
   (bool) preg_match('/correo_aplicar_desvio\(\$msg, correo_desvio\(\)\).*RCPT TO/s', $hEnvio));
$hGcal = (string) file_get_contents($hSrc . '/gcal.php');
ok('los invitados de Google también se desvían',
   (bool) preg_match('/correo_desvio\(\).*\$d\[\'invitados\'\]\s*=.*\'attendees\'/s', $hGcal));

<?php
declare(strict_types=1);

// ============================================================
//  Casos sin base: campañas en texto plano (como Pulsemail) y la lectura
//  automática de las respuestas (respuesta_clasificar). Los textos de
//  ejemplo son respuestas reales recibidas, con los nombres cambiados.
//  Las variables llevan el prefijo $i.
// ============================================================

echo "== Campañas · Texto plano ==\n";
eq('@nombre de Pulsemail pasa a {{nombre}}', plantilla_texto_normalizar("¡Hola @nombre!\r\nTexto  \r\n\r\n"), "¡Hola {{nombre}}!\nTexto");
eq('sin nombre, «¡Hola {{nombre}}!» queda «¡Hola!»', render_plantilla('¡Hola {{nombre}}!', ['nombre' => '']), '¡Hola!');
eq('con nombre, queda el nombre', render_plantilla('¡Hola {{nombre}}!', ['nombre' => 'Ana']), '¡Hola Ana!');
eq('al principio de un renglón, sin nombre sigue siendo «Hola»', render_plantilla('{{nombre}}, ¿cómo estás?', ['nombre' => '']), 'Hola, ¿cómo estás?');

$iCamp = ['id' => 5, 'propietario_id' => 0, 'plantilla_tipo' => 'texto', 'asunto' => '',
          'plantilla_asunto' => 'Retomando el contacto', 'cuerpo_html' => '',
          'cuerpo_texto' => "¡Hola {{nombre}}!\nTe escribo de nuevo.\nSaludos,\nCarlos"];
$iEnv = ['id' => 1, 'token' => str_repeat('a1', 16), 'email' => 'ana@cliente.com', 'nombre' => 'Ana Gómez', 'empresa' => 'ACME'];
$iMsg = campana_armar_mensaje($iCamp, $iEnv);
eq('una plantilla de texto no lleva HTML', $iMsg['html'], '');
ok('sale marcado como sólo texto', !empty($iMsg['solo_texto']));
ok('el cuerpo es el texto con el nombre', str_starts_with($iMsg['texto'], "¡Hola Ana!\nTe escribo de nuevo."));
contiene('al pie va el renglón de baja por respuesta', $iMsg['texto'], CAMPANA_PIE_TEXTO);
no_contiene('sin píxel de apertura', $iMsg['texto'] . $iMsg['html'], 'px?t=');
ok('la baja en un clic sigue en la cabecera', str_contains($iMsg['baja_url'], 'baja?t=' . str_repeat('a1', 16)));
eq('el asunto sale de la plantilla', $iMsg['asunto'], 'Retomando el contacto');

$iEnvio = (string) file_get_contents((getenv('CRM_SRC') ?: __DIR__ . '/../../app/web/app') . '/envio.php');
ok('smtp_enviar manda una sola parte text/plain cuando es sólo texto',
   (bool) preg_match("/solo_texto'\\]\\) && \\\$ics === ''\\) \\{.*?Content-Type: text\\/plain/s", $iEnvio));

echo "== Campañas · Lo que el CRM resuelve solo de una respuesta ==\n";
$iM = fn(string $asunto, string $texto) => respuesta_clasificar($asunto, $texto)['motivo'] ?? null;
eq('«falleció» de un compañero', $iM('RE: Seguimiento', 'Buenas tardes. El Cr. Pérez falleció hace varios años. Por favor, no enviar más mails.'), 'fallecido');
eq('la viuda que usa su correo', $iM('Re: Retomando el contacto', 'Hola Carlos, Daniel falleció en 2024, soy su esposa.'), 'fallecido');
eq('«nos dejó muy entusiasmados» NO es un fallecimiento', $iM('Re: Propuesta', 'La reunión nos dejó muy entusiasmados.'), null);
eq('BAJA en el asunto', $iM('BAJA', ''), 'baja');
eq('una respuesta corta con BAJA', $iM('Re: Retomando el contacto', 'Baja por favor'), 'baja');
eq('«no me envíen más»', $iM('Re: x', 'Les pido que no me envíen más correos, gracias.'), 'baja');
eq('«quitarme de la lista»', $iM('Re: x', 'Por favor quitarme de la lista de distribución.'), 'baja');
eq('«ya no trabaja en la empresa» de un compañero', $iM('RE: Seguimiento', 'Carlos, Rodolfo no trabaja más en esta empresa. Gracias.'), 'ya_no_trabaja');
eq('respuesta automática en inglés', $iM('Automatic reply', 'The person you are trying to reach no longer works for Umicore.'), 'ya_no_trabaja');
eq('«se desvinculó»', $iM('Respuesta automática', 'El Ing. Bosch se desvinculó de la empresa, contactarse con Valeria.'), 'ya_no_trabaja');
eq('«esta casilla ya no está activa»', $iM('Aviso automático', 'Te informamos que esta dirección de correo electrónico ya no se encuentra activa.'), 'ya_no_trabaja');
eq('«fuera de la oficina» no es nada de eso', $iM('Fuera de la oficina', 'Estoy de vacaciones hasta el lunes 3. Para urgencias, escribir a soporte.'), null);
eq('una respuesta común no dispara nada', $iM('Re: Retomando el contacto', 'Hola Carlos, me interesa. ¿Podemos hablar el martes?'), null);
eq('lo que dice la cita de nuestro correo no cuenta',
   $iM('Re: x', "Me interesa, llamame.\n\nEl lun, 3 nov 2026 a las 10:00, Carlos escribió:\n> Si preferís no recibir más correos míos, respondé BAJA"), null);
eq('si fallece y pide la baja, manda el fallecimiento', $iM('BAJA', 'Lamentablemente falleció. Den de baja este correo.'), 'fallecido');

echo "== Agenda · Invitados fijos de cada reunión ==\n";
$GLOBALS['__ajustes']['agenda_invitados_fijos'] = ' NBaldelli@intellihelp.tech, bsierra@intellihelp.tech, no-es-correo, nbaldelli@intellihelp.tech ';
eq('los invitados fijos salen de Ajustes, limpios y sin repetir', agenda_invitados_fijos(),
   ['nbaldelli@intellihelp.tech', 'bsierra@intellihelp.tech']);
unset($GLOBALS['__ajustes']['agenda_invitados_fijos']);
eq('sin el ajuste no hay invitados de más', agenda_invitados_fijos(), []);
ok('el evento de la reserva los suma', str_contains((string) file_get_contents((getenv('CRM_SRC') ?: __DIR__ . '/../../app/web/app') . '/agenda.php'),
   'array_merge($invitados, agenda_invitados_fijos())'));
$iV = ajustes_validar(['agenda_invitados_fijos' => 'a@x.com, malo'], fn($k, $d = null) => $d);
ok('Ajustes rechaza un invitado que no es correo', (bool) array_filter($iV['errores'], fn($e) => str_contains($e, 'malo')));

echo "== Campañas · Sólo días hábiles ==\n";
$iSinFer = fn(string $f) => null;
ok('un jueves cualquiera es hábil', campana_dia_habil('2026-11-26', $iSinFer));
ok('el sábado no', !campana_dia_habil('2026-11-28', $iSinFer));
ok('el domingo no', !campana_dia_habil('2026-11-29', $iSinFer));
ok('un lunes feriado no', !campana_dia_habil('2026-12-07', fn(string $f) => $f === '2026-12-07' ? ['nombre' => 'Feriado puente'] : null));
ok('el martes siguiente sí', campana_dia_habil('2026-12-09', fn(string $f) => $f === '2026-12-08' ? ['nombre' => 'Inmaculada'] : null));
$iSrc = (string) file_get_contents((getenv('CRM_SRC') ?: __DIR__ . '/../../app/web/app') . '/campanas.php');
ok('el despachador frena fuera de los días hábiles', (bool) preg_match("/function campana_en_horario.*?solo_habiles'\]\) && !campana_dia_habil/s", $iSrc));

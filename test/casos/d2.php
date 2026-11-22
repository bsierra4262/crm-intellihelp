<?php
declare(strict_types=1);

// ============================================================
//  Casos del lote D2 (sin base): correo entrante, MIME, rebotes y saneado
//  del HTML que llega de afuera.
//
//  Los correos de prueba están armados a mano copiando la forma real de
//  cada cliente (Outlook, Apple Mail, Gmail): boundaries con mayúsculas,
//  base64, quoted-printable, juegos de caracteres raros y rebotes con y sin
//  DSN. Lo carga run_tests.php (glob de test/casos/*.php).
//
//  El servidor IMAP de mentira (d2_imap_falso()) vive en test/d2_imap_falso.php,
//  compartido con test/casos_db/d2.php.
// ============================================================

$__srcD2 = $SRC ?? (__DIR__ . '/../../app/web/app');
require_once $__srcD2 . '/mensajes.php';
require_once $__srcD2 . '/cuentas_correo.php';

require_once __DIR__ . '/../d2_imap_falso.php';

// ---- Correos reales armados a mano ----

// Outlook: boundary con mayúsculas, quoted-printable en ISO-8859-1, asunto
// codificado en Q, In-Reply-To con el Message-ID del envío.
$D2_OUTLOOK = d2_crlf('From: =?iso-8859-1?Q?Juan_P=E9rez?= <juan@acme.com>
To: Ventas <ventas@intellihelp.tech>
Subject: =?iso-8859-1?Q?RE:_Propuesta_de_soluci=F3n?=
Thread-Topic: Propuesta de solucion
Date: Mon, 16 Nov 2026 14:03:11 +0000
Message-ID: <DB7PR02MB4321A1B2C3D4E5F6@DB7PR02MB4321.eurprd02.prod.outlook.com>
References: <aa11bb22cc33.1763300000@intellihelp.tech>
In-Reply-To: <aa11bb22cc33.1763300000@intellihelp.tech>
Content-Language: es-AR
Content-Type: multipart/alternative;
	boundary="_000_DB7PR02MB4321A1B2C3D4E5F6DB7PR02MB4321eurp_"
MIME-Version: 1.0

--_000_DB7PR02MB4321A1B2C3D4E5F6DB7PR02MB4321eurp_
Content-Type: text/plain; charset="iso-8859-1"
Content-Transfer-Encoding: quoted-printable

Hola, me interesa la soluci=F3n. =BFPodemos hablar el martes?

________________________________
De: Ventas <ventas@intellihelp.tech>
Enviado: lunes, 16 de noviembre de 2026 10:00

--_000_DB7PR02MB4321A1B2C3D4E5F6DB7PR02MB4321eurp_
Content-Type: text/html; charset="iso-8859-1"
Content-Transfer-Encoding: quoted-printable

<html><head><meta http-equiv=3D"Content-Type" content=3D"text/html; charset=3D=
iso-8859-1"></head><body><p>Hola, me interesa la soluci=F3n.</p>
<div id=3D"divRplyFwdMsg"><b>De:</b> Ventas</div></body></html>

--_000_DB7PR02MB4321A1B2C3D4E5F6DB7PR02MB4321eurp_--
');

// Apple Mail: boundary «Apple-Mail=_…» con mayúsculas, QP en UTF-8, asunto
// partido en dos palabras B cortadas en el medio de una letra.
$D2_APPLE = d2_crlf('Content-Type: multipart/alternative;
	boundary="Apple-Mail=_5B3A1C2D-9E8F-4A7B-B6C5-D4E3F2A1B0C9"
Mime-Version: 1.0 (Mac OS X Mail 16.0 \(3774.600.62\))
Subject: =?utf-8?B?UmU6IFJldW5pw7NuIGRlIG1hw7Fh?=
 =?utf-8?B?bmE=?=
From: =?utf-8?Q?Mar=C3=ADa_G=C3=B3mez?= <maria@ejemplo.com.ar>
In-Reply-To: <dd44ee55.1763300100@intellihelp.tech>
Date: Tue, 17 Nov 2026 09:12:45 -0300
Message-Id: <8F3B2A1C-0D4E-4F5A-9B8C-7D6E5F4A3B2C@ejemplo.com.ar>
References: <dd44ee55.1763300100@intellihelp.tech>
To: Ventas <ventas@intellihelp.tech>

--Apple-Mail=_5B3A1C2D-9E8F-4A7B-B6C5-D4E3F2A1B0C9
Content-Transfer-Encoding: quoted-printable
Content-Type: text/plain;
	charset=utf-8

Perfecto, quedamos as=C3=AD. Ma=C3=B1ana a las 10.

> El 16 nov 2026, a las 10:00, Ventas <ventas@intellihelp.tech> escribi=C3=B3:
>=20
> Hola Mar=C3=ADa

--Apple-Mail=_5B3A1C2D-9E8F-4A7B-B6C5-D4E3F2A1B0C9
Content-Transfer-Encoding: quoted-printable
Content-Type: text/html;
	charset=utf-8

<html><body><div>Perfecto, quedamos as=C3=AD.</div><blockquote type=3D"cite">Hola</blockquote></body></html>
--Apple-Mail=_5B3A1C2D-9E8F-4A7B-B6C5-D4E3F2A1B0C9--
');

// Gmail: base64 en las dos partes, gmail_quote en el HTML.
$gmTexto = base64_encode("¡Genial! Mandame la cotización, por favor.\r\n\r\nEl lun, 16 nov 2026 a las 10:00, Ventas (<ventas@intellihelp.tech>)\r\nescribió:\r\n\r\n> Hola Pedro\r\n");
$gmHtml  = base64_encode('<div dir="ltr">¡Genial! Mandame la cotización, por favor.</div><br><div class="gmail_quote"><div dir="ltr" class="gmail_attr">El lun escribió:</div><blockquote class="gmail_quote">Hola Pedro</blockquote></div>');
$D2_GMAIL = d2_crlf('MIME-Version: 1.0
References: <ff66aa77.1763300200@intellihelp.tech>
In-Reply-To: <ff66aa77.1763300200@intellihelp.tech>
From: Pedro Ruiz <pedro.ruiz@gmail.com>
Date: Wed, 18 Nov 2026 11:20:00 -0300
Message-ID: <CAJx9Q+abcDEF123456789=xyz@mail.gmail.com>
Subject: Re: Cotización
To: Ventas <ventas@intellihelp.tech>
Content-Type: multipart/alternative; boundary="000000000000a1b2c3d4e5f60708"

--000000000000a1b2c3d4e5f60708
Content-Type: text/plain; charset="UTF-8"
Content-Transfer-Encoding: base64

' . chunk_split($gmTexto, 76, "\n") . '
--000000000000a1b2c3d4e5f60708
Content-Type: text/html; charset="UTF-8"
Content-Transfer-Encoding: base64

' . chunk_split($gmHtml, 76, "\n") . '
--000000000000a1b2c3d4e5f60708--
');

// Una sola parte, base64: el caso que antes se rompía con la línea de cierre
// del FETCH pegada al final.
$D2_UNA_PARTE = d2_crlf('From: Ana <ana@acme.com>
Subject: Consulta
Message-ID: <una-parte-1@acme.com>
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: base64

' . chunk_split(base64_encode('Quería saber el precio de la instalación en Córdoba.'), 76, "\n"));

// Juego de caracteres que mbstring no conoce, con bytes de Latin-1 crudos.
$D2_RARO = d2_crlf("From: x@acme.com\nSubject: =?unknown-8bit?Q?Informaci=F3n?=\nContent-Type: text/plain; charset=\"unknown-8bit\"\nContent-Transfer-Encoding: 8bit\n\nInformaci\xF3n del pedido") ;

echo "-- MIME: Outlook, Apple Mail y Gmail --\n";
$p = mime_partes($D2_OUTLOOK);
contiene('Outlook: el boundary con mayúsculas se respeta y el texto aparece', $p['texto'], 'me interesa la solución');
contiene('Outlook: QP en ISO-8859-1 pasa a UTF-8 (¿)', $p['texto'], '¿Podemos hablar');
contiene('Outlook: también el HTML', $p['html'], 'me interesa la solución');
eq('Outlook: asunto en Q-encoding', imap_texto('=?iso-8859-1?Q?RE:_Propuesta_de_soluci=F3n?='), 'RE: Propuesta de solución');
eq('Outlook: nombre del remitente decodificado', imap_nombre('=?iso-8859-1?Q?Juan_P=E9rez?= <juan@acme.com>'), 'Juan Pérez');

$p = mime_partes($D2_APPLE);
contiene('Apple Mail: boundary «Apple-Mail=_…» y QP en UTF-8', $p['texto'], 'quedamos así. Mañana a las 10.');
contiene('Apple Mail: HTML', $p['html'], 'quedamos así');
[$cabApple] = mime_separar($D2_APPLE);
eq('Apple Mail: asunto partido en el medio de una letra se une sin espacio',
   imap_texto(imap_cabecera($cabApple, 'Subject')), 'Re: Reunión de mañana');
eq('Apple Mail: Message-Id con minúscula se encuentra igual',
   imap_cabecera($cabApple, 'Message-ID'), '<8F3B2A1C-0D4E-4F5A-9B8C-7D6E5F4A3B2C@ejemplo.com.ar>');
$cita = mensaje_partir_cita($p['texto']);
eq('Apple Mail: la cita se pliega', $cita['limpio'], 'Perfecto, quedamos así. Mañana a las 10.');

$p = mime_partes($D2_GMAIL);
contiene('Gmail: base64 en UTF-8', $p['texto'], '¡Genial! Mandame la cotización');
contiene('Gmail: HTML en base64', $p['html'], 'gmail_quote');
$ch = mensaje_partir_cita_html($p['html']);
ok('Gmail: el HTML se corta en gmail_quote',
   str_contains($ch['limpio'], 'Mandame la cotización') && !str_contains($ch['limpio'], 'Hola Pedro'));
eq('dos palabras codificadas seguidas: sin el espacio del medio',
   imap_texto('=?UTF-8?Q?Hola_?= =?UTF-8?Q?mundo?='), 'Hola mundo');
eq('texto plano entre palabras codificadas se conserva',
   imap_texto('Re: =?UTF-8?B?w6E=?= y más'), 'Re: á y más');

echo "-- Juegos de caracteres raros: nada lanza ValueError --\n";
$lanzo = false;
try {
    $p = mime_partes($D2_RARO);
    $asuntoRaro = imap_texto('=?unknown-8bit?Q?Informaci=F3n?=');
    $hebreo = texto_a_utf8("\xF9\xEC\xE5\xED", 'iso-8859-8-i');
    $inventado = texto_a_utf8("caf\xE9", 'x-juego-que-no-existe');
} catch (\Throwable $e) { $lanzo = true; }
ok('un charset desconocido no corta la lectura', !$lanzo);
eq('unknown-8bit con bytes Latin-1 se lee como Windows-1252', $p['texto'] ?? '', 'Información del pedido');
eq('lo mismo en el asunto', $asuntoRaro ?? '', 'Información');
eq('iso-8859-8-i se toma como hebreo (ISO-8859-8)', $hebreo ?? '', 'שלום');
eq('un juego inventado cae a Windows-1252', $inventado ?? '', 'café');
eq('UTF-8 válido declarado como otra cosa rara queda igual', texto_a_utf8('ñandú', 'unknown-8bit'), 'ñandú');
eq('windows-1252 con comillas tipográficas', texto_a_utf8("\x93hola\x94", 'windows-1252'), '“hola”');

echo "-- imap_cmd: el literal, sin la línea de cierre --\n";
$fp = d2_imap_falso([7 => $D2_UNA_PARTE]);
$r = imap_cmd($fp, 'UID FETCH 7 (BODY.PEEK[]<0.2097152>)', 2200000);
ok('el FETCH sale bien', $r['ok'] && !$r['sucio']);
ok('el literal no trae la línea de cierre del comando',
   !str_contains($r['literal'], 'FETCH completed') && !str_contains($r['literal'], 'FLAGS'));
contiene('el crudo sí la trae (por eso no se decodifica el crudo)', $r['crudo'], 'OK FETCH completed');
$p = mime_partes($r['literal']);
eq('una sola parte en base64: acentos sanos, sin basura al final', $p['texto'],
   'Quería saber el precio de la instalación en Córdoba.');
$r = imap_cmd($fp, 'UID FETCH 7 (BODY.PEEK[]<0.2097152>)', 100);
ok('un literal más grande que el tope se corta y se avisa', strlen($r['literal']) <= 100 && $r['truncado']);
ok('pero se lee entero del socket: la conexión no queda desfasada', $r['ok'] && !$r['sucio']);
$r2 = imap_cmd($fp, 'UID FETCH 7 (BODY.PEEK[HEADER])');
contiene('el comando siguiente recibe su propia respuesta', $r2['literal'], 'Subject: Consulta');
fclose($fp);

echo "-- Rebotes: definitivo o pasajero --\n";
ok('un «450» suelto en el texto ya no lo hace pasajero',
   es_rebote_duro("Tenemos 450 empleados en planta.\r\n550 5.1.1 User unknown"));
ok('451 4.7.1 sigue siendo pasajero', !es_rebote_duro('451 4.7.1 Greylisted, please retry'));
ok('«smtp; 421» es pasajero', !es_rebote_duro("Diagnostic-Code: smtp; 421 Service not available"));
ok('con 4xx y 5xx en contexto, manda el 5xx (y la frase)',
   es_rebote_duro("421 4.4.2 timeout\r\n550 5.1.1 user unknown"));

echo "-- Rebotes: a quién se le marca --\n";
$dsn = d2_crlf('--BOUNDARY_dsn
Content-Type: text/plain; charset=UTF-8

No se pudo entregar el mensaje a juan@acme.com.

--BOUNDARY_dsn
Content-Type: message/delivery-status

Reporting-MTA: dns; googlemail.com
Final-Recipient: rfc822; juan@acme.com
Action: failed
Status: 5.1.1
Diagnostic-Code: smtp; 550-5.1.1 The email account that you tried to reach does not exist.

--BOUNDARY_dsn
Content-Type: message/rfc822

From: Ventas <ventas@intellihelp.tech>
Reply-To: info@intellihelp.tech
To: juan@acme.com
Message-ID: <aa11bb22cc33.1763300000@intellihelp.tech>

Escribinos a soporte@intellihelp.tech
--BOUNDARY_dsn--
');
eq('con DSN: sólo el Final-Recipient', extraer_destinatario_rebote($dsn), ['juan@acme.com']);
ok('con DSN: definitivo', es_rebote_duro($dsn));

$exim = d2_crlf('This message was created automatically by mail delivery software.

A message that you sent could not be delivered to one or more of its
recipients. This is a permanent error. The following address(es) failed:

  pedro@acme.com
    host mx.acme.com [203.0.113.5]
    SMTP error from remote mail server after RCPT TO:<pedro@acme.com>:
    550 5.1.1 <pedro@acme.com>: Recipient address rejected: User unknown

------ This is a copy of the message, including all the headers. ------

Return-path: <ventas@intellihelp.tech>
From: Ventas <ventas@intellihelp.tech>
Reply-To: info@intellihelp.tech
To: pedro@acme.com
Message-ID: <bb22cc33.1763300300@intellihelp.tech>

Si no querés recibir más correos, escribí a bajas@intellihelp.tech o a otro@cliente.com
');
$dirs = extraer_destinatario_rebote($exim);
eq('sin DSN (Exim): sólo la dirección que falló', $dirs, ['pedro@acme.com']);
ok('sin DSN: la copia del original no aporta direcciones',
   !array_intersect($dirs, ['ventas@intellihelp.tech', 'info@intellihelp.tech', 'bajas@intellihelp.tech', 'otro@cliente.com']));
$postfix = d2_crlf("This is the mail system at host mail.ejemplo.com.\n\n<luis@ejemplo.com>: host mx said: 550 5.1.1 unknown user\n\n--- Below this line is a copy of the message.\n\nFrom: ventas@intellihelp.tech\nTo: luis@ejemplo.com, tercero@ejemplo.com\n");
eq('sin DSN (Postfix): la del «<x>:»', extraer_destinatario_rebote($postfix), ['luis@ejemplo.com']);
ok('los Message-ID de la copia del original se encuentran',
   in_array('<bb22cc33.1763300300@intellihelp.tech>', imap_message_ids($exim), true));

echo "-- mime_parametro --\n";
eq('boundary entre comillas, con mayúsculas', mime_parametro('multipart/mixed; boundary="AbC_123"', 'boundary'), 'AbC_123');
eq('boundary suelto', mime_parametro('multipart/mixed;boundary=XyZ; charset=utf-8', 'boundary'), 'XyZ');
eq('boundary con espacios (permitido entre comillas)', mime_parametro('multipart/mixed; boundary="----=_Part 12"', 'boundary'), '----=_Part 12');
eq('charset no se confunde con otro parámetro', mime_parametro('text/plain; format=flowed; charset="ISO-8859-1"', 'charset'), 'ISO-8859-1');

echo "-- HTML entrante: lista blanca --\n";
$s = static fn(string $h): string => strtolower(mensaje_html_seguro($h));
no_contiene('href=javascript: sin comillas', $s('<a href=javascript:alert(1)>x</a>'), 'javascript');
no_contiene('javascript: escrito con entidades', $s('<a href="&#106;avascript:alert(1)">x</a>'), 'alert');
no_contiene('javascript: con entidad hexadecimal y tabulación', $s('<a href="jav&#x09;ascript:alert(1)">x</a>'), 'alert');
no_contiene('javascript: con espacios delante y mayúsculas', $s('<a HREF="  JaVaScRiPt:alert(1)">x</a>'), 'alert');
no_contiene('vbscript:', $s('<a href="vbscript:msgbox(1)">x</a>'), 'vbscript');
no_contiene('data:text/html en un enlace', $s('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'), 'data:');
no_contiene('SVG con onload se va entero', $s('<svg onload=alert(1)><circle r=5 /></svg>ok'), 'svg');
no_contiene('SVG con enlace xlink', $s('<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>'), 'javascript');
no_contiene('MathML', $s('<math><mi xlink:href="javascript:alert(1)">x</mi></math>'), 'javascript');
no_contiene('on* sin comillas', $s('<img src=x onerror=alert(1)>'), 'onerror');
no_contiene('on* con comillas', $s('<p onmouseover="alert(1)">hola</p>'), 'onmouseover');
no_contiene('script en mayúsculas', $s('<SCRIPT>alert(1)</SCRIPT>hola'), 'alert');
no_contiene('script partido', $s('<scr<script>ipt>alert(1)</script>'), '<script');
no_contiene('iframe', $s('<iframe src="https://evil.tld"></iframe>'), 'iframe');
no_contiene('form', $s('<form action="https://evil.tld"><input name=p></form>'), '<form');
no_contiene('meta refresh', $s('<meta http-equiv="refresh" content="0;url=https://evil.tld">'), 'refresh');
no_contiene('base href', $s('<base href="https://evil.tld/">'), '<base');
no_contiene('style con expression', $s('<div style="width:expression(alert(1))">x</div>'), 'expression');
no_contiene('style con url(javascript:)', $s('<div style="background:url(javascript:alert(1))">x</div>'), 'javascript');
no_contiene('style con escapes', $s('<div style="background:u\\72l(x)">x</div>'), 'style=');
no_contiene('etiqueta style', $s('<style>body{background:red}</style>hola'), 'background');
no_contiene('comentario condicional', $s('<!--[if IE]><script>alert(1)</script><![endif]-->hola'), 'alert');
no_contiene('img con data:text/html', $s('<img src="data:text/html,<script>alert(1)</script>">'), 'data:text');
no_contiene('object y embed', $s('<object data="x.swf"></object><embed src="x.swf">'), 'swf');
$bueno = mensaje_html_seguro('<p style="color:#663399;font-weight:bold">Hola <a href="https://intellihelp.tech">acá</a></p>'
    . '<table bgcolor="#eeeeee" cellpadding="4"><tr><td>Celda</td></tr></table><img src="https://x.tld/logo.png" alt="Logo">'
    . '<img src="cid:logo123"><a href="mailto:ventas@intellihelp.tech">mail</a><a href="#arriba">ancla</a>');
contiene('se conserva el formato', $bueno, 'style="color:#663399;font-weight:bold"');
contiene('los enlaces https quedan, con target y rel', $bueno, 'href="https://intellihelp.tech" target="_blank" rel="noopener noreferrer"');
contiene('las tablas con sus atributos', $bueno, '<table bgcolor="#eeeeee" cellpadding="4">');
contiene('imágenes https', $bueno, 'src="https://x.tld/logo.png"');
contiene('imágenes cid:', $bueno, 'src="cid:logo123"');
contiene('mailto', $bueno, 'href="mailto:ventas@intellihelp.tech"');
contiene('anclas', $bueno, 'href="#arriba"');
eq('los acentos sobreviven', mensaje_html_seguro('<p>Información</p>'), '<p>Información</p>');
contiene('una etiqueta desconocida se desarma pero queda el texto', mensaje_html_seguro('<o:p>texto de Word</o:p>'), 'texto de Word');
eq('vacío da vacío', mensaje_html_seguro('   '), '');

echo "-- Vista de la conversación: marco aislado --\n";
$vistaConv = (string) file_get_contents($__srcD2 . '/views/conversacion.php');
ok('el marco ya no comparte el origen del CRM',
   str_contains($vistaConv, 'sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"')
   && !preg_match('/sandbox="[^"]*allow-same-origin/', $vistaConv));
contiene('lleva una política de contenido con nonce', $vistaConv, "script-src 'nonce-");
contiene('y manda su alto con postMessage', $vistaConv, 'parent.postMessage');
$appJs = (string) file_get_contents($__srcD2 . '/../assets/js/app.js');
contiene('app.js sólo acepta el alto de la ventana de un marco', $appJs, 'contentWindow === e.source');
no_contiene('app.js ya no lee el documento del marco', $appJs, 'contentDocument');
$pagConv = (string) file_get_contents($__srcD2 . '/pages/conversacion.php');
contiene('el POST de responder usa verify_csrf()', $pagConv, 'verify_csrf();');
no_contiene('y no la función inexistente', $pagConv, 'csrf_check(');
ok('verify_csrf() existe', function_exists('verify_csrf'));

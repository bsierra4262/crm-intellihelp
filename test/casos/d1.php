<?php
declare(strict_types=1);

// ============================================================
//  Casos del lote D1 (campañas, plantillas y seguimiento), sin base.
//  Los carga run_tests.php. Ninguno manda un correo: el diálogo SMTP se
//  prueba contra un servidor de mentira (la envoltura de flujo `smtpfalso://`
//  de abajo), que contesta lo que se le indica y anota lo que recibe.
// ============================================================

echo "== D1 · Variables de plantilla escapadas en el HTML ==\n";
$malo = ['nombre' => '<img src=x onerror=alert(1)>', 'empresa' => '"><script>alert(2)</script>',
         'baja' => 'https://intellihelp.tech/crm/baja?t=abc&x=1'];
$h = render_plantilla('<p>Hola {{nombre}} de {{empresa}}</p><a href="{{baja}}">baja</a>', $malo, true);
no_contiene('en el HTML el nombre no entra como etiqueta', $h, '<img src=x');
no_contiene('ni la empresa como script', $h, '<script>');
contiene('queda como texto', $h, '&lt;img src=x onerror=alert(1)&gt;');
contiene('el enlace queda bien escapado dentro del href', $h, 'href="https://intellihelp.tech/crm/baja?t=abc&amp;x=1"');
eq('en el asunto y el texto plano va tal cual', render_plantilla('{{nombre}}', $malo), '<img src=x onerror=alert(1)>');
eq('sin nombre sigue cayendo en «Hola» también en HTML', render_plantilla('{{nombre}}', ['nombre' => ''], true), 'Hola');

echo "== D1 · Enlaces con variables: sólo las que son enlaces ==\n";
eq('{{agenda}} pasa', _url('{{agenda}}'), '{{agenda}}');
eq('{{baja}} pasa', _url('{{baja}}'), '{{baja}}');
eq('{{nombre}} como enlace se descarta (lo escribe un desconocido)', _url('{{nombre}}'), '');
eq('{{empresa}} también', _url('{{empresa}}'), '');
$btn = bloques_render([['tipo' => 'boton', 'texto' => 'X', 'enlace' => '{{nombre}}']], [], []);
no_contiene('un botón con {{nombre}} de enlace no se dibuja', $btn, 'href="{{nombre}}"');

echo "== D1 · Bloques con tipos raros no rompen el render ==\n";
$raro = null;
try {
    $raro = bloques_render([['tipo' => 'titulo', 'texto' => 'Hola', 'alineacion' => 3, 'nivel' => [1]],
                            ['tipo' => 'texto', 'texto' => ['no', 'es', 'texto'], 'alineacion' => ['x']],
                            ['tipo' => 'boton', 'texto' => 12, 'enlace' => 'intellihelp.tech', 'fondo' => 5],
                            'no es un bloque'], ['marca' => 7], []);
} catch (\Throwable $e) {
    $raro = 'EXCEPCION ' . get_class($e);
}
no_contiene('un número o una lista donde va texto no tira TypeError', (string)$raro, 'EXCEPCION');
contiene('y el resto se dibuja igual', (string)$raro, 'Hola');
contiene('el número se toma como texto', (string)$raro, '>12</a>');
eq('el texto plano tampoco se rompe',
   bloques_texto([['tipo' => 'texto', 'texto' => ['x']], ['tipo' => 'titulo', 'texto' => 5]]), "5");

echo "== D1 · Texto plano igual que el HTML (WhatsApp y firma) ==\n";
$ctxW = ['empresa_whatsapp' => '+54 9 11 5555-0000'];
contiene('WhatsApp con teléfono vacío cae al de la empresa en el texto',
         bloques_texto([['tipo' => 'whatsapp', 'telefono' => '']], $ctxW), '+5491155550000');
contiene('y en el HTML', bloques_render([['tipo' => 'whatsapp', 'telefono' => '']], [], $ctxW), 'phone=5491155550000');
contiene('firma sin nombre: {{remitente}} en el texto', bloques_texto([['tipo' => 'firma']]), '{{remitente}}');
contiene('igual que en el HTML', bloques_render([['tipo' => 'firma']], [], []), '{{remitente}}');

echo "== D1 · Control de personalización sobre los bloques, no sobre el HTML ya armado ==\n";
$conVar = [['tipo' => 'texto', 'texto' => 'Hola {{nombre}}, te escribo por {{empresa}}.']];
$chk = bloques_verificar($conVar, 'Una propuesta', '<html>Hola Juan, te escribo por ACME. baja</html>', 'Hola Juan', []);
ok('con {{nombre}} en los bloques no avisa «no personalizado» aunque el asunto no tenga variables',
   !array_filter($chk['avisos'], fn($a) => str_contains($a[0], 'no está personalizado')));
$chk2 = bloques_verificar([['tipo' => 'texto', 'texto' => 'Hola, ¿cómo va?'], ['tipo' => 'firma']], 'Una propuesta',
                          '<html>Hola baja</html>', 'Hola', []);
ok('sin variables del destinatario sí avisa (la firma {{remitente}} no cuenta)',
   (bool) array_filter($chk2['avisos'], fn($a) => str_contains($a[0], 'no está personalizado')));

echo "== D1 · Asunto partido como pide la norma (75 caracteres por palabra) ==\n";
$asuntoLargo = 'Propuesta de tecnología y personal para tu empresa: ñandú, acción y más cosas que contar hoy';
$cod = mime_asunto($asuntoLargo);
$palabras = explode("\r\n ", $cod);
ok('se parte en varias palabras', count($palabras) > 1);
ok('ninguna pasa de 75 caracteres', max(array_map('strlen', $palabras)) <= 75);
eq('juntas dicen lo mismo', implode('', array_map(fn($p) => base64_decode(substr($p, 10, -2)), $palabras)), $asuntoLargo);
ok('ninguna letra queda partida', array_reduce($palabras, fn($ok, $p) => $ok && mb_check_encoding(base64_decode(substr($p, 10, -2)), 'UTF-8'), true));
eq('un asunto corto es una sola palabra', mime_asunto('Hola'), '=?UTF-8?B?' . base64_encode('Hola') . '?=');

echo "== D1 · Respuestas SMTP: qué es definitivo y qué no ==\n";
eq('5xx en el RCPT: rechazado (la casilla no existe)', smtp_clasificar_fallo('rcpt', '550 5.1.1 No such user'), 'rechazado');
eq('5xx en MAIL FROM: fallido, no se reintenta', smtp_clasificar_fallo('mail', '553 5.7.1 Sender not allowed'), 'fallido');
eq('5xx al cerrar el DATA: fallido', smtp_clasificar_fallo('fin', '554 5.7.1 Message rejected as spam'), 'fallido');
eq('4xx: temporal', smtp_clasificar_fallo('fin', '451 4.3.0 Try again later'), 'error');
eq('el tope diario de Gmail (550 5.4.5) es temporal', smtp_clasificar_fallo('mail', '550 5.4.5 Daily user sending limit exceeded'), 'error');
eq('sin respuesta: temporal', smtp_clasificar_fallo('mail', ''), 'error');

echo "== D1 · Qué hace el despachador con cada fallo ==\n";
$f = campana_resultado_fallo('fallido', 1, '554 rechazado');
ok('fallido: error y sin reintento', $f['estado'] === 'error' && $f['reintentar_min'] === null);
$f = campana_resultado_fallo('dudoso', 1, 'sin respuesta');
ok('dudoso: error, sin reintento, y el motivo lo explica',
   $f['estado'] === 'error' && $f['reintentar_min'] === null && str_contains($f['motivo'], 'Dudoso'));
$f = campana_resultado_fallo('error', 3, '451');
ok('temporal: vuelve a la cola con espera creciente', $f['estado'] === 'pendiente' && $f['reintentar_min'] === 15);
eq('la espera tiene techo de 60 minutos', campana_resultado_fallo('error', 19, 'x')['reintentar_min'], 60);
eq('a los 20 intentos se da por perdido', campana_resultado_fallo('error', 20, 'x')['estado'], 'error');

echo "== D1 · smtp_enviar contra un servidor de mentira ==\n";
/**
 * Servidor SMTP de mentira: cada escritura del cliente saca la próxima
 * respuesta del guion (una cadena vacía = el servidor no contesta). Lo que
 * escribe el cliente queda en self::$recibido.
 */
final class SmtpFalsoD1
{
    public static array $guion = [];
    public static array $recibido = [];
    public $context;
    private string $buffer = '';
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool { return true; }
    public function stream_write(string $data): int
    {
        self::$recibido[] = $data;
        $this->buffer .= (string) array_shift(self::$guion);
        return strlen($data);
    }
    public function stream_read(int $n): string|false
    {
        $out = substr($this->buffer, 0, $n);
        $this->buffer = (string) substr($this->buffer, strlen($out));
        return $out;
    }
    public function stream_eof(): bool { return $this->buffer === '' && self::$guion === []; }
    public function stream_close(): void { }
    public function stream_set_option(int $o, int $a1, ?int $a2): bool { return false; }
}
if (!in_array('smtpfalso', stream_get_wrappers(), true)) { stream_wrapper_register('smtpfalso', SmtpFalsoD1::class); }
$cfgFalsa = ['from' => 'ventas@intellihelp.tech', 'from_name' => 'Ventas', 'user' => 'x', 'pass' => 'y'];
$msgFalso = ['para' => 'juan@acme.com', 'asunto' => 'Hola', 'html' => '<p>Hola</p>', 'texto' => 'Hola'];
$probar = static function (array $guion, ?callable $alEntregar = null) use ($cfgFalsa, $msgFalso): array {
    SmtpFalsoD1::$guion = $guion;
    SmtpFalsoD1::$recibido = [];
    $fp = fopen('smtpfalso://servidor', 'r+');
    $err = null;
    $r = smtp_enviar($fp, $msgFalso, $err, $cfgFalsa, $alEntregar);
    fclose($fp);
    return [$r, (string)$err, SmtpFalsoD1::$recibido];
};
$avisado = false;
[$r] = $probar(["250 ok\r\n", "250 ok\r\n", "354 go\r\n", "250 queued\r\n"], function () use (&$avisado) { $avisado = true; });
eq('todo bien: enviado', $r, 'enviado');
ok('y avisó justo antes de entregar el mensaje', $avisado);
[$r, $err] = $probar(["250 ok\r\n", "250 ok\r\n", "354 go\r\n", ""]);
eq('mensaje entregado y sin respuesta: dudoso (no se reintenta)', $r, 'dudoso');
[$r] = $probar(["250 ok\r\n", "250 ok\r\n", "354 go\r\n", "554 5.7.1 spam\r\n"]);
eq('rechazo por contenido al cerrar el DATA: fallido', $r, 'fallido');
[$r] = $probar(["553 5.7.1 sender\r\n", "250 reset\r\n"]);
eq('remitente rechazado con 5xx: fallido', $r, 'fallido');
[$r] = $probar(["250 ok\r\n", "550 5.1.1 no such user\r\n", "250 reset\r\n"]);
eq('destinatario inexistente: rechazado', $r, 'rechazado');
$avisado = false;
[$r] = $probar(["250 ok\r\n", "451 4.3.0 later\r\n", "250 reset\r\n"], function () use (&$avisado) { $avisado = true; });
ok('un 4xx antes del DATA es temporal y no avisa entrega', $r === 'error' && !$avisado);
[, , $recibido] = $probar(["250 ok\r\n", "250 ok\r\n", "354 go\r\n", "250 queued\r\n"]);
contiene('la cabecera Subject usa mime_asunto', implode('', $recibido), 'Subject: =?UTF-8?B?' . base64_encode('Hola') . '?=');

echo "== D1 · Transiciones y permisos de la campaña ==\n";
ok('iniciar desde borrador, pausada o programada',
   campana_transicion_valida('borrador', 'iniciar') && campana_transicion_valida('pausada', 'iniciar')
   && campana_transicion_valida('programada', 'iniciar'));
ok('iniciar NO desde completada ni cancelada',
   !campana_transicion_valida('completada', 'iniciar') && !campana_transicion_valida('cancelada', 'iniciar'));
ok('ni desde enviando', !campana_transicion_valida('enviando', 'iniciar'));
ok('preparar no sobre una terminada', !campana_transicion_valida('completada', 'preparar') && !campana_transicion_valida('cancelada', 'preparar'));
ok('cancelar no dos veces', !campana_transicion_valida('cancelada', 'cancelar'));
ok('acción inventada: no', !campana_transicion_valida('borrador', 'borrar'));
$camp = ['creado_por' => 5, 'propietario_id' => 7];
ok('el creador puede', campana_puede_gestionar($camp, ['id' => 5, 'rol' => 'comercial']));
ok('el responsable puede', campana_puede_gestionar($camp, ['id' => 7, 'rol' => 'comercial']));
ok('un admin puede', campana_puede_gestionar($camp, ['id' => 99, 'rol' => 'admin']));
ok('otro comercial no', !campana_puede_gestionar($camp, ['id' => 8, 'rol' => 'comercial']));
ok('sin sesión no', !campana_puede_gestionar($camp, null));
ok('una campaña sin creador ni responsable: sólo admin', !campana_puede_gestionar([], ['id' => 1, 'rol' => 'comercial']));

echo "== D1 · Modo prueba: lista blanca ==\n";
$lista = campana_lista_blanca_armar(['Ana@IntelliHelp.tech', '', 'no-es-correo'], ' pruebas@gmail.com; otro@x.com ,  ');
eq('junta usuarios y ajuste, normalizados', array_keys($lista), ['ana@intellihelp.tech', 'pruebas@gmail.com', 'otro@x.com']);
ok('en prueba, un usuario sí', campana_destino_permitido('ANA@intellihelp.tech', true, $lista));
ok('en prueba, un cliente no', !campana_destino_permitido('cliente@empresa.com', true, $lista));
ok('en producción, cualquiera', campana_destino_permitido('cliente@empresa.com', false, []));

echo "== D1 · Quién queda afuera de la cola ==\n";
eq('el marcador de los sin correo es inválido', campana_motivo_omision(lista_marcador_sin_correo(3), false, []), 'Correo inválido');
ok('dos miembros sin correo no comparten marcador', lista_marcador_sin_correo(3) !== lista_marcador_sin_correo(4));
eq('contacto en la papelera', campana_motivo_omision('a@b.com', false, ['ct_eliminado_en' => '2026-01-01 10:00:00']), 'Está en la papelera');
eq('posible cliente que rebotó', campana_motivo_omision('a@b.com', false, ['pc_email_rebotado' => 1]), 'El correo rebotó antes');
eq('posible cliente «no incluir»', campana_motivo_omision('a@b.com', false, ['pc_no_email' => 1]), 'Marcado como «no incluir en campañas»');
eq('la baja manda sobre lo demás', campana_motivo_omision('a@b.com', true, ['ct_no_email' => 1]), 'Se dio de baja');
eq('uno sano entra', campana_motivo_omision('a@b.com', false, ['ct_no_email' => 0, 'ct_email_rebotado' => 0]), null);

echo "== D1 · Ritmo: el tope es el de la campaña ==\n";
$rit = campana_ritmo(['pausa_min_seg' => 25, 'pausa_max_seg' => 45, 'limite_diario' => 50], 500);
contiene('el freno dice «de la campaña»', (string)$rit['freno'], 'por día de la campaña');

<?php
declare(strict_types=1);

// ============================================================
//  Casos sin base del lote C2: API pública para la web, Google Calendar,
//  feriados y el registro del navegador. Los carga run_tests.php (bloque
//  «Casos sin base»); usan ok(), eq(), contiene() y no_contiene() de ahí.
// ============================================================

$SRC_C2 = getenv('CRM_SRC') ?: (__DIR__ . '/../../app/web/app');

// ---- freeBusy: una respuesta con errores no es «todo libre» ----
$cal = 'primary';
$falla = null;
eq('freeBusy con calendars[id].errors: no se sabe qué está libre (null)',
   gcal_franjas_freebusy(200, ['calendars' => [$cal => [
       'errors' => [['domain' => 'calendar', 'reason' => 'notFound']], 'busy' => []]]], $cal, $falla), null);
contiene('y el motivo nombra el error de Google', (string)$falla, 'notFound');
eq('backendError también', gcal_franjas_freebusy(200, ['calendars' => [$cal => [
       'errors' => [['reason' => 'backendError']]]]], $cal), null);
eq('una respuesta sin el calendario pedido no es «libre»', gcal_franjas_freebusy(200, ['calendars' => []], $cal), null);
eq('sin conexión (código 0): null', gcal_franjas_freebusy(0, [], $cal, $falla), null);
contiene('con un motivo que se entiende', (string)$falla, 'no respondió');
eq('un 403 de Google: null', gcal_franjas_freebusy(403, ['error' => ['message' => 'Forbidden']], $cal, $falla), null);
eq('una franja ilegible no se saltea: null', gcal_franjas_freebusy(200, ['calendars' => [$cal => [
       'busy' => [['start' => 'basura', 'end' => 'otra']]]]], $cal), null);
eq('calendario sin nada ocupado: lista vacía (no null)',
   gcal_franjas_freebusy(200, ['calendars' => [$cal => ['busy' => []]]], $cal), []);
eq('las franjas ocupadas se leen',
   gcal_franjas_freebusy(200, ['calendars' => [$cal => ['busy' => [
       ['start' => '2026-12-01T13:00:00Z', 'end' => '2026-12-01T13:30:00Z']]]]], $cal),
   [[strtotime('2026-12-01T13:00:00Z'), strtotime('2026-12-01T13:30:00Z')]]);

// ---- El aviso de Google: cada uno ve el suyo ----
$fallas = [
    ['usuario_id' => 2, 'codigo' => 401, 'mensaje' => 'El usuario no tiene su cuenta de Google conectada.'],
    ['usuario_id' => 5, 'codigo' => 0,   'mensaje' => 'Google no respondió'],
];
$v = gcal_falla_elegir($fallas, 5, false);
eq('quien tiene una falla ve la suya, marcada como propia', [$v['usuario_id'] ?? null, $v['propia'] ?? null], [5, true]);
eq('un comercial sin falla propia no ve la de otro', gcal_falla_elegir($fallas, 9, false), null);
$v = gcal_falla_elegir($fallas, 9, true);
eq('un administrador sí, marcada como ajena', [$v['usuario_id'] ?? null, $v['propia'] ?? null], [2, false]);
eq('sin fallas, nada', gcal_falla_elegir([], 5, true), null);
$aviso = (string) file_get_contents($SRC_C2 . '/views/_aviso_gcal.php');
contiene('el aviso sólo ofrece reconectar en la falla propia', $aviso, '$gcalDesconectado && $gcalPropia');
contiene('y en la ajena nombra al afectado', $aviso, 'El Google Calendar de <?= e($gcalQuien) ?>');

// ---- Evento de Google con id propio (idempotente) ----
$idA = gcal_id_evento('123');
eq('el id del evento es siempre el mismo para la misma reserva', gcal_id_evento('123'), $idA);
ok('y distinto para otra', gcal_id_evento('124') !== $idA);
ok('sólo caracteres que Google acepta (base32hex) y largo válido',
   preg_match('/^[0-9a-v]{5,1024}$/', $idA) === 1);
$secretoAntes = $GLOBALS['CONFIG']['app']['secret'];
$GLOBALS['CONFIG']['app']['secret'] = 'otra-instalacion';
ok('otra instalación (otro secreto) da otro id: pruebas y producción no chocan', gcal_id_evento('123') !== $idA);
$GLOBALS['CONFIG']['app']['secret'] = $secretoAntes;

// ---- Permisos concedidos en el login de Google ----
$todos = implode(' ', GOOGLE_SCOPES);
eq('con todos los permisos no falta nada', google_permisos_faltantes($todos), []);
eq('si se destildó la ocupación, falta ésa',
   google_permisos_faltantes('openid email profile https://www.googleapis.com/auth/calendar.events'),
   ['https://www.googleapis.com/auth/calendar.events.freebusy']);
eq('si Google no informa los permisos, no se da nada por faltante', google_permisos_faltantes(''), []);
eq('el permiso completo del calendario alcanza',
   google_permisos_faltantes('openid email https://www.googleapis.com/auth/calendar'), []);

// ---- Pausa después de invalid_grant ----
ok('sin marca no hay pausa', !google_en_pausa(''));
ok('recién rechazado: pausa', google_en_pausa(date('Y-m-d H:i:s', time() - 60)));
ok('pasada la pausa se vuelve a probar', !google_en_pausa(date('Y-m-d H:i:s', time() - GOOGLE_PAUSA_INVALIDO_SEG - 5)));

// ---- Secreto de la instalación sin app.secret ----
$GLOBALS['CONFIG']['app']['secret'] = '';
$derivado = crm_secret_derivado();
$ivC2 = random_bytes(12); $tagC2 = '';
$ctC2 = openssl_encrypt('token-viejo', 'aes-256-gcm', hash('sha256', $derivado, true), OPENSSL_RAW_DATA, $ivC2, $tagC2);
eq('lo cifrado con el secreto derivado viejo se sigue leyendo', descifrar(base64_encode($ivC2 . $tagC2 . $ctC2)), 'token-viejo');
eq('y lo nuevo también', descifrar(cifrar('token-nuevo')), 'token-nuevo');
$GLOBALS['CONFIG']['app']['secret'] = $secretoAntes;
eq('con app.secret configurado se usa ése (producción no cambia)', crm_secret(), $secretoAntes);
$dirC2 = sys_get_temp_dir() . '/crm-c2-' . bin2hex(random_bytes(4));
$s1 = crm_secret_guardado($dirC2);
ok('sin app.secret se guarda un secreto al azar fuera del webroot', is_string($s1) && strlen($s1) === 64 && $s1 !== $derivado);
eq('y es el mismo en el pedido siguiente', crm_secret_guardado($dirC2), $s1);
@unlink($dirC2 . '/.secreto-app');
@rmdir($dirC2);

// ---- Fórmula de la firma en la pantalla de claves ----
$canonC2 = api_firma_canonica('hora', 'MÉTODO', 'ruta', 'idempotencia', 'hashIP', 'cuerpo');
$formulaC2 = implode(' + "\n" + ', explode("\n", $canonC2));
contiene('la fórmula que muestra la pantalla es la de api_firma_canonica()',
   (string) file_get_contents($SRC_C2 . '/views/api-keys.php'), $formulaC2);

// ---- Registro desde el navegador ----
eq('una acción que no es js.* no se registra', cliente_log_limpio(['accion' => 'sesion.ingreso']), null);
eq('ni una vacía', cliente_log_limpio([]), null);
$lC2 = cliente_log_limpio(['accion' => 'js.error', 'detalle' => "falló\nACCION: falsa " . str_repeat('x', 400), 'tipo' => 'auth']);
eq('un error de JavaScript sí', $lC2['accion'] ?? null, 'js.error');
eq('el tipo no puede ser auth', $lC2['tipo'] ?? null, 'accion');
ok('el detalle queda en una línea y corto', !str_contains((string)($lC2['detalle'] ?? ''), "\n")
   && mb_strlen((string)($lC2['detalle'] ?? '')) <= 300);

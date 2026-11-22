<?php
declare(strict_types=1);

// ============================================================
//  Casos con base del lote C2 (SQLite en memoria de run_tests.php): reserva
//  de la web trabada a medias, cupo de reservas por visitante, avisos de
//  Google por vendedor, pausa de invalid_grant, auditoría de rechazos y
//  feriados. Usa las tablas entradas_web, api_limites, usuarios,
//  agenda_tipos y agenda_reservas que ya creó run_tests.php, y crea las suyas.
// ============================================================

// Un vendedor, un tipo de reunión y una reserva, con ids altos para no chocar
// con los demás casos.
q("INSERT INTO usuarios (id, nombre, email, activo) VALUES (901, 'Vendedora C2', 'c2@intellihelp.tech', 1)");
q("INSERT INTO agenda_tipos (id, usuario_id, slug, nombre, duracion_min, margen_min, aviso_min_horas, ventana_dias,
                             zona_horaria, con_meet, activo)
   VALUES (901, 901, 'diagnostico-c2', 'Diagnóstico C2', 30, 0, 0, 30, 'America/Argentina/Buenos_Aires', 1, 1)");
q("INSERT INTO agenda_reservas (id, tipo_id, usuario_id, inicia_el, termina_el, estado, invitado_nombre, invitado_email)
   VALUES (9001, 901, 901, '2026-12-01 10:00:00', '2026-12-01 10:30:00', 'confirmada', 'Juana', 'bsierra@intellihelp.tech')");

$hC2 = hash('sha256', 'pedido-c2');
$filaC2 = static fn(string $idem, array $extra = []): array => $extra + [
    'idempotencia' => $idem, 'cuerpo_hash' => $hC2, 'token_id' => 1, 'tipo' => 'reserva',
    'nombre' => 'Juana Prueba', 'email' => 'bsierra@intellihelp.tech',
    'consentimiento_en' => date('Y-m-d H:i:s'), 'consentimiento_version' => 'web-2026-11',
];

// ---- Reserva trabada en 'procesando' con la reunión ya creada ----
$idT = entrada_web_guardar($filaC2('web-c2-trabada-000001'));
entrada_web_actualizar($idT, ['reserva_id' => 9001]);
$prev = api_reparar_previa(q1('SELECT * FROM entradas_web WHERE id = ?', [$idT]));
eq('una entrada procesando con su reserva se repara sola a «ok»', $prev['estado'], 'ok');
[$cT, $dT] = api_previa_respuesta($prev, $hC2);
eq('y el reintento con la misma clave recibe ESA reserva, como repetida',
   [$cT, $dT['ok'] ?? null, $dT['repetida'] ?? null, $dT['reserva']['id'] ?? null], [200, true, true, 9001]);
eq('con el inicio que lee la web', $dT['reserva']['inicio'] ?? null, '2026-12-01T10:00:00-03:00');
eq('queda guardada en la base', qv('SELECT estado FROM entradas_web WHERE id = ?', [$idT]), 'ok');

// La escritura del «ok» falló y el catch la había marcado 'error' (código viejo).
$idE = entrada_web_guardar($filaC2('web-c2-error-con-reunion'));
entrada_web_actualizar($idE, ['reserva_id' => 9001, 'estado' => 'error', 'error' => 'error_interno']);
$prev = api_reparar_previa(q1('SELECT * FROM entradas_web WHERE id = ?', [$idE]));
eq('una con error pero con reunión tampoco se retoma: se repara', $prev['estado'], 'ok');
eq('y no contesta horario_tomado contra su propia reunión', api_previa_respuesta($prev, $hC2)[1]['reserva']['id'] ?? null, 9001);

// Resuelta pero sin la respuesta guardada: se completa con la reserva.
$idS = entrada_web_guardar($filaC2('web-c2-ok-sin-respuesta'));
entrada_web_actualizar($idS, ['reserva_id' => 9001, 'estado' => 'ok']);
$prev = api_reparar_previa(q1('SELECT * FROM entradas_web WHERE id = ?', [$idS]));
eq('una «ok» sin respuesta guardada recupera la reserva', api_previa_respuesta($prev, $hC2)[1]['reserva']['inicio'] ?? null,
   '2026-12-01T10:00:00-03:00');

// La forma de la respuesta es la de siempre (la web lee ok y reserva.inicio).
$rC2 = api_respuesta_reserva(q1('SELECT * FROM agenda_reservas WHERE id = 9001'), agenda_tipo_get(901), 77);
eq('la respuesta de la reserva conserva sus campos',
   [array_keys($rC2), array_keys($rC2['reserva'])],
   [['ok', 'repetida', 'entrada_id', 'reserva', 'mensaje'], ['id', 'inicio', 'fin', 'duracion_min', 'estado']]);

// ---- Trabada sin nada creado ----
$idV = entrada_web_guardar($filaC2('web-c2-vencida-000001'));
$prev = api_reparar_previa(q1('SELECT * FROM entradas_web WHERE id = ?', [$idV]));
eq('recién empezada sigue en proceso (el reintento recibe en_proceso)',
   api_previa_respuesta($prev, $hC2)[1]['error'] ?? null, 'en_proceso');
q('UPDATE entradas_web SET creado_en = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - API_PROCESANDO_VENCE_SEG - 30), $idV]);
$prev = api_reparar_previa(q1('SELECT * FROM entradas_web WHERE id = ?', [$idV]));
eq('pasado el vencimiento queda con error «vencida»', [$prev['estado'], $prev['error']], ['error', 'vencida']);
ok('y el reintento con la misma clave la retoma', entrada_web_retomar($idV, $filaC2('web-c2-vencida-000001')));

// ---- Cupo de reservas por visitante: sólo las que entran ----
$kC2 = 'ip:c2:reserva:3600';
for ($i = 0; $i < 10; $i++) { api_limite_lleno($kC2, 3, 3600); }
ok('consultar el cupo no lo gasta (intentos fallidos y reintentos no cuentan)', !api_limite_lleno($kC2, 3, 3600));
api_limite_sumar($kC2, 3600);
api_limite_sumar($kC2, 3600);
ok('con dos reservas que entraron todavía hay lugar', !api_limite_lleno($kC2, 3, 3600));
api_limite_sumar($kC2, 3600);
ok('con tres, el cupo está lleno', api_limite_lleno($kC2, 3, 3600));
ok('el de otro visitante no', !api_limite_lleno('ip:c2-otro:reserva:3600', 3, 3600));

// ---- Avisos de Google por vendedor ----
gcal_recordar_falla(905, 0, '');
$fC2 = json_decode((string) setting_get(gcal_falla_clave(905), ''), true);
eq('una caída de red (código 0) se guarda como falla, no borra el aviso', [$fC2['codigo'] ?? null, $fC2['usuario_id'] ?? null], [0, 905]);
contiene('con un mensaje que se entiende', (string)($fC2['mensaje'] ?? ''), 'no respondió');
gcal_recordar_falla(906, 403, 'Forbidden');
gcal_limpiar_falla(906);
eq('lo que sale bien con un vendedor borra SU aviso', (string) setting_get(gcal_falla_clave(906), ''), '');
ok('pero no el de otro vendedor', (string) setting_get(gcal_falla_clave(905), '') !== '');
$GLOBALS['__ajustes'][gcal_falla_clave(905)] = json_encode(['codigo' => 0, 'mensaje' => (string)$fC2['mensaje'],
    'usuario_id' => 905, 'cuando' => '2026-01-01 00:00:00']);
gcal_recordar_falla(905, 0, '');
eq('la misma falla repetida no pisa cuándo se detectó',
   json_decode((string) setting_get(gcal_falla_clave(905), ''), true)['cuando'] ?? null, '2026-01-01 00:00:00');
$GLOBALS['__ajustes']['gcal_falla'] = json_encode(['codigo' => 500, 'mensaje' => 'x', 'usuario_id' => 907, 'cuando' => '2026-01-01 00:00:00']);
gcal_limpiar_falla(905);
ok('el aviso viejo (único) de otro vendedor no lo borra un éxito ajeno', (string) setting_get('gcal_falla', '') !== '');
gcal_limpiar_falla(907);
eq('el de ese vendedor sí', (string) setting_get('gcal_falla', ''), '');

// ---- Pausa después de invalid_grant ----
db()->exec("CREATE TABLE IF NOT EXISTS google_tokens (usuario_id INTEGER PRIMARY KEY, refresh_token TEXT, access_token TEXT,
                expira_en TEXT, scopes TEXT, calendario_id TEXT DEFAULT 'primary', actualizado_en TEXT)");
q("INSERT INTO google_tokens (usuario_id, refresh_token, access_token, expira_en) VALUES (908, ?, NULL, '2000-01-01 00:00:00')",
  [cifrar('refresh-viejo')]);
ok('con el permiso guardado, está conectado', google_conectado(908));
google_marcar_invalido(908, true);
ok('después de un invalid_grant ya no figura como conectado', !google_conectado(908));
$t0 = microtime(true);
eq('y durante la pausa no se vuelve a pedir la renovación a Google', google_access_token(908, 20), null);
ok('(contesta en el acto, sin esperar a la red)', microtime(true) - $t0 < 1.0);
google_marcar_invalido(908, false);
ok('al reconectar vuelve a estar conectado', google_conectado(908));

// ---- Auditoría: los rechazos de autenticación no la inundan ----
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
$silenciados = [];
for ($i = 0; $i < API_RECHAZOS_MAX + 2; $i++) { $silenciados[] = api_rechazo_silenciar(); }
eq('los primeros rechazos de un origen se registran, los siguientes no',
   [in_array(true, array_slice($silenciados, 0, API_RECHAZOS_MAX), true), $silenciados[API_RECHAZOS_MAX]], [false, true]);
$_SERVER['REMOTE_ADDR'] = '198.51.100.78';
ok('otro origen se registra igual', !api_rechazo_silenciar());
unset($_SERVER['REMOTE_ADDR']);

// ---- Feriados ----
$llamadasC2 = 0;
$caidaC2 = static function (int $a) use (&$llamadasC2): ?array { $llamadasC2++; return null; };
$rC2 = feriados_sincronizar([2091, 2092], $caidaC2, 1, true);
eq('al vuelo, con la fuente caída se espera un solo año, no dos', [$llamadasC2, $rC2['fallidos']], [1, [2091, 2092]]);
$llamadasC2 = 0;
feriados_sincronizar([2091, 2092], $caidaC2, 1, false);
eq('el cron (sin corte) prueba los dos', $llamadasC2, 2);

$ajustesC2 = $GLOBALS['__ajustes'];
db()->exec('ALTER TABLE feriados RENAME TO feriados_c2');
$calC2 = [];
for ($d = 1; $d <= 12; $d++) { $calC2[sprintf('2093-%02d-01', $d)] = ['nombre' => 'Feriado ' . $d, 'tipo' => 'inamovible']; }
$rC2 = feriados_asegurar(false, true, static fn(int $a): ?array => $calC2);
db()->exec('ALTER TABLE feriados_c2 RENAME TO feriados');
$GLOBALS['__ajustes'] = $ajustesC2;
ok('«Actualizar ahora» con una falla interna devuelve el error, no «ya hay una en curso»',
   is_array($rC2) && $rC2['anios'] === [] && str_contains((string)$rC2['error'], 'falla interna'));

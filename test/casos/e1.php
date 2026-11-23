<?php
// ============================================================
//  Casos del lote E1: frontend (CSS/JS) y pantalla de Ajustes.
//  Sin base. La validación de Ajustes se prueba de verdad
//  (ajustes_validar.php es puro); el JS y el CSS se revisan por su texto.
//  Lo carga run_tests.php (usa $SRC, ok(), eq(), contiene(), no_contiene()).
// ============================================================

// notificaciones.php no se puede cargar entero (run_tests.php simula
// notificar()): se toma sólo notif_tipos(), que es una lista fija.
if (!function_exists('notif_tipos')
    && preg_match('/function notif_tipos\(\): array\s*\{.*?\n\}/s', (string) file_get_contents($SRC . '/notificaciones.php'), $__m)) {
    eval($__m[0]);
}
require_once $SRC . '/ajustes_validar.php';

$WEB_E1 = dirname($SRC);
$leer = static fn(string $rel): string => (string) @file_get_contents($WEB_E1 . '/' . $rel);

// Valores «guardados» para las pruebas.
$guardado = ['app_entorno' => 'produccion', 'campana_pausa_min' => '25', 'campana_pausa_max' => '45',
             'campana_limite_diario' => '300', 'papelera_dias' => '60'];
$actual = static fn(string $k, string $def = '') => $guardado[$k] ?? $def;

echo "-- Ajustes: interruptores de avisos --\n";
$tipos = ajustes_tipos_aviso();
ok('la lista de interruptores no incluye «sistema»', !isset($tipos['sistema']));
ok('pero sí los demás tipos', isset($tipos['respuesta'], $tipos['reunion'], $tipos['rebote'], $tipos['campana'], $tipos['web']));
$pagA = $leer('app/pages/ajustes.php');
$vistaA = $leer('app/views/ajustes.php');
ok('la página guarda la misma lista que dibuja la vista',
   str_contains($pagA, 'foreach (ajustes_tipos_aviso() as $t)') && str_contains($vistaA, 'foreach (ajustes_tipos_aviso() as $k => $t)'));
no_contiene('la página ya no recorre notif_tipos() al guardar (apagaba avisar_sistema)', $pagA, 'foreach (notif_tipos()');

echo "-- Ajustes: validación del servidor --\n";
$r = ajustes_validar(['papelera_dias' => ''], $actual);
ok('papelera vacía no da error', $r['errores'] === []);
ok('y conserva el valor (no se guarda nada)', !array_key_exists('papelera_dias', $r['valores']));
$r = ajustes_validar(['papelera_dias' => '3'], $actual);
ok('papelera de 3 días se rechaza', $r['errores'] !== [] && !isset($r['valores']['papelera_dias']));
$r = ajustes_validar(['papelera_dias' => '7'], $actual);
eq('papelera de 7 días se acepta', $r['valores']['papelera_dias'] ?? null, '7');
$r = ajustes_validar(['papelera_dias' => '-5'], $actual);
ok('papelera negativa se rechaza', $r['errores'] !== []);
$r = ajustes_validar(['papelera_dias' => '400'], $actual);
ok('papelera de más de 365 se rechaza', $r['errores'] !== []);

$r = ajustes_validar(['campana_pausa_min' => '50', 'campana_pausa_max' => '40'], $actual);
ok('pausa mínima mayor que la máxima se rechaza', $r['errores'] !== []);
ok('y no queda ninguna de las dos para guardar', !isset($r['valores']['campana_pausa_min']) && !isset($r['valores']['campana_pausa_max']));
$r = ajustes_validar(['campana_pausa_min' => '50', 'campana_pausa_max' => ''], $actual);
ok('la comparación usa el valor guardado si la otra viene vacía (50 > 45)', $r['errores'] !== []);
$r = ajustes_validar(['campana_pausa_min' => '30', 'campana_pausa_max' => '30'], $actual);
ok('mínima igual a la máxima se acepta', $r['errores'] === [] && ($r['valores']['campana_pausa_min'] ?? '') === '30');
$r = ajustes_validar(['campana_limite_diario' => '0'], $actual);
ok('límite diario 0 se rechaza', $r['errores'] !== []);
$r = ajustes_validar(['campana_limite_diario' => 'abc'], $actual);
ok('límite diario no numérico se rechaza', $r['errores'] !== []);
$r = ajustes_validar(['campana_limite_diario' => '500'], $actual);
eq('límite diario válido se guarda', $r['valores']['campana_limite_diario'] ?? null, '500');

$r = ajustes_validar(['cron_clave' => 'x'], $actual);
ok('clave del cron de un carácter se rechaza', $r['errores'] !== []);
$r = ajustes_validar(['cron_clave' => ''], $actual);
ok('clave del cron vacía se acepta (cierra la vía web)', $r['errores'] === [] && ($r['valores']['cron_clave'] ?? null) === '');
$r = ajustes_validar(['cron_clave' => str_repeat('a', 24)], $actual);
ok('clave del cron de 24 caracteres se acepta', $r['errores'] === []);
$r = ajustes_validar(['cron_clave' => str_repeat('a', 23)], $actual);
ok('clave del cron de 23 caracteres se rechaza', $r['errores'] !== []);

$r = ajustes_validar(['empresa_email' => 'no-es-un-correo'], $actual);
ok('correo de contacto inválido se rechaza', $r['errores'] !== []);
$r = ajustes_validar(['empresa_email' => ''], $actual);
ok('correo de contacto vacío se acepta', $r['errores'] === []);
$r = ajustes_validar(['avisar_email_a' => 'a@b.com, mal, c@d.com'], $actual);
ok('una dirección de aviso inválida rechaza la lista', $r['errores'] !== [] && str_contains(implode(' ', $r['errores']), 'mal'));
$r = ajustes_validar(['avisar_email_a' => ' a@b.com ,c@d.com,, '], $actual);
eq('la lista de avisos se guarda normalizada', $r['valores']['avisar_email_a'] ?? null, 'a@b.com, c@d.com');

$r = ajustes_validar(['empresa_whatsapp' => '+54 9 11 5027-4827'], $actual);
eq('el WhatsApp se guarda sólo con dígitos', $r['valores']['empresa_whatsapp'] ?? null, '5491150274827');
$r = ajustes_validar(['empresa_whatsapp' => '123'], $actual);
ok('un WhatsApp de tres dígitos se rechaza', $r['errores'] !== []);
$r = ajustes_validar(['empresa_telefono' => 'llamame'], $actual);
ok('un teléfono con letras se rechaza', $r['errores'] !== []);

echo "-- Ajustes: volver a «Prueba» --\n";
$r = ajustes_validar(['app_entorno' => 'testing'], $actual);
ok('de Producción a Prueba sin confirmar se rechaza', $r['errores'] !== [] && !isset($r['valores']['app_entorno']));
$r = ajustes_validar(['app_entorno' => 'testing', 'confirmar_prueba' => '1'], $actual);
ok('con la confirmación se acepta', $r['errores'] === [] && ($r['valores']['app_entorno'] ?? '') === 'testing');
eq('y se informa el cambio para la auditoría', $r['entorno'], 'a_prueba');
$r = ajustes_validar(['app_entorno' => 'produccion'], $actual);
ok('quedarse en Producción no pide nada', $r['errores'] === [] && $r['entorno'] === null);
$r = ajustes_validar(['app_entorno' => 'produccion'], static fn($k, $d = '') => $d);
eq('de Prueba a Producción se informa', $r['entorno'], 'a_produccion');
$r = ajustes_validar(['app_entorno' => 'otra'], $actual);
ok('un modo que no existe se rechaza', $r['errores'] !== []);
contiene('el cambio a Prueba deja su propia línea en la auditoría', $pagA, "log_app('ajustes.entorno_prueba'");
contiene('la vista ofrece la confirmación en Producción', $vistaA, 'name="confirmar_prueba"');
contiene('si hay errores no se guarda nada', $pagA, "if (\$r['errores'])");

echo "-- Ajustes: casilla predeterminada --\n";
contiene('sin predeterminada hay una opción «sin elegir»', $vistaA, '<option value="0" selected>');
contiene('se marca con una sola sentencia', $pagA, 'SET predeterminada = CASE WHEN id = ? THEN 1 ELSE 0 END');

echo "-- Papelera: el plazo nunca baja de 7 días --\n";
if (function_exists('dias_papelera') && function_exists('setting_set') && isset($GLOBALS['__ajustes'])) {
    $antes = $GLOBALS['__ajustes']['papelera_dias'] ?? null;
    setting_set('papelera_dias', '');
    eq('vacío vale 60', dias_papelera(), 60);
    setting_set('papelera_dias', '2');
    eq('2 vale 7', dias_papelera(), 7);
    setting_set('papelera_dias', '90');
    eq('90 vale 90', dias_papelera(), 90);
    if ($antes === null) { unset($GLOBALS['__ajustes']['papelera_dias']); } else { $GLOBALS['__ajustes']['papelera_dias'] = $antes; }
} else {
    contiene('dias_papelera no baja de 7', $leer('app/campanas.php'), 'max(7, $n)');
}

echo "-- Confirmación (app.js) --\n";
$appJ = $leer('assets/js/app.js');
contiene('envía con requestSubmit(botón)', $appJ, 'form.requestSubmit(el)');
no_contiene('ya no usa el.form.submit() (perdía el name/value del botón)', $appJ, 'el.form.submit()');
contiene('respaldo para navegadores sin requestSubmit: campo oculto con el name/value', $appJ, "oculto.name  = nombre;");
contiene('Tab queda encerrado en la ventana', $appJ, "e.key === 'Tab'");
no_contiene('Enter ya no confirma esté donde esté el foco', $appJ, "if (e.key === 'Enter')  { cerrar(true); }");
contiene('el foco vuelve a donde estaba', $appJ, 'previo.focus()');
contiene('la ventana tiene título y texto asociados', $appJ, 'aria-labelledby="confTit"');
no_contiene('se quitó el bloque viejo de inactividad', $appJ, "login?expiro=");
// Todos los formularios que distinguen la acción por el botón y piden confirmación.
foreach (['views/feriados.php' => 'value="ignorar"', 'views/contactos.php' => 'value="aprobar"',
          'views/cuentas.php' => 'value="aprobar"', 'views/ajustes.php' => 'value="limpiar_prueba"'] as $v => $boton) {
    contiene("$v sigue teniendo su botón con name/value ($boton)", $leer('app/' . $v), $boton);
}

echo "-- Inactividad (idle.js) --\n";
$idleJ = $leer('assets/js/idle.js');
contiene('comparte la actividad entre pestañas', $idleJ, 'localStorage.setItem(CLAVE');
contiene('escucha la actividad de las otras pestañas', $idleJ, "addEventListener('storage'");
contiene('pregunta al servidor antes de cerrar, sin contar como actividad', $idleJ, "api-sesion?__vivo=1");
contiene('al vencer va a logout con el motivo', $idleJ, "logout?motivo=inactividad");
no_contiene('idle_ttl = 0 ya no se reemplaza por 600', $idleJ, '(cfg.idle | 0) || 600');
contiene('api-sesion informa cuánto falta para el corte', $leer('app/pages/api-sesion.php'), "'idle_resto'");

echo "-- Consultas en vivo (vivo.js, secciones.js) --\n";
$vivoJ = $leer('assets/js/vivo.js');
contiene('la campana consulta con __vivo', $vivoJ, "api-alertas?__vivo=1");
contiene('el avance de campañas consulta con __vivo', $vivoJ, "api-campana?__vivo=1");
contiene('la ficha de la campaña también se actualiza (antes pintarFicha no corría nunca)', $vivoJ,
   "doc.querySelector('[data-vivo-campanas], [data-vivo-campana]')");
contiene('el badge sólo entra como HTML si tiene la forma exacta', $vivoJ, 'function ponerBadge(');
contiene('el enlace de una alerta se revisa', $vivoJ, 'el.href = urlSegura(a.url)');
contiene('un reloj pausado se rearma al volver a la pestaña', $vivoJ, 'if (!reloj) { malas = 0;');
$secJ = $leer('assets/js/secciones.js');
contiene('zonas con el mismo nombre se aparean por orden', $secJ, 'var nueva  = nuevas[n - 1];');

echo "-- Desplegables (selects.js) y sector (campos.js) --\n";
$selJ = $leer('assets/js/selects.js');
contiene('anuncia la opción resaltada', $selJ, "setAttribute('aria-activedescendant'");
contiene('toma el rótulo del label', $selJ, "setAttribute('aria-labelledby'");
contiene('la tecla que abre no vuelve a procesarse', $selJ, 'e === eventoApertura');
contiene('Escape devuelve el foco al botón', $selJ, "var caja = abierto;");
contiene('sigue al select si se deshabilita por código', $selJ, "attributeFilter: ['disabled']");
contiene('un sector guardado fuera de la lista se conserva', $leer('assets/js/campos.js'), '(valor anterior)');

echo "-- Estilos (app.css) --\n";
$css = $leer('assets/css/app.css');
eq('.gsearch se define una sola vez (fuera del media query del celular)', substr_count($css, "\n.gsearch{"), 1);
eq('.brand-logo se define una sola vez', substr_count($css, '.brand-logo{'), 1);
no_contiene('el pulso ya no usa el verde viejo', $css, '95,138,18');
no_contiene('la página pública no usa degradado', $css, 'radial-gradient');
contiene('el halo de foco se distingue del fondo', $css, '--focus-ring:0 0 0 3px var(--primary-100)');
contiene('el placeholder tiene contraste suficiente', $css, '--placeholder:#776f83');

echo "-- Caché de los assets (bootstrap.php) --\n";
$bootE1 = $leer('app/bootstrap.php');
ok('ASSETS_V mira todos los JS y CSS', str_contains($bootE1, "glob(\$dir . 'js/*.js')") && str_contains($bootE1, "glob(\$dir . 'css/*.css')"));

echo "-- Cron por URL --\n";
$cronE1 = (string) @file_get_contents(dirname($WEB_E1) . '/cron.php');
if ($cronE1 !== '') { contiene('la clave del cron se compara en tiempo constante', $cronE1, 'hash_equals($configurada, $recibida)'); }

<?php
declare(strict_types=1);

// ============================================================
//  Casos sin base del lote B2: contactos, empresas, posibles clientes y
//  actividades. Los carga test/run_tests.php (bloque «Casos: b2»).
//  Funciones puras de crm.php y actividades.php, más algunas comprobaciones
//  sobre el código de las páginas y las vistas.
// ============================================================

$B2 = getenv('CRM_SRC') ?: (__DIR__ . '/../../app/web/app');
$b2Leer = static fn(string $rel): string => (string) @file_get_contents($B2 . '/' . $rel);

// ---- Conversión: el importe se lee como lo escribe la gente ----
eq('importe con puntos de miles', conversion_importe('1.500.000'), 1500000.0);
eq('importe con miles y coma decimal', conversion_importe('1.234,56'), 1234.56);
eq('importe a la inglesa', conversion_importe('1,234.56'), 1234.56);
eq('una sola coma es decimal', conversion_importe('1500,5'), 1500.5);
eq('un punto con tres cifras detrás son miles', conversion_importe('1.500'), 1500.0);
eq('un punto con dos cifras es decimal', conversion_importe('1500.50'), 1500.5);
eq('con signo de pesos y espacios', conversion_importe('$ 2.000'), 2000.0);
eq('con US$', conversion_importe('US$ 3.000,25'), 3000.25);
eq('vacío vale cero', conversion_importe(''), 0.0);
eq('letras no', conversion_importe('mil pesos'), null);
eq('negativo no', conversion_importe('-100'), null);
eq('dos decimales distintos no', conversion_importe('1,2,3.4,5'), null);
eq('miles mal agrupados no', conversion_importe('12.34.567'), null);

// ---- Conversión: moneda y fecha se validan ----
[$op, $err] = conversion_opciones(['crear_negocio' => '1', 'negocio_importe' => '1.234,56',
                                   'negocio_moneda' => 'usd', 'negocio_cierre' => '2026-12-31']);
eq('opciones válidas: importe, moneda y fecha normalizados',
   [$err, $op['negocio_importe'], $op['negocio_moneda'], $op['negocio_cierre']], [[], 1234.56, 'USD', '2026-12-31']);
[, $err] = conversion_opciones(['crear_negocio' => '1', 'negocio_moneda' => 'EUR']);
ok('una moneda que no es ARS ni USD se rechaza', isset($err['negocio_moneda']));
[, $err] = conversion_opciones(['crear_negocio' => '1', 'negocio_cierre' => '2026-02-30']);
ok('una fecha imposible se rechaza (antes rompía el INSERT)', isset($err['negocio_cierre']));
[, $err] = conversion_opciones(['crear_negocio' => '1', 'negocio_importe' => 'abc']);
ok('un importe que no se entiende se rechaza', isset($err['negocio_importe']));
[$op, $err] = conversion_opciones(['negocio_moneda' => 'EUR', 'negocio_importe' => 'abc']);
ok('sin negocio no se valida lo del negocio', $err === [] && $op['crear_negocio'] === false);
eq('un importe que ya es número pasa tal cual', conversion_opciones(['crear_negocio' => 1, 'negocio_importe' => 1.234])[0]['negocio_importe'], 1.23);

// ---- Conversión: el mensaje dice lo que pasó ----
contiene('empresa creada', conversion_mensaje(['cuenta_id' => 1, 'cuenta_nueva' => true, 'contacto_nuevo' => true]), 'se creó la empresa');
contiene('empresa reutilizada', conversion_mensaje(['cuenta_id' => 1, 'cuenta_nueva' => false, 'contacto_nuevo' => false]), 'se usó la empresa que ya existía');
contiene('sin empresa no dice «se creó la empresa»', conversion_mensaje(['cuenta_id' => null, 'contacto_nuevo' => true]), 'quedó sin empresa');
no_contiene('…ni la nombra como creada', conversion_mensaje(['cuenta_id' => null, 'contacto_nuevo' => true]), 'se creó la empresa');
contiene('negocio pedido sin embudo: avisa', conversion_mensaje(['cuenta_id' => 1, 'contacto_nuevo' => true, 'negocio_omitido' => true]), 'no se creó');

// ---- Papelera: propietario o administrador ----
ok('el administrador manda cualquier cosa a la papelera', papelera_permitida(5, 9, true));
ok('el propietario, lo suyo', papelera_permitida(9, 9, false));
ok('un comercial, lo de otro no', !papelera_permitida(5, 9, false));
ok('lo que no tiene propietario, cualquiera', papelera_permitida(null, 9, false));

// ---- Estado de la ficha: aprobada aunque incompleta ----
ok('incompleta y sin aprobar: pendiente', ficha_pendiente(['pendiente_norm' => 1, 'aprobado_en' => null]));
ok('incompleta pero aprobada: ya no', !ficha_pendiente(['pendiente_norm' => 1, 'aprobado_en' => '2026-11-01 10:00:00']));
ok('completa: no', !ficha_pendiente(['pendiente_norm' => 0, 'aprobado_en' => null]));

// ---- Ficha de empresa: totales por moneda ----
$tot = cuenta_totales_negocios([
    ['importe' => '1000', 'moneda' => 'ARS', 'etapa_tipo' => 'abierta'],
    ['importe' => '50',   'moneda' => 'USD', 'etapa_tipo' => 'abierta'],
    ['importe' => '200',  'moneda' => 'ARS', 'etapa_tipo' => 'abierta'],
    ['importe' => '70',   'moneda' => 'USD', 'etapa_tipo' => 'ganada'],
    ['importe' => '999',  'moneda' => 'ARS', 'etapa_tipo' => 'perdida'],
]);
eq('pesos y dólares no se mezclan', $tot, ['abierto' => ['ARS' => 1200.0, 'USD' => 50.0], 'ganado' => ['USD' => 70.0]]);

// ---- Actividades: fecha del formulario ----
eq('datetime-local a la base', actividad_fecha_form('2026-11-22T10:30'), '2026-11-22 10:30:00');
eq('con segundos también', actividad_fecha_form('2026-11-22T10:30:15'), '2026-11-22 10:30:15');
eq('vacío es vacío', actividad_fecha_form(''), '');
eq('una fecha imposible es null', actividad_fecha_form('2026-02-30T10:00'), null);
eq('texto es null', actividad_fecha_form('mañana'), null);

// ---- Lo que se ve en pantalla y lo que controla el servidor ----
foreach (['contactos', 'cuentas', 'posibles'] as $pag) {
    ok("exportar $pag pide administrador", preg_match("/exportar'\] \?\? ''\) === '1'\) \{\s*require_admin\(\);/", $b2Leer("pages/$pag.php")) === 1);
    contiene("la vista de $pag esconde «Exportar» a quien no es admin", $b2Leer("views/$pag.php"), '<?php if (auth_is_admin()): ?>');
}
foreach (['contactos', 'cuentas', 'posibles', 'actividades'] as $v) {
    contiene("la papelera de $v sólo para propietario o admin", $b2Leer("views/$v.php"), 'puede_mandar_papelera(');
}
contiene('el servidor controla la papelera', $b2Leer('pages/registro-eliminar.php'), 'puede_mandar_papelera($entidad, $fila');
contiene('«No incluirlo en campañas» se puede desmarcar', $b2Leer('views/contacto-form.php'), '<input type="hidden" name="no_email" value="0">');
contiene('crear una empresa al vuelo libera el selector', $b2Leer('views/contacto-form.php'), 'data-libera-requerido="cuenta_id"');
ok('la empresa nueva se crea después de validar',
   strpos($b2Leer('pages/contacto-form.php'), 'cuenta_asegurar(') > strpos($b2Leer('pages/contacto-form.php'), 'if (!$errores)'));
contiene('una reunión de la agenda no cambia de horario desde el formulario', $b2Leer('pages/actividad-form.php'), "foreach (['tipo', 'inicia_el', 'termina_el'] as \$k)");
contiene('y se ofrece mover la reserva', $b2Leer('views/actividad-form.php'), "reserva-mover?id=");
contiene('la ficha del posible cliente mira los módulos', $b2Leer('views/posible-ver.php'), '<?php if ($verActividades): ?>');
no_contiene('la ficha de la empresa ya no suma monedas mezcladas', $b2Leer('views/cuenta-ver.php'), 'money($abierto)');
contiene('el negocio de la conversión sólo con el módulo encendido', $b2Leer('views/posible-convertir.php'), '<?php if ($verNegocios): ?>');
no_contiene('la conversión no muestra el error crudo de la base', $b2Leer('pages/posible-convertir.php'), '$error = $e->getMessage()');
ok('la doble conversión se frena con un UPDATE condicional',
   preg_match('/WHERE id = \? AND convertido_en IS NULL AND eliminado_en IS NULL\',\s*\[current_user_id\(\), \$id\]\)->rowCount\(\)/', $b2Leer('crm.php')) === 1);
foreach (['contacto-form', 'cuenta-form', 'posible-form'] as $v) {
    no_contiene("$v: sitio web sin type=url (el ejemplo sin https:// no pasaba)", $b2Leer("views/$v.php"), '<input type="url"');
}
contiene('filtrar desde «Vencidas» no vuelve a «Pendientes»', $b2Leer('views/actividades.php'), '$vistaActual === \'pendientes\' ? \'\' : $vistaActual');
contiene('el enlace de Meet sólo si es https', $b2Leer('views/actividades.php'), "preg_match('#^https://#i', (string)\$a['meet_url'])");
contiene('el código de error no depende de la carpeta de instalación', $b2Leer('actividad.php'), "md5(\$loc)");

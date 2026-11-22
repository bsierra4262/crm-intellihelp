<?php
declare(strict_types=1);

// ============================================================
//  Casos del lote B1 sin base: negocios (importes, períodos, monedas,
//  fecha de cierre), importación (codificación, fechas) y lo de crm.php que
//  usa la entrada web (dominio corporativo, vencimiento con feriados,
//  recorte al largo de las columnas).
//
//  Lo carga run_tests.php con require; usa ok(), eq() y feq() de ahí.
// ============================================================

$SRC = getenv('CRM_SRC') ?: (__DIR__ . '/../../app/web/app');
require_once $SRC . '/negocios.php';
require_once $SRC . '/importador.php';

echo "-- Importes escritos de cualquier forma --\n";
feq('lo que manda un type=number', importe_parsear('1500000.5'), 1500000.5);
feq('con puntos de miles', importe_parsear('1.500.000'), 1500000);
feq('con miles y coma decimal', importe_parsear('1.500.000,50'), 1500000.5);
feq('coma decimal sola', importe_parsear('1500,50'), 1500.5);
feq('formato inglés', importe_parsear('1,500.50'), 1500.5);
feq('«1.500» es mil quinientos', importe_parsear('1.500'), 1500);
feq('«0.500» es medio', importe_parsear('0.500'), 0.5);
feq('con símbolo y espacios', importe_parsear('$ 2.000'), 2000);
eq('vacío no es un número', importe_parsear(''), null);
eq('letras tampoco', importe_parsear('abc'), null);

echo "-- Períodos --\n";
ok('2026-11 es un período', periodo_valido('2026-11'));
ok('2026-13 no', !periodo_valido('2026-13'));
ok('2026-00 tampoco', !periodo_valido('2026-00'));
ok('ni otro formato', !periodo_valido('11/2026'));

echo "-- Monedas separadas --\n";
eq('pesos y dólares por separado', montos_por_moneda(['ARS' => 1500, 'USD' => 200]), '$ 1.500,00 · US$ 200,00');
eq('una moneda en cero no se muestra', montos_por_moneda(['ARS' => 0, 'USD' => 50]), 'US$ 50,00');
eq('todo en cero', montos_por_moneda([]), '$ 0,00');
eq('sumar_por_moneda separa', sumar_por_moneda([['moneda' => 'ARS', 'x' => 10], ['moneda' => 'USD', 'x' => 3],
    ['moneda' => 'ARS', 'x' => 5]], 'x'), ['ARS' => 15.0, 'USD' => 3.0]);

echo "-- Fecha de cierre de un negocio --\n";
$t0 = strtotime('2026-11-22 10:00:00');
eq('abierta: sin fecha de cierre', negocio_cerrado_en('abierta', 'ganada', '2026-03-01 09:00:00', $t0), null);
eq('ya ganado y sigue ganado: conserva la fecha', negocio_cerrado_en('ganada', 'ganada', '2026-03-01 09:00:00', $t0),
   '2026-03-01 09:00:00');
eq('recién se gana: hoy', negocio_cerrado_en('ganada', 'abierta', null, $t0), '2026-11-22 10:00:00');
eq('de ganado a perdido: hoy', negocio_cerrado_en('perdida', 'ganada', '2026-03-01 09:00:00', $t0), '2026-11-22 10:00:00');
eq('alta directa en ganada: hoy', negocio_cerrado_en('ganada', null, null, $t0), '2026-11-22 10:00:00');

echo "-- Codificación del CSV --\n";
[$txt, $cod] = csv_a_utf8("\xEF\xBB\xBFT\xC3\xADtulo;M\xC3\xB3vil\n");
eq('UTF-8 con BOM: se saca el BOM', [$txt, $cod], ["Título;Móvil\n", 'UTF-8']);
[$txt, $cod] = csv_a_utf8("T\xC3\xADtulo;M\xC3\xB3vil\n");
eq('UTF-8 sin BOM: queda igual', [$txt, $cod], ["Título;Móvil\n", 'UTF-8']);
[$txt, $cod] = csv_a_utf8("T\xEDtulo;M\xF3vil;Correo electr\xF3nico;Tel\xE9fono Directo;Mu\xF1oz\n");
eq('Windows-1252 (Excel en castellano): se convierte', [$txt, $cod],
   ["Título;Móvil;Correo electrónico;Teléfono Directo;Muñoz\n", 'Windows-1252']);
[$txt, $cod] = csv_a_utf8("\xFF\xFE" . mb_convert_encoding("Título\n", 'UTF-16LE', 'UTF-8'));
eq('UTF-16 con BOM: se convierte', [$txt, $cod], ["Título\n", 'UTF-16']);
[$txt] = csv_a_utf8("Precio \x80 10\n");
eq('el símbolo del euro de Windows-1252', $txt, "Precio € 10\n");

echo "-- Encabezados sin importar tildes ni mayúsculas --\n";
$filaCab = csv_fila(['TÍTULO', 'correo electronico', 'Nombre'], ['Gerente', 'a@b.com', 'Ana']);
eq('«TÍTULO» se reconoce como Título', valor_zoho($filaCab, ['Título', 'Title']), 'Gerente');
eq('«correo electronico» sin tilde también', valor_zoho($filaCab, ['Correo electrónico']), 'a@b.com');

echo "-- Fecha de alta de Zoho --\n";
eq('día/mes/año: 01/02/2024 es el 1 de febrero', fecha_zoho('01/02/2024 10:30'), '2024-02-01 10:30:00');
eq('13/02/2024 ya no se pierde', fecha_zoho('13/02/2024'), '2024-02-13 00:00:00');
eq('ISO', fecha_zoho('2024-02-01 10:30:15'), '2024-02-01 10:30:15');
eq('con AM/PM', fecha_zoho('05/03/2024 02:15 PM'), '2024-03-05 14:15:00');
eq('fecha imposible: no se inventa', fecha_zoho('31/02/2024'), null);
eq('basura: null', fecha_zoho('ayer'), null);

echo "-- Corridas de importación cortadas --\n";
$ahoraLote = strtotime('2026-11-22 12:00:00');
eq('vieja sin estado: terminada', importar_estado_lote(['resumen' => '{"Sin nombre ni apellido":2}', 'creado_en' => '2026-01-01 10:00:00'], $ahoraLote), 'terminada');
eq('en curso hace 5 minutos', importar_estado_lote(['resumen' => '{"estado":"en curso"}', 'creado_en' => '2026-11-22 11:55:00'], $ahoraLote), 'en curso');
eq('en curso hace dos horas: cortada', importar_estado_lote(['resumen' => '{"estado":"en curso"}', 'creado_en' => '2026-11-22 10:00:00'], $ahoraLote), 'cortada');

echo "-- Dominio corporativo (entrada web) --\n";
eq('mail.empresa.com es corporativo', email_dominio_corporativo('ana@mail.empresa.com'), 'mail.empresa.com');
eq('me.empresa.com.ar también', email_dominio_corporativo('ana@me.empresa.com.ar'), 'me.empresa.com.ar');
eq('gmail.com.ar sigue siendo gratuito', email_dominio_corporativo('ana@gmail.com.ar'), null);
eq('me.com sigue siendo gratuito', email_dominio_corporativo('ana@me.com'), null);
eq('yahoo.com.ar sigue siendo gratuito', email_dominio_corporativo('ana@yahoo.com.ar'), null);

echo "-- Vencimiento de la consulta web con feriados --\n";
eq('el día siguiente es feriado: pasa al otro', entrada_web_vencimiento(strtotime('2026-11-22 15:00:00'), ['2026-11-23']),
   '2026-11-24 12:00:00');
eq('viernes con lunes feriado: martes', entrada_web_vencimiento(strtotime('2026-11-20 15:00:00'), ['2026-11-23']),
   '2026-11-24 12:00:00');
eq('sin feriados: el próximo hábil', entrada_web_vencimiento(strtotime('2026-11-20 15:00:00'), []), '2026-11-23 12:00:00');

echo "-- Recorte al largo de las columnas --\n";
$rc = recortar_a_columnas(['cargo' => str_repeat('á', 200), 'codigo_postal' => '1234', 'x' => str_repeat('z', 999), 'nombre' => null],
                          CONTACTOS_LARGOS);
eq('el cargo se corta en 150 caracteres (no bytes)', mb_strlen($rc['cargo']), 150);
eq('lo corto queda igual', $rc['codigo_postal'], '1234');
eq('lo que no tiene largo no se toca', strlen($rc['x']), 999);
eq('los null quedan null', $rc['nombre'], null);

<?php
declare(strict_types=1);

// ============================================================
//  Casos sin base del lote A (núcleo, arranque y pantallas generales).
//  Los carga run_tests.php; usan ok(), eq(), contiene() y no_contiene().
//  Sólo funciones puras de helpers.php y registros.php.
// ============================================================

// ---- Búsqueda: % y _ se buscan literales ----
eq('like_contiene envuelve en %', like_contiene('acme'), '%acme%');
eq('un % escrito por la persona no es comodín', like_contiene('50%'), '%50\\%%');
eq('ni un _', like_contiene('a_b'), '%a\\_b%');
eq('la barra invertida se duplica primero', like_contiene('a\\b'), '%a\\\\b%');

// ---- Descarga de adjuntos: nombre con acentos y sin romper la cabecera ----
$cd = descarga_disposicion('Cotización "final".pdf');
contiene('lleva filename en ASCII', $cd, 'filename="Cotizaci_n final.pdf"');
contiene('y filename* en UTF-8 con el acento', $cd, "filename*=UTF-8''Cotizaci%C3%B3n%20final.pdf");
no_contiene('sin saltos de línea que metan otra cabecera', descarga_disposicion("a\r\nSet-Cookie: x=1.pdf"), "\n");
contiene('un nombre vacío baja como «archivo»', descarga_disposicion('""'), 'filename="archivo"');

// ---- Monedas: no se suman pesos con dólares ----
eq('sumar_por_moneda agrupa', sumar_por_moneda([
    ['moneda' => 'ARS', 'total' => '100'], ['moneda' => 'USD', 'total' => 5],
    ['moneda' => 'ARS', 'total' => 50], ['moneda' => '', 'total' => 1],
], 'total'), ['ARS' => 151.0, 'USD' => 5.0]);
eq('money_por_moneda: pesos primero, dólares después', money_por_moneda(['USD' => 200, 'ARS' => 1500]),
   '$ 1.500,00 · US$ 200,00');
eq('sin importes, cero en pesos', money_por_moneda([]), '$ 0,00');
eq('una sola moneda, una sola cifra', money_por_moneda(['USD' => 10]), 'US$ 10,00');

// ---- Duraciones de la auditoría: las configuradas, no «10 min» fijo ----
eq('600 s son 10 min', duracion_legible(600), '10 min');
eq('28800 s son 8 h', duracion_legible(28800), '8 h');
eq('5400 s son 1 h 30 min', duracion_legible(5400), '1 h 30 min');
eq('0 es sin límite', duracion_legible(0), 'sin límite');

// ---- Cierre de sesión por GET: sólo desde el propio sitio ----
eq('Sec-Fetch-Site same-origin: propio', pedido_mismo_origen(['HTTP_SEC_FETCH_SITE' => 'same-origin']), true);
eq('none (dirección escrita): propio', pedido_mismo_origen(['HTTP_SEC_FETCH_SITE' => 'none']), true);
eq('cross-site: ajeno', pedido_mismo_origen(['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_REFERER' => 'https://intellihelp.tech/crm/']), false);
eq('same-site (otro subdominio): ajeno', pedido_mismo_origen(['HTTP_SEC_FETCH_SITE' => 'same-site']), false);
eq('sin Sec-Fetch, Referer del mismo host: propio',
   pedido_mismo_origen(['HTTP_REFERER' => 'https://intellihelp.tech/crm/dashboard', 'HTTP_HOST' => 'intellihelp.tech']), true);
eq('sin Sec-Fetch, Referer de otro host: ajeno',
   pedido_mismo_origen(['HTTP_REFERER' => 'https://evil.tld/x', 'HTTP_HOST' => 'intellihelp.tech']), false);
eq('sin nada: no se sabe (se pide confirmar)', pedido_mismo_origen(['HTTP_HOST' => 'intellihelp.tech']), null);

// ---- Token vencido (419): lo escrito se muestra, no se pierde ----
eq('csrf_campos_escritos deja sólo lo escrito', csrf_campos_escritos([
    '_csrf' => 'x', 'id' => '3', 'entidad' => 'contacto', 'cuerpo' => "  Llamó el cliente\n ", 'vacio' => '',
    'etiquetas' => ['vip', 'norte'],
]), ['cuerpo' => 'Llamó el cliente', 'etiquetas' => 'vip, norte']);

// ---- Papelera: propietario o administrador; sin propietario, cualquiera ----
ok('el admin manda cualquier cosa a la papelera', puede_mandar_papelera('contacto', ['propietario_id' => 5], 9, true));
ok('el propietario, lo suyo', puede_mandar_papelera('contacto', ['propietario_id' => 5], 5, false));
ok('otro comercial, no', !puede_mandar_papelera('negocio', ['propietario_id' => 5], 9, false));
ok('sin propietario, cualquiera', puede_mandar_papelera('cuenta', ['propietario_id' => null], 9, false));
ok('una lista: quien la creó', puede_mandar_papelera('lista', ['creado_por' => 9], 9, false)
   && !puede_mandar_papelera('lista', ['creado_por' => 4], 9, false));
ok('una campaña: el propietario o quien la creó', puede_mandar_papelera('campana', ['propietario_id' => 5, 'creado_por' => 9], 9, false)
   && puede_mandar_papelera('campana', ['propietario_id' => 5, 'creado_por' => 9], 5, false)
   && !puede_mandar_papelera('campana', ['propietario_id' => 5, 'creado_por' => 9], 7, false));
ok('sin sesión no hay propietario que coincida', !puede_mandar_papelera('contacto', ['propietario_id' => 5], null, false));

// ---- Adjuntos: su autor o un administrador ----
ok('el autor borra su adjunto', puede_borrar_adjunto(['creado_por' => 3], 3, false));
ok('otro no', !puede_borrar_adjunto(['creado_por' => 3], 4, false));
ok('el admin sí', puede_borrar_adjunto(['creado_por' => 3], 4, true));
ok('sin autor registrado, sólo el admin', !puede_borrar_adjunto(['creado_por' => null], 4, false));
eq('un .php no queda como .php en disco', adjunto_ext_disco('shell.php'), 'bin');
eq('ni un .phtml', adjunto_ext_disco('x.PHTML'), 'bin');
eq('un PDF conserva la extensión', adjunto_ext_disco('Presupuesto.PDF'), 'pdf');
eq('sin extensión, .bin', adjunto_ext_disco('LEEME'), 'bin');

// ---- Módulo de cada entidad (para las rutas compartidas) ----
eq('módulos de las entidades', array_map('entidad_modulo', ['contacto', 'cuenta', 'posible_cliente', 'negocio', 'actividad', 'campana', 'lista', 'plantilla']),
   [null, null, 'posibles', 'negocios', 'actividades', 'campanas', 'campanas', 'campanas']);
eq('tabla de cada entidad', [entidad_tabla('posible_cliente'), entidad_tabla('plantilla'), entidad_tabla('usuarios')],
   ['posibles_clientes', 'plantillas', null]);

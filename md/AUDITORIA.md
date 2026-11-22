# AUDITORÍA DEL CÓDIGO · CRM INTELLIHELP

Fecha: 22/11/2026 · Rama: `docs/auditoria-comentarios` · Base: `80a38d9` (web-api-v1-produccion)

## Qué se hizo

Se recorrió el repositorio completo, archivo por archivo: unos 197 archivos y 32.000 líneas entre PHP, vistas, JS, CSS, SQL, Python y pruebas. En cada archivo se hicieron dos cosas:

1. **Se comentó a fondo.**
   - Cada archivo tiene un encabezado con qué hace, quién lo llama, qué tablas toca, de qué depende y qué trampas tiene.
   - Cada función tiene un docblock.
   - Se explica el porqué de la lógica que no es obvia.
   - Los comentarios que estaban mal se corrigieron.
2. **Se auditó.** Se buscaron bugs, problemas de seguridad, datos que se pueden perder, condiciones de carrera, fechas y zonas horarias, rendimiento y diferencias con la documentación de `md/`. Lo encontrado está en este documento, no en el código.

**El código no cambió.** Hay sólo comentarios agregados o corregidos. Se comprobó así:

- Un verificador compara cada archivo con su versión anterior.
  - En PHP compara el código pieza por pieza, sin tener en cuenta comentarios ni espacios.
  - En JS, Python y sh controla además que el archivo siga siendo válido.
  - En todos los archivos exige que cada línea agregada o quitada sea un comentario.
  - Resultado: 0 fallas.
- Las pruebas dan lo mismo que antes: `run_tests.php` 418/418 y `ui_test.php` 331/331.
- Ningún bug se arregló en esta pasada. Este documento es la lista de trabajo para decidir qué se arregla y en qué orden.

## Números

| Gravedad | Cantidad |
|---|---|
| Crítico | 1 |
| Alto | 25 |
| Medio | 78 |
| Bajo | 142 |

| Lote | Qué cubre | Crít. | Alto | Medio | Bajo |
|---|---|---|---|---|---|
| A | Núcleo: arranque, sesión, permisos, ruteo, adjuntos, pantallas generales | 0 | 1 | 7 | 21 |
| B1 | crm.php, negocios, embudos, importador, cuotas | 0 | 7 | 10 | 12 |
| B2 | Contactos, cuentas, posibles clientes, actividades | 0 | 1 | 13 | 14 |
| C1 | Agenda de reuniones y reservas | 0 | 0 | 4 | 17 |
| C2 | API web, entradas web, feriados, Google Calendar | 0 | 1 | 7 | 14 |
| D1 | Campañas, plantillas, seguimiento, baja | 0 | 4 | 9 | 13 |
| D2 | Cuentas de correo, IMAP, conversaciones, listas, editor | 1 | 2 | 9 | 12 |
| E1 | CSS/JS del frontend y Ajustes | 0 | 3 | 7 | 12 |
| E2 | Esquema, migraciones, cron, reinicio, deploy | 0 | 4 | 6 | 17 |
| F | Pruebas automáticas | 0 | 2 | 6 | 10 |

## Prioridades

Ésta es la selección de lo más urgente, agrupada por tipo de riesgo. El detalle de cada punto, con archivo, línea, escenario y sugerencia, está en la sección de su lote.

Los marcados ✔ los volvió a revisar a mano quien juntó el informe, leyendo el código. El resto los informó el agente de cada lote, que leyó el código, pero no se controlaron por segunda vez.

### 1. Funciones rotas hoy

| # | Qué pasa | Dónde | Lote |
|---|---|---|---|
| 1 | ✔ **Contestar un correo desde el CRM falla siempre.** La pantalla llama a `csrf_check()`, que no existe (la función del sistema es `verify_csrf()`). | pages/conversacion.php | D2 |
| 2 | **Una casilla IMAP puede quedar trabada para siempre.** Basta un correo con un juego de caracteres raro: la conversión lanza una excepción y la marca de lectura no avanza. Se dejan de detectar rebotes y respuestas. | imap.php | D2 |
| 3 | **Las respuestas de Outlook, Apple Mail, Yahoo y Thunderbird se guardan vacías.** El boundary se busca después de pasar todo a minúsculas, y la marca avanza igual, así que el contenido se pierde. | imap.php `mime_partes` | D2 |
| 4 | ✔ **«Borrar los datos de prueba» no borra.** La confirmación envía el formulario con `form.submit()`, que no manda el botón apretado, así que llega `accion=guardar`. Por lo mismo, «Se trabaja» y «Eliminar» de la pantalla de feriados no hacen nada. | assets/js/app.js | E1 |
| 5 | **Cada vez que se guarda Ajustes se apagan los avisos de sistema.** Dejan de llegar por correo los avisos de Google caído o de una casilla rechazada. | pages/ajustes.php | E1 |
| 6 | ✔ **Si los cron de producción y de pruebas coinciden, una de las dos instalaciones saltea la corrida entera.** El bloqueo tiene un nombre fijo y vale para todo el servidor. | app/cron.php | E2 |
| 7 | **Si Google contesta freeBusy con un error, se publican todos los horarios como libres.** Se pueden encimar reuniones. | gcal.php `gcal_ocupado` | C2 |

### 2. Riesgo de tocar producción por error

| # | Qué pasa | Dónde | Lote |
|---|---|---|---|
| 8 | ✔ **`reinicio.php` tiene una sola barrera: el ajuste `app_entorno`.** Si la fila falta, vale 'testing', y no se mira ni la carpeta ni el nombre de la base. Si falta `crm-pruebas.php`, bootstrap carga la config de producción. Un reinicio podría vaciar la base real. | app/reinicio.php | E2 |
| 9 | ✔ **`deploy.py config` siempre escribe la config de producción**, aunque se pase `--pruebas`. | bin/deploy.py | E2 |
| 10 | **`deploy.py` sin `--pruebas` va a producción sin confirmar.** Basta un typo como `--prueba`. | bin/deploy.py | E2 |
| 11 | **`render_test.php`, `render.sh` y `vivo_test.php` corren contra producción** si no se les pasa otra ruta. Crean usuarios admin, y `vivo_test` renombra un contacto real sin `finally`. | test/ | F |

### 3. Datos que se pierden o se pisan sin aviso

| # | Qué pasa | Dónde | Lote |
|---|---|---|---|
| 12 | **Editar un negocio o una actividad borra la empresa, el contacto o el negocio vinculado** cuando quedan fuera de los desplegables, que se cortan en 3.000 de unas 12.000 empresas y 13.500 contactos. | negocio-form, actividad-form | B1, B2 |
| 13 | **Editar un negocio de otro embudo lo muda a la primera etapa del embudo predeterminado.** | negocio-form | B1 |
| 14 | **El importador no maneja CSV en Windows-1252**, que es lo que guarda Excel en castellano: se pierden correos y teléfonos y fallan las filas con tildes. Además, al reimportar pisa el propietario de miles de contactos y «actualiza» contactos que están en la papelera. | importador.php | B1 |
| 15 | **`cerrado_en` se recalcula en cada guardado:** la fecha real de cierre se pierde. | negocios.php | B1 |
| 16 | **Importes mal leídos:** «1.500.000» se guarda como 1,50, y «1.234,56» como 1.234. | posible-convertir | B1, B2 |
| 17 | **Vaciar «Días en la papelera» en Ajustes la deja en 1 día.** La purga del cron borra de verdad lo que tenga más de un día. | pages/ajustes.php | E1 |
| 18 | **Cualquier usuario puede borrar del disco, en el acto, cualquier adjunto.** No hay papelera ni forma de recuperarlo. | adjunto-subir.php, registros.php | A |
| 19 | **Una pestaña olvidada cierra por inactividad la sesión de todas las pestañas.** Se pierde lo que se estaba escribiendo. | assets/js/idle.js | E1 |

### 4. Seguridad

| # | Qué pasa | Dónde | Lote |
|---|---|---|---|
| 20 | **XSS guardado en plantillas, por tres caminos:**<br>• el JSON del editor va sin `JSON_HEX_TAG`;<br>• el servidor acepta `tipo=html` crudo;<br>• `{{nombre}}` y las demás variables se pegan sin escapar, y un nombre cargado desde la web llega a la vista previa dentro del CRM. | campanas.php, plantilla-form, campana-ver | D1 |
| 21 | **Correos entrantes:** el iframe usa `allow-same-origin` y el HTML se limpia con una lista negra de expresiones regulares. Es un XSS plausible. | views/conversacion.php, mensajes.php | D2 |
| 22 | **No hay control de propietario ni de rol** para mandar a la papelera, exportar toda la base a CSV, ni iniciar o cancelar campañas. Hay que definir qué puede hacer un comercial. | registro-eliminar, contactos, campana-accion | A, B2, D1 |

### 5. Correo, aspectos legales y reputación

| # | Qué pasa | Dónde | Lote |
|---|---|---|---|
| 23 | **El modo prueba no bloquea los envíos.** La documentación y `es_testing()` dicen que sí, pero no existe ninguna lista blanca: en crm-pruebas los correos salen de verdad. | campanas.php, db.php | D1 |
| 24 | **Purgar una campaña borra sus tokens:** los enlaces de baja de correos ya entregados dejan de funcionar. | campanas.php `purgar_papelera` | D1 |
| 25 | **Los contactos que están en la papelera siguen recibiendo campañas.** | `lista_miembro_sql` | D1 |
| 26 | **Un 5xx del servidor de correo se trata como error temporal:** el mismo mensaje rechazado se manda 20 veces. | envio.php | D1 |
| 27 | **La IP de visitantes sin sesión se guarda en claro** en baja y en el píxel de apertura. Contradice la Ley 25.326 tal como la aplica la propia documentación. | baja.php, px.php | A |
| 28 | **Al rebote se le atribuyen direcciones de más:** sin Final-Recipient se toman todas las direcciones del cuerpo y se archivan contactos por error. | imap.php | D2 |

### 6. Base de datos e instalación

| # | Qué pasa | Dónde | Lote |
|---|---|---|---|
| 29 | **`schema.sql` no trae las migraciones 002 a 007** (pero sí 008 y 009). Una instalación nueva hecha sólo con el schema falla en campañas, contactos y alertas. | app/db/schema.sql | E2 |
| 30 | **Las migraciones 005 a 008 usan `IF NOT EXISTS` en ALTER**, que existe sólo en MariaDB. Fallan en MySQL 8, que es el que nombra el schema. | app/db/ | E2, C2 |

## Partes sin pruebas automáticas

Según el lote F, `run_tests.php` no ejercita estas partes; `ui_test.php` sólo busca textos en el código.

- **Login y permisos:** auth.php, CSRF, el control del dominio en el login de Google, el apagado por módulo.
- **Limpieza del HTML entrante:** `mensaje_html_seguro()` no tiene ningún caso.
- **Adjuntos:** subida y borrado, sin pruebas de tipo, tamaño ni ruta.
- **Campañas:** preparación, despacho, retome de envíos trabados, baja y reactivación; también el armado MIME y SMTP.
- **IMAP:** `mime_partes`, `mime_a_utf8`, la lectura de la casilla. Justo donde están los hallazgos 2 y 3.
- **Negocios y embudo**, el importador de Zoho (salvo la normalización) y el CRUD de crm.php.
- **Borrado de datos:** limpieza de datos de prueba, purga de la papelera, reinicio.php.
- **Otros:** Google Calendar, notificaciones, cuentas de correo, el comportamiento del cron.

## Documentación que no coincide con el código

- **DOCUMENTACION-MAESTRA §11:**
  - El modo prueba con lista blanca no existe.
  - Dice «un correo por campaña por corrida»; manda varios.
  - Dice pausas de 25 a 45 s; el código acepta desde 5 s.
  - La tabla figura como `eventos_envio`; la real es `envio_eventos`.
  - Dice «cron cada 5 min», pero el código está pensado para cada minuto.
  - El cruce de respuestas por Message-ID no está implementado: se cruza por dirección.
- **DOCUMENTACION-MAESTRA §10:** dice que la reserva «es todo base de datos, menos de un segundo». En realidad consulta Google en vivo.
- **DOCUMENTACION-MAESTRA:142:** dice que `?v=` se sube a mano. Hoy sale de la fecha de modificación de los archivos.
- **DOCUMENTACION-MAESTRA:155:** nombra tablas de actividades que no existen.
- **INTEGRACION-WEB §5.1:** dice que `api/estado` acepta cualquier clave. Rechaza las de escritura.
- **INSTALL.md:** dice «32 tablas»; el schema crea 35. Además, el schema solo no alcanza (ver hallazgo 29).
- **Textos visibles en pantalla que dicen algo falso:**
  - **Agenda:** «las reservas se siguen tomando» sin Google conectado.
  - **Claves de API:** la fórmula de la firma está desactualizada; quien la siga recibe siempre 401.
  - **Negocios:** el aviso de probabilidad no se cumple al editar, y apagar una etapa no avisa que saca sus negocios del tablero.
  - **Listas y campañas:** «60 días» de papelera está fijo, pero el plazo real sale de un ajuste.

## Sugerencia de orden

1. **Arreglos cortos que destraban funciones rotas:**
   - `verify_csrf` en conversacion (1).
   - `requestSubmit` en app.js (4).
   - El aviso de sistema en Ajustes (5).
   - El nombre del bloqueo del cron (6).
   - Los errores de freeBusy (7).
   - IMAP (2 y 3).
2. **Blindar producción:** reinicio.php, deploy.py y las pruebas que apuntan a producción (8 a 11).
3. **Pérdida de datos:** desplegables con buscador o con el valor actual (12 y 13), importador (14), `cerrado_en` e importes (15 y 16), validación de Ajustes (17), adjuntos (18).
4. **Seguridad:** XSS de plantillas y de conversaciones (20 y 21). Definir permisos por rol (22).
5. **Correo y legal:** puntos 23 a 28.
6. **Esquema y documentación:** puntos 29 y 30, y la sección anterior.
7. **Pruebas para cada arreglo**, empezando por IMAP, CSRF y permisos.

---

# Detalle por lote

Cada lote lista sus hallazgos de más a menos grave, con `archivo:línea`, el escenario concreto y una sugerencia. Salvo que se indique otra cosa, los números de línea son de la versión anterior a los comentarios (`80a38d9`). En la rama comentada están corridos: buscá por el nombre de la función.

## Lote A — Núcleo, arranque y pantallas generales
Verificador: fallas: 0. 48 archivos: index.php, dos .htaccess, config.example.php; bootstrap, db, auth, helpers, modulos, icons, notificaciones, registros, datos_prueba; 21 páginas y 14 vistas generales.

### Resumen
Columna vertebral. Todo entra por .htaccess → index.php (ruta en ?r=). bootstrap.php encuentra la config de la instalación por el nombre de la carpeta, conecta PDO con la zona horaria de PHP, abre la sesión e impone el cierre automático (10 min de inactividad, 8 h máximo). Luego cuatro controles en orden: la ruta existe en la tabla, hay sesión, el módulo está encendido (modulos.php), y rol admin si la ruta lo pide. Recién ahí se incluye pages/<ruta>.php, que dibuja con render() (vista + layout), responde JSON o redirige. helpers.php: escape, CSRF, flash, refresco «vivo», CSV seguro, formatos. registros.php: notas, etiquetas y adjuntos polimórficos. notificaciones.php: alertas en pantalla y por correo. Pantallas: panel, búsqueda, papelera, alertas, reportes, usuarios, bitácora CRON.

### Hallazgos
- [ALTO] pages/adjunto-subir.php:21 + registros.php:248 — Cualquier usuario con sesión borra cualquier adjunto por id (secuencial), y el archivo se borra del disco en el acto, sin recuperación. Contradice «nada se borra de golpe» (DOCUMENTACION-MAESTRA §2.5, §14.5). Sugerencia: autor o admin como en nota-eliminar, y unlink en el purgado del cron.
- [MEDIO] pages/registro-eliminar.php:42 — Eliminar no controla rol, propietario ni módulo: cualquiera manda a la papelera contactos, empresas, negocios, campañas, listas o plantillas de otros, incluso con el módulo apagado (sólo un admin restaura).
- [MEDIO] datos_prueba.php:138 — DELETE FROM notificaciones borra todas las alertas, también las reales, al pasar a producción. Sugerencia: sólo las ligadas a ids sembrados.
- [MEDIO] datos_prueba.php:119 — notas.registro_id no existe (es entidad + entidad_id): el DELETE falla siempre, el try/catch lo calla y quedan notas huérfanas.
- [MEDIO] notificaciones.php:131-135 — leida_en es una sola columna: si alguien marca leída una alerta del equipo (usuario_id NULL) desaparece para todos («marcar todo leído» oculta respuestas de clientes al resto). Sugerencia: tabla de lecturas por usuario.
- [MEDIO] notificaciones.php:173 — El correo de alertas va a avisar_email_a o admins, nunca al dueño de una alerta personal; la ayuda del tipo 'web' dice otra cosa.
- [MEDIO] helpers.php:125 (client_ip) — pages/baja.php:45 y pages/px.php:25 guardan en claro la IP de visitantes sin sesión, de cabeceras falsificables. Contradice §6 («la IP de quien no tiene sesión no se guarda en claro», Ley 25.326). Sugerencia: ip_hash(ip_confiable()).
- [MEDIO] bootstrap.php:86 + registros.php:207 — Sin `storage` en el config se usa dirname(WEBROOT)/storage, que en Hostinger cae dentro de public_html, y adjunto_guardar acepta cualquier extensión incluida .php. Hoy el config lo define. Sugerencia: fallar si falta o lista de extensiones.
- [BAJO] pages/buscar.php:35 — Búsqueda global muestra posibles y negocios con esos módulos apagados (enlaces a rutas bloqueadas); % y _ sin escapar (línea 23).
- [BAJO] pages/adjunto.php:17 — Cualquiera baja cualquier adjunto por id, aunque el registro esté en papelera; nombre de descarga sin filename* (acentos, línea 42).
- [BAJO] views/layout.php:204 / pages/logout.php — Logout por GET sin token: un sitio externo puede desloguear.
- [BAJO] pages/adjunto-subir.php:36 — Muestra el mensaje crudo de cualquier excepción (PDOException).
- [BAJO] pages/adjunto-subir.php:39, nota-eliminar.php:31 — Redirigen a HTTP_REFERER sin validar (redirección abierta acotada por POST+CSRF).
- [BAJO] pages/nota-guardar.php, etiquetas-guardar.php, adjunto-subir.php — No comprueban que el registro exista (tabla polimórfica sin FK).
- [BAJO] registros.php:122 — etiquetas_asignar DELETE+INSERT sin transacción.
- [BAJO] notificaciones.php:168 — Alertas de tipos con aviso apagado nunca se marcan: al encenderlo sale un correo con todo lo acumulado (hasta 50). Color fijo #663399 ignora app.brand (línea 203).
- [BAJO] pages/usuario-guardar.php:30 — Carrera: dos admins que se quitan el rol a la vez dejan el sistema sin admin; user_update no lo protege.
- [BAJO] views/usuarios.php:51 — <form> abre en una celda y cierra en otra: frágil.
- [BAJO] views/dashboard.php:105 — «Ganado este mes · desde el dd/mm» muestra hoy, no el 1.º del mes.
- [BAJO] pages/dashboard.php:38 — Un GET escribe en app_settings (panel_vista_<uid>).
- [BAJO] pages/reportes.php:33 — «Fuentes» y «Campañas» son históricos aunque la pantalla filtra por período.
- [BAJO] views/_notas.php:56 — Botón borrar en todas las notas (el server sólo deja al autor/admin); dice «se borra definitivamente» pero es lógico y no hay pantalla para recuperar.
- [BAJO] pages/api-sesion.php:21 — Rama casi inalcanzable: sin sesión index.php responde 302, no 440.
- [BAJO] pages/img.php:17 — preg_replace que no hace nada (código muerto).
- [BAJO] bootstrap.php:236 — Auditoría dice «10 min» y «8 h» fijos aunque idle_ttl/abs_ttl son configurables.
- [BAJO] bootstrap.php:45-46 — Usuario real del hosting (u566451014) escrito en código; la doc lo enmascara.
- [BAJO] app/web/.htaccess:70 — FilesMatch no cubre .bak, .env, .txt, .sh; config.local.php es candidato de config dentro del webroot.
- [BAJO] helpers.php:251 — verify_csrf corta con 419 en texto plano: si la sesión venció se pierde lo escrito.
- [BAJO] DOCUMENTACION-MAESTRA.md:142 — Dice que ?v=NNN se sube a mano; hoy ASSETS_V sale de la fecha de modificación.
- Sin SQL injection, sin XSS en vistas del lote (todo por e()), sin secretos.

### Comentarios que estaban mal
- config.example.php — Decía que se apunta con CRM_CONFIG del .htaccess; bootstrap lo encuentra por el nombre de carpeta. Corregido.
- helpers.php vivo_responder — Decía 304; responde 204. Corregido.
- helpers.php — Docblocks de exportar_csv y paginar estaban sobre csv_celda y filas_por_pagina. Movidos.
- datos_prueba.php:138 — Decía que borra la marca de lectura de la casilla; no la toca. Corregido.
- datos_prueba.php — «la instalación es UNA sola»: hoy son dos. Aclarado.
- bootstrap.php — «no existe crm.php» ambiguo: es crm_config/crm.php. Precisado.

---

## Lote B1 — Núcleo CRM, negocios, embudos e importación
Verificador: fallas: 0. 18 archivos: crm.php, negocios.php, importador.php; pages negocio-form, negocio-ver, negocio-etapa, negocios, embudo, embudos, importar, cuotas; views negocio-form, negocio-ver, negocios, embudo, embudos, importar, cuotas.

### Resumen
crm.php es el núcleo (no se apaga): listas de valores, normalización (provincia, país, localidad, teléfono), alta/edición de cuentas, contactos y posibles, conversión posible → cuenta+contacto+negocio, papelera y la entrada desde la web (API v1). negocios.php: embudo con etapas abierta/ganada/perdida, historial en negocio_etapas, pronóstico contra cuotas; módulo «negocios», encendible aparte en producción. importador.php: CSV de Zoho, empresas únicas por nombre, actualiza por zoho_id o correo en modo «sólo completar»; también carga correos sueltos a una lista de campaña. Pantalla Importar: núcleo, sólo admin.

### Hallazgos
- [ALTO] pages/negocio-form.php:56-58, views/negocio-form.php:71,80 — Desplegables de empresa y contacto cortados en 3.000 filas (hay ~12.000 empresas y ~13.500 contactos). Editar un negocio cuya empresa no entra en la lista la deja en «— Sin empresa —» y al guardar se pierde sin aviso. Igual con contacto, y con campañas desde 200. Sugerencia: autocompletar o incluir siempre el valor actual.
- [ALTO] pages/negocio-form.php:55, views/negocio-form.php:28 — Etapas sólo del embudo predeterminado: editar un negocio de otro embudo sin tocar nada lo muda a la primera etapa del predeterminado y abre un tramo nuevo en el historial. Sugerencia: cargar las etapas del embudo del negocio.
- [ALTO] importador.php:61-84 — No detecta ni convierte la codificación. Excel en castellano guarda en Windows-1252: no se reconocen «Correo electrónico», «Título», «Móvil», «Teléfono Directo» (contactos sin correo ni teléfono) y cada texto con tilde hace fallar el INSERT («Incorrect string value»). Sugerencia: si mb_check_encoding falla, convertir desde Windows-1252.
- [ALTO] crm.php:755 (posible_convertir), pages/posible-convertir.php:26, views/posible-convertir.php:63 — Importe como texto libre, sólo coma→punto: «1.500.000» se guarda como 1,50. Moneda sin validar: más de 3 caracteres rompe el INSERT (CHAR(3)). Sugerencia: parseo como cuotas o type=number + lista blanca ARS/USD.
- [ALTO] negocios.php:146, :192 — cerrado_en se recalcula con la hora actual en cada guardado: corregir la descripción de un negocio ganado en marzo pone hoy como fecha de cierre. Sugerencia: escribirlo sólo al pasar de abierta a cerrada.
- [ALTO] importador.php:149, :210-212 (+ contacto_guardar/_solo_completar) — En reimportación pisa el propietario de contactos existentes; si no coincide con un usuario usa quien importa: reimportar reasigna miles de contactos. Igual con cuenta_id. Sugerencia: en actualizaciones no tocar propietario ni empresa si ya tenían.
- [ALTO] importador.php:182-184 (y :156-158 en simulación) — Búsquedas por zoho_id y correo no filtran eliminado_en, y si hay zoho_id no busca por correo: contactos en papelera se «actualizan» y siguen invisibles; un contacto web con el mismo correo se duplica. Sugerencia: filtrar papelera o restaurar, y caer a correo.
- [MEDIO] negocios.php:73, :107, :207; views/negocios.php:7; views/embudo.php:12, :57; pages/cuotas.php:56 — Totales, Kanban, pronóstico y cuotas suman ARS y USD como una sola moneda, mostrado en pesos.
- [MEDIO] views/negocio-form.php:38, negocios.php:127, :188 — En edición la probabilidad viene precargada y cambiar la etapa conserva la vieja; negocio_mover siempre la pisa. El ponderado depende de por dónde se cambió.
- [MEDIO] importador.php:220 — strtotime lee «01/02/2024» como mes/día y «13/02/2024» da false: fecha de alta invertida o perdida. Sugerencia: DateTime::createFromFormat('d/m/Y H:i').
- [MEDIO] crm.php contacto_guardar/cuenta_guardar — No recortan textos al largo de columna (cargo 150, CP 30, calle 250, linkedin 255): en modo estricto la fila falla.
- [MEDIO] importador.php:111-245, pages/importar.php — Sin transacción, sin set_time_limit, import_lotes se escribe al final: si el server corta 13.000 filas por tiempo queda a medias sin rastro.
- [MEDIO] views/embudos.php:106, negocios.php:100-105 — Apagar una etapa saca sus negocios del Kanban (la pantalla dice sólo que no se ofrece en nuevos); en la ficha el selector «Mover» marca otra etapa y un clic la cambia.
- [MEDIO] pages/embudos.php:86-91 — Al borrar etapa cuenta sólo negocios vivos: con negocios en papelera el DELETE choca con la FK y muestra error crudo. Si se borra, negocio_etapas (sin FK) queda huérfano y esos tramos desaparecen del recorrido (JOIN).
- [MEDIO] crm.php:685-693 — «Ya convertido» se chequea fuera de la transacción y sin FOR UPDATE: doble clic en Convertir duplica contacto y negocio.
- [MEDIO] views/negocio-form.php:57 — Propietario sólo entre usuarios activos: editar un negocio de alguien dado de baja lo reasigna.
- [MEDIO] negocios.php:181-197 — negocio_mover sin transacción; misma etapa abre tramo nuevo; motivo de pérdida persiste al reabrir; no valida que la etapa sea del embudo ni que esté activa.
- [BAJO] pages/negocio-form.php, negocio-etapa.php — Cualquier usuario edita o mueve cualquier negocio (¿decisión? no documentada).
- [BAJO] pages/cuotas.php:24 — type=number manda «1500000.5»; al sacar puntos queda 15000005.
- [BAJO] pages/cuotas.php:12, :55; negocios.php:206 — ?periodo=2026-13 pasa el formato, strtotime da false y con strict_types date() lanza TypeError → 500.
- [BAJO] crm.php:411 — $d['empleados'] sin ?? null: aviso y 0 en vez de null.
- [BAJO] importador.php:139-141 — Correos inválidos descartados sin dejar detalle; simulación cuenta filas repetidas como altas separadas; búsqueda por correo sólo mira el principal.
- [BAJO] importador.php:166-168 — cuenta_asegurar compara nombre exacto: «ACME S.A.» y «Acme SA» son dos empresas; empresa_clave() existe pero sólo la usa la web.
- [BAJO] pages/importar.php:21 — Destino 'lista' por POST se acepta con campañas apagado; importar_lista_csv no verifica que la lista exista, no mira «No incluir en campañas», no deja fila en import_lotes.
- [BAJO] views/importar.php:63 — label «Qué importar» duplicado, el primero sin cerrar: HTML mal formado.
- [BAJO] crm.php:935-961, :1024-1028 — email_dominio_corporativo descarta dominios que empiezan con «mail.» o «me.»; sitio_web LIKE '%acme.com%' también encuentra notacme.com.
- [BAJO] crm.php:1307 — Vencimiento «próximo día hábil» de la tarea de contestar consultas web ignora feriados.
- [BAJO] pages/negocio-form.php:41 — cuenta_id/contacto_id/campana_id inexistente → FK falla, 500 sin capturar; negocio-etapa.php:16 muestra la excepción cruda.
- [BAJO] pages/embudos.php — Sin redirect tras POST (recargar reenvía; crear embudo dos veces da error de duplicado); embudo_predeterminado hace dos UPDATE sin transacción; la rama de editar embudo no tiene formulario en la vista.
- Seguridad: sin inyección SQL (lo que se pega es entero o de lista blanca); vistas escapan con e(); POST con CSRF; embudos, importar y cuotas exigen admin.

### Comentarios que estaban mal
- negocios.php:180 — Decía que negocio_mover lo usa el Kanban; lo usa el formulario «Mover de etapa» de la ficha (el Kanban no arrastra).
- pages/embudos.php:33-34 — Decía «tres etapas»; se crean cinco (tres en curso + Ganado + Perdido).
- importador.php:124 — Decía «Sin apellido no hay contacto»; se omite sólo si faltan nombre y apellido.
- Textos de pantalla engañosos (no tocados, no son comentarios): views/negocio-form.php:35 sobre la probabilidad; views/embudos.php:106 sobre apagar etapa.

---

## Lote B2 — Contactos, cuentas, posibles clientes y actividades
Verificador: fallas: 0. 28 archivos: actividad.php, actividades.php; pages contacto-form, contacto-ver, contactos, cuenta-form, cuenta-ver, cuentas, posible-form, posible-ver, posibles, posible-convertir, actividad-form, actividad-completar, actividades; las 12 vistas homónimas + _actividades_panel.

### Resumen
Base comercial: empresas (cuentas), contactos y posibles clientes, cada uno con listado (filtros, paginación, CSV, aprobación masiva), ficha y formulario. La conversión de posible crea o reutiliza empresa y contacto, con negocio opcional, en una transacción. Actividades (tareas, llamadas, reuniones) en la tabla `actividades`, creadas desde el listado o el panel de cada ficha; la agenda web también crea reuniones ahí. actividad.php es la auditoría (tabla `actividad`, singular): alimenta el CRON y el «Historial» de cada ficha. La lógica de datos vive en crm.php y actividades.php; las páginas validan y orquestan.

### Hallazgos
- [ALTO] pages/actividad-form.php:84-86, views/actividad-form.php — Selectores hasta 3000 contactos (hay >13.000), 3000 empresas y 1000 negocios: si el vínculo queda afuera muestra «Ninguno» y al guardar se borra sin aviso (también al crear desde la ficha de un contacto «Z…»). Sugerencia: incluir siempre el vinculado o autocompletar.
- [MEDIO] Permisos en todo el lote — Sólo require_login(): un comercial edita, convierte, aprueba en masa, manda a papelera y exporta a CSV toda la base (pages/contactos.php:61-70, cuentas.php, posibles.php). Como mínimo la exportación debería pedir require_admin().
- [MEDIO] crm.php:864 / pages/posible-convertir.php:38 — Doble conversión sin FOR UPDATE: doble clic duplica empresa, contacto y negocio. Sugerencia: UPDATE … WHERE convertido_en IS NULL y rowCount.
- [MEDIO] views/contacto-form.php:176 + crm.php:682 — «No incluirlo en campañas» no se puede desmarcar (checkbox desmarcado no viaja; contacto_guardar sólo toca no_email si vino). «Volver a habilitar» dice «No figuraba dado de baja» sin fila en desuscripciones aunque igual pone no_email=0. Sugerencia: hidden no_email=0 antes del checkbox.
- [MEDIO] views/contacto-form.php:101 — Selector de empresa `required`: el alta de empresa al vuelo («crear una empresa nueva») no anda desde el navegador.
- [MEDIO] actividades.php:268 — actividad_completar() al REABRIR igual deja «Tarea completada» en la línea de tiempo y actualiza ultima_actividad_en (ensucia «sin tocar hace 30 días»). Sugerencia: if ($completar).
- [MEDIO] actividades.php:209 — Editar una actividad completada pisa completada_en con la hora de edición.
- [MEDIO] Conversión (views/posible-convertir.php, crm.php:856+) — No muda etiquetas; pierde sector, calificación y empleados; al reutilizar un contacto por correo no completa datos y sólo lo vincula a la empresa si no tenía (COALESCE, crm.php:918), aunque la pantalla dice «se enlaza» y «no se pierde nada»; pisa el estado anterior con «Precalificado».
- [MEDIO] pages/posible-convertir.php:56 — «1.234,56» → «1.234.56» → se guarda 1.234. Moneda y fecha sin validar; fecha inválida rompe el INSERT y muestra el error crudo (línea 69).
- [MEDIO] views/posible-convertir.php:78, pages/posible-convertir.php:64 — «Crear negocio» marcado aunque el módulo negocios esté apagado: lo crea igual y redirige a negocio-ver (ruta bloqueada). Sin embudo predeterminado no lo crea y no avisa.
- [MEDIO] views/contactos.php:178, views/cuentas.php:144 — «Estado» y «Falta …» miran sólo pendiente_norm, no aprobado_en: tras «Aprobar las marcadas» siguen diciendo «Pendiente».
- [MEDIO] views/posible-ver.php:114-116 + «Nueva actividad» — Sin mirar módulos actividades/adjuntos: en producción llevan a rutas bloqueadas.
- [MEDIO] actividad-form con reuniones de la agenda — Editar la fecha de una reunión reservada desde la web cambia sólo `actividades`; agenda_reservas y Google quedan igual. Sugerencia: bloquear o mandar a «mover reserva».
- [MEDIO] views/cuenta-ver.php:30 — «Embudo abierto» y «Ganado histórico» suman monedas mezcladas como ARS y salen con negocios apagado.
- [BAJO] pages/contacto-form.php:65 — La empresa nueva se crea antes de validar el resto: queda creada si el formulario falla.
- [BAJO] pages/cuenta-form.php:49 — Chequeo de nombre repetido corre con nombre vacío y pisa «obligatorio»; igualdad exacta (no empresa_clave()).
- [BAJO] pages/actividad-form.php:70 — `volver` del POST sin sanear (hoy código muerto, detrás de CSRF); con BASE_PATH vacío `\evil.com` sería redirección abierta.
- [BAJO] pages/actividad-completar.php — No comprueba que exista ni que no esté en papelera.
- [BAJO] views/cuenta-ver.php:44 — «Nuevo contacto» manda ?cuenta_id= que contacto-form no lee.
- [BAJO] views/cuenta-ver.php — No muestra descripcion ni código postal.
- [BAJO] views/contactos.php:141, cuentas.php:112 — colspan="8" en tablas de 10 columnas.
- [BAJO] Formularios — Sin propietario se preselecciona el usuario actual: guardar sin tocar asigna la ficha a quien edita.
- [BAJO] Formularios — sitio_web/linkedin type="url" con ejemplo sin esquema que el navegador rechaza.
- [BAJO] Sin validación server de estado, fuente, calificación, tipo, sector ni fechas de actividad; id vinculado inexistente → 500.
- [BAJO] views/actividades.php — meet_url como href sin mirar esquema; filtros no conservan `vencidas`.
- [BAJO] Conversión con empresa vacía — contacto sin empresa pero el mensaje dice «se creó la empresa».
- [BAJO] actividad.php:218 — actividad_resumen() recorre todo el histórico en cada visita al CRON; log_error() usa md5 de la ruta completa (códigos distintos en crm/ y crm-pruebas/).
- [BAJO] DOCUMENTACION-MAESTRA.md:155 — Dice tablas tareas/llamadas/reuniones/reunion_invitados; es una sola `actividades` + actividad_invitados (§9 lo dice bien).
- Sin SQL injection ni XSS (e()/dato() fuerza https); todos los POST con CSRF.

### Comentarios que estaban mal
- actividad.php timeline() — Decía que mezcla auditoría con actividades y notas; trae sólo `actividad`. Corregido.
- pages/cuenta-ver.php:29 — Decía que lo apagado no se consulta; negocios y actividades se consultan siempre. Corregido.
- actividad.php encabezado — Canal `api` también registra la API v1 de escritura. Corregido.

---

## Lote C1 — Agenda propia de reuniones y reservas
Verificador: fallas: 0. 16 archivos: agenda.php; pages agenda, agenda-form, reservar, reservas, reserva-accion, reserva-mover; views agenda, agenda-form, agenda-error, agenda-ok, agenda-cancelar, agenda-publica, agenda-mover, reserva-mover, reservas.

### Resumen
Reemplazo de Calendly. Cada vendedor define tipos de reunión con horario laboral y zona horaria. Huecos = horario laboral − ocupado en Google Calendar (freeBusy) − ya reservado en el CRM − feriados; las coberturas por vacaciones parten el rango por anfitrión. Se reserva desde la página pública /r/<usuario>/<tipo> (reservar.php) o desde la web por la API. La reserva se guarda bajo GET_LOCK por vendedor y en una transacción (reserva, contacto, empresa, actividad); después se crea el evento con Meet y el correo con .ics, y el cron completa lo pendiente. El invitado mueve o cancela con el token del correo (/r/mover, /r/cancelar); el equipo, desde reservas, reserva-mover y reserva-accion.

### Hallazgos
- [MEDIO] agenda.php:1393 (agenda_reprogramar), :1519 (agenda_cancelar) — La reserva se lee antes del bloqueo y no se relee adentro; los UPDATE no exigen estado='confirmada'; cancelar no bloquea. Si el invitado mueve desde /r/mover mientras el equipo mueve o cancela desde el CRM, quedan dos filas confirmadas hijas de la misma, o una cancelada y la nueva viva, compartiendo gcal_event_id/ics_uid; la sobrante ocupa horario y recibe recordatorio. Sugerencia: SELECT … FOR UPDATE dentro del bloqueo, UPDATE … AND estado='confirmada' con rowCount, y bloquear también al cancelar.
- [MEDIO] agenda.php:749 (agenda_hueco_libre) — La consulta a Google espera 6 s para fallar antes de que la web corte a 10 s, pero gcal_api() puede renovar antes el token con google_post() (hasta 20 s, sin acotar). Con token vencido y Google lento, la web corta y la reserva igual queda tomada; el visitante ve error. Sugerencia: timeout también en la renovación, o renovar fuera del camino de la reserva.
- [MEDIO] agenda.php:1432 (agenda_reprogramar), :1544 (agenda_cancelar) — El evento de Google se mueve/borra antes de la transacción; si la base falla después, Google y CRM quedan desfasados sin registro. Sugerencia: base primero, Google después, registrando el error.
- [MEDIO] views/agenda-form.php:83 — Texto visible: «Las reservas se van a seguir tomando» sin Google conectado. Falso: sin Google no se ofrecen horarios ni se aceptan reservas por web ni /r/. Corregir el texto (el comentario PHP ya se corrigió).
- [BAJO] agenda.php:1138 (agenda_controlar_topes) — El tope diario cuenta filas creadas hoy web/publica en cualquier estado: cada movida del invitado (fila nueva) y las canceladas gastan tope. Sugerencia: excluir reprogramada_de no nulo.
- [BAJO] agenda.php:632, :720 — Feriados comparados con la fecha en la zona del tipo, pero son fechas argentinas (sólo afecta tipos fuera de Argentina).
- [BAJO] agenda.php:1306 (agenda_completar_pendientes) — ORDER BY id LIMIT 20: 20 reservas que fallan siempre bloquean al resto hasta pasar 24 h.
- [BAJO] agenda.php:1250 — Reserva movida cuyo evento original nunca se creó cuenta como «primera vez»: segunda alerta «X agendó una reunión».
- [BAJO] agenda.php:2030 (agenda_recordatorios) — Reunión reservada con <24 h recibe el recordatorio minutos después de la confirmación.
- [BAJO] agenda.php:1517 — Cancelar no descuenta campanas.reuniones: métricas cuentan canceladas.
- [BAJO] agenda.php:111 (agenda_tipo_por_slug) — URL usa la parte local del correo; dos usuarios con la misma parte local en dominios distintos y mismo slug → LIMIT 1 elige cualquiera. Sin índice único.
- [BAJO] pages/agenda-form.php / agenda.php:165 — Sin validación server de largo de nombre (120) ni slug (60; slugificar no recorta), formato de horas ni día 0-6: slug largo → error de base. Reemplazo de agenda_horarios (DELETE+INSERT) sin transacción: si falla queda sin horario. Si el dueño ya no está activo, el selector del admin arranca en otro y guardar cambia el dueño.
- [BAJO] pages/reservar.php:42 — Usa agenda_tipo_get(), que no mira si el dueño sigue activo: con una página abierta (token 2 h) se reserva en un tipo de alguien dado de baja.
- [BAJO] pages/reserva-accion.php:76 — flash($e->getMessage()) muestra cualquier Throwable, incluido texto técnico de PDOException; el respaldo `cuando` usa strtotime() en la zona de la instalación.
- [BAJO] views/reserva-mover.php:81, pages/reserva-accion.php — Confirmación y mensaje de éxito en la zona de la instalación; el botón en la zona del tipo: no coinciden en tipos de otra zona.
- [BAJO] pages/reservas.php:31 — Con todos=1 cualquier usuario ve reservas de todo el equipo con correo y teléfono del invitado (¿buscado?). La cadena de reprogramaciones se sigue con una consulta por salto (hasta 20 por fila).
- [BAJO] agenda.php:398 (agenda_anfitrion_id) — Con coberturas se llama por cada día del rango, releyendo ajuste, JSON y usuarios: hasta 31 veces por pedido.
- [BAJO] agenda.php:1717 (agenda_render_publica) — `conectado` mira el Google del dueño aunque atienda otro por cobertura: puede mostrar «no sincronizada» sin motivo.
- [BAJO] md/DOCUMENTACION-MAESTRA.md §10 — Dice que la reserva «es todo base de datos, <1 s»; con validar_hueco consulta Google sin caché (hasta 6 s o más).
- [BAJO] views/agenda-cancelar.php — «Te mandamos la confirmación por correo» sale aunque ya estuviera cancelada o no haya SMTP.
- [BAJO, latente] agenda_tx_abrir/agenda_bloquear — Si se llamara a agenda_reservar/reprogramar dentro de una transacción abierta, el commit quedaría después de soltar el GET_LOCK (doble reserva). Hoy ningún llamador lo hace; quedó documentado.
- Revisado sin problemas: SQL parametrizado; vistas con e(); POST internos con CSRF; públicos con token HMAC de un solo uso atado al uso; tokens de reserva de 128 bits; GET a /r/cancelar no cancela; permisos dueño/admin en agenda-form, reserva-accion, reserva-mover; zona horaria MySQL alineada con PHP; grilla igual en oferta y verificación.

### Comentarios que estaban mal
- agenda.php (agenda_avisar) — Decía que el CANCEL no se manda desde el CRM porque avisa Google; va siempre adjunto y Google borra con avisar=none. Corregido.
- agenda.php (encabezado) — «No dependemos de ningún servicio externo»: depende de Google Calendar y ArgentinaDatos. Corregido a «ningún servicio pago».
- pages/agenda-form.php:34-35 — Decía que sin Google «la reserva se toma igual»; no se ofrecen horarios. Corregido (el texto visible sigue mal, ver hallazgos).

---

## Lote C2 — API pública para la web, entradas web, feriados y Google Calendar
Verificador: fallas: 0. 21 archivos: api.php, feriados.php, gcal.php, google.php; pages/api/estado.php, api/v1/disponibilidad, entrada, reserva; pages api-keys, api-log, entradas-web, feriados, google-callback, google-desconectar, google-login; views api-keys, entradas-web, feriados, _aviso_gcal; migraciones 008 y 009. (Líneas = HEAD.)

### Resumen
La web intellihelp.tech habla con el CRM de servidor a servidor por la API v1: disponibilidad (horarios), reserva y entrada (consultas). Autenticación: clave + firma HMAC sobre hora, ruta, idempotencia, hash de IP y cuerpo; ±5 min; cada firma sirve una vez. Idempotencia en entradas_web; límites por clave y visitante en api_limites. Horarios = horario laboral − ocupado en Google Calendar (gcal.php, con permiso offline guardado en el login OAuth de google.php) − feriados (feriados.php, sincronizados con ArgentinaDatos). Pantallas de admin: claves, entradas, feriados. _aviso_gcal muestra fallas de Google. api/estado: API aparte de sólo lectura con clave, para ITHOS y Jarvis.

### Hallazgos
- [ALTO] gcal.php:83 (gcal_ocupado) — Google puede contestar 200 en freeBusy con calendars[id].errors (calendario sin permiso, backendError) y busy vacío: se toma como «nada ocupado», se cachea 60 s y se publican todos los horarios como libres; la verificación final tampoco lo detecta → reuniones encimadas. Sugerencia: si hay errors, devolver null.
- [MEDIO] gcal.php:75-81, 236-240 — Si curl no llega a Google, $codigo=0, y gcal_recordar_falla() interpreta 0 como «anduvo» y borra el aviso. Las caídas de red nunca muestran el cartel. Sugerencia: separar «borrar» del código HTTP.
- [MEDIO] gcal.php:123, views/_aviso_gcal.php — gcal_falla es un solo ajuste para toda la instalación: un éxito del vendedor B borra la falla del A. El aviso lo ve cualquiera y «Reconectar» conecta la cuenta de quien toca, no la que falló. Sugerencia: falla por usuario y nombrar al afectado.
- [MEDIO] google.php:194-215, gcal.php:21 — La renovación del token (google_post, 20 s, sin timeout de conexión) no la acota el $timeout de 6 s: la reserva puede pasar los 10 s de la web (contradice INTEGRACION-WEB §5.3). Con invalid_grant no hay pausa entre intentos y google_conectado() sigue en true.
- [MEDIO] views/api-keys.php:56 — La fórmula de firma en pantalla está desactualizada (hora\nMÉTODO\nruta\ncuerpo): faltan idempotencia y hash de IP de api_firma_canonica(). Quien integre siguiéndola recibe siempre 401 firma_invalida. Texto visible a corregir.
- [MEDIO] pages/api/v1/reserva.php:288-289 — El límite de 3/hora por visitante cuenta intentos fallidos (horario tomado, feriado, tope) y reintentos tras error: al 3.º-4.º intento 429 y el contacto se pierde. Sugerencia: contar sólo reservas que entran.
- [MEDIO] md/INTEGRACION-WEB.md §5.1 — Dice que api/estado acepta cualquier clave activa; pages/api/estado.php:17-20 rechaza claves de escritura (el código está bien, corregir el doc).
- [MEDIO] api.php:266 (api_fallo) — 42S02 y 42S22 (columna inexistente) se responden 503 migracion_pendiente sin log_error(): una columna mal escrita quedaría como «actualizándose» para siempre sin rastro. Sugerencia: registrar antes o limitar a 42S02.
- [BAJO] pages/api/v1/reserva.php:381-391 — Si el proceso muere entre el commit de agenda_reservar() y estado='ok', la entrada queda 'procesando' y los reintentos reciben 409 en_proceso para siempre (nada lo repara). Si falla esa escritura, el catch marca 'error' aunque la reunión existe y el reintento responde horario_tomado contra su propia reunión.
- [BAJO] gcal.php:204 (gcal_crear_evento) — POST no idempotente (sin id propio): reintento tras éxito parcial → dos eventos.
- [BAJO] pages/api-log.php — Cualquier usuario con sesión escribe acción y detalle libres en la auditoría (tipo acotado, no puede ser 'auth').
- [BAJO] Auditoría inundable — api/estado sin límite de tasa ni control de método; en API v1 la autenticación va antes de los límites: cada pedido rechazado escribe una línea.
- [BAJO] pages/entradas-web.php:155 — El CSV con datos personales lo baja cualquier usuario, no sólo admin (¿buscado?).
- [BAJO] pages/api/v1/entrada.php:220 — entrada_web_duplicada() consulta y luego inserta sin bloqueo: dos envíos simultáneos → dos tareas.
- [BAJO] pages/feriados.php:216-218 — feriados_asegurar() devuelve null también con excepción y la pantalla dice «ya hay una actualización en curso».
- [BAJO] pages/feriados.php:240-244 — Cargar a mano sobre uno oficial del mismo día lo vuelve manual para siempre; si el oficial trasladable se mueve quedan dos días sin turnos.
- [BAJO] migracion-008:63 — uq_ew_idem (token_id, idempotencia) no protege con token_id NULL (agenda pública). ADD COLUMN/INDEX IF NOT EXISTS sólo existe en MariaDB; en MySQL 8 falla.
- [BAJO] google-callback.php:76 — No controla los permisos concedidos: si el vendedor destilda el calendario, falla recién en la primera consulta.
- [BAJO] google.php:233 + pages/google-desconectar.php — Dos líneas de auditoría por desconexión.
- [BAJO] feriados.php — Consulta al vuelo 3 s por año × 2 años: un visitante puede esperar ~6 s cada 15 min si la fuente está caída; sumado a Google puede pasar los 10 s de la web.
- [BAJO] pages/api/v1/disponibilidad.php:82 — 120/min por clave compartido entre todos los visitantes, sin límite por visitante: un bot deja sin horarios a todos si falla la caché de la web.
- [BAJO] google.php:129-135 — Respaldo de crm_secret() adivinable sin app.secret: refresh tokens cifrados con clave derivable. Hoy el server tiene secreto.
- Bien: HMAC con hash_equals, ventana de hora, firmas de un uso, idempotencia firmada; sin cabeceras CORS (correcto server-to-server); OAuth con state de un uso, session_regenerate_id, dominio por claim hd; CSRF, escape, SQL parametrizado, CSV anti-fórmulas; feriados con timeout corto, reintento 15 min, mínimo de 10 para borrar, bloqueo con nombre; zona horaria alineada.

### Comentarios que estaban mal
- google.php:61-62 — Consentimiento «sólo cuando no lo tenemos»: google-login.php lo fuerza siempre.
- gcal.php:176 — gcal_disponible() «¿se pudo consultar?»: sólo mira que haya refresh_token.
- views/_aviso_gcal.php:160 — «se detecta por el 401 y por el texto»: sólo mira el texto.

---

## Lote D1 — Campañas de correo, plantillas y seguimiento
Verificador: fallas: 0. 22 archivos: campanas.php, bloques.php, envio.php; pages api-campana, baja, campana-accion, campana-form, campana-ver, campanas, imagenes, plantilla-form, plantilla-vista, plantillas, px, r; views baja, campana-form, campana-ver, campanas, imagenes, plantilla-form, plantillas.

### Resumen
Envío masivo de correo, hecho a partir de Pulsemail. Campaña = plantilla + lista. campana_preparar arma la cola en `envios` (una fila por destinatario, clave única campaña+correo). El cron llama a campanas_despachar, que manda de a uno con pausa al azar de 25–45 s, respetando franja horaria, tope diario y cupo de la casilla. Las plantillas se arman con bloques (bloques.php) traducidos a HTML de tablas. envio.php habla SMTP a mano. Seguimiento: píxel de apertura (px), baja pública con List-Unsubscribe de un clic (baja), y rebotes/respuestas por IMAP (imap.php). NO existe redirector de clics (r.php es la página pública de reserva de la Agenda; sin redirección abierta; sólo usa ?c= para atribuir la reunión). NO existe paso de aprobación de campañas.

### Hallazgos
- [ALTO] views/plantilla-form.php:127 (window.__editor.datos) — json_encode con JSON_UNESCAPED_SLASHES y sin JSON_HEX_TAG: un texto de bloque con `</script><script>…` cierra el script → XSS guardado. Cualquier usuario edita plantillas y corre cuando un admin la abre. Sugerencia: JSON_HEX_TAG|JSON_HEX_AMP o sacar UNESCAPED_SLASHES.
- [ALTO] campanas.php plantilla_guardar + pages/plantilla-vista.php:47 + views/campana-ver.php (`<?= $vista['html'] ?>`) — Un POST con tipo=html guarda cuerpo_html crudo (la pantalla manda visual, el server acepta html). Se sirve tal cual en el dominio del CRM (plantilla-vista por GET; campana-ver sin iframe): XSS guardado. Sugerencia: rechazar/sanear tipo=html y mostrar vistas previas en `<iframe sandbox srcdoc>`.
- [ALTO] campanas.php render_plantilla, campana_armar_mensaje — {{nombre}}, {{empresa}}, etc. entran al HTML sin escapar. Un posible cliente del formulario web con nombre `<img src=x onerror=…>` llega al correo y a la vista previa de campana-ver dentro del CRM. Sugerencia: escapar con e() al renderizar.
- [ALTO] Modo prueba — DOCUMENTACION-MAESTRA §11 regla 7 dice que en modo prueba no sale nada fuera de una lista blanca, y db.php:139 (es_testing) dice «bloquea envíos reales de campaña». Ni el despachador ni campana-accion consultan es_testing() y no existe lista blanca: en crm-pruebas los correos salen de verdad. Sugerencia: implementar la lista blanca o corregir doc y db.php.
- [MEDIO] campanas.php campanas_despachar (rescate de 'enviando' a 15 min) + envio.php — Dos caminos al duplicado: (1) SMTP acepta pero la conexión a la base se cayó antes del UPDATE 'enviado' (hosting corta a 20 s; db_reanudar() sólo tras el sleep; lecturas SMTP esperan hasta 30 s) → se rescata y reenvía; (2) se pierde el 250 del DATA → 'error' y reintento. Sugerencia: db_reanudar() antes del UPDATE y no rescatar automático lo que llegó al DATA (marcar dudoso).
- [MEDIO] campanas.php corte por presupuesto — UPDATE envios SET proximo_intento_en=NOW()+pausa WHERE estado='pendiente' pisa la espera de reintentos (5…60 min): un error temporal gasta los 20 intentos en minutos. Sugerencia: AND (proximo_intento_en IS NULL OR proximo_intento_en < NOW()+pausa).
- [MEDIO] envio.php smtp_enviar — 5xx en MAIL FROM o en el cierre del DATA se trata como temporal: el mismo mensaje rechazado se manda 20 veces y castiga reputación. Sugerencia: 5xx definitivo en todos los pasos.
- [MEDIO] campanas.php purgar_papelera + schema (envios ON DELETE CASCADE) — Al purgar una campaña a los 60 días se borran sus tokens: los enlaces de baja de correos ya entregados dicen «enlace no válido» (la baja es obligación legal). Sugerencia: no purgar envios o tabla aparte de tokens de baja.
- [MEDIO] campanas.php lista_miembro_sql/lista_miembro_join — No filtran ct.eliminado_en: contactos en papelera siguen recibiendo campañas. campana_preparar no excluye posibles con email_rebotado.
- [MEDIO] pages/campana-accion.php — Sin rol, propietario ni validación de transición: cualquiera inicia o cancela cualquier campaña; «Iniciar» funciona sobre completada/cancelada por POST directo; no llama a campana_lista_para_enviar(); smtp_configurado() mira la cuenta por defecto, no la de la campaña.
- [MEDIO] pages/api-campana.php — Consultado cada 6 s; con id devuelve toda la cola sin paginar; sin id hace 7 COUNT por campaña. Pesado en hosting compartido. Sugerencia: sólo ids visibles o cambios.
- [MEDIO] campanas.php lista_sincronizar_contacto — El marcador 'sin-correo@invalido.local' entra en uq_lm (lista_id,email): dos contactos de la misma lista sin correo → error de duplicado al guardar.
- [MEDIO] Baja y producción — Según la memoria del proyecto WordPress se queda con 4 de cada 10 pedidos a /crm: afectaría baja, px, img y el POST de un clic de Gmail. No verificable desde el código, pero es lo más grave para la baja legal.
- [BAJO] campanas.php marcar_rebote — Marca 'rebotado' todos los envíos enviado/enviando a ese correo de cualquier campaña y fecha, incluso rebote temporal.
- [BAJO] campanas.php desuscribir + pages/baja.php — Cada POST repetido suma otra vez en campanas.desuscriptos.
- [BAJO] campanas.php campanas_despachar — Tope diario y cupo de casilla mirados una vez por campaña y vuelta (se pasa por 1-2, campañas que comparten casilla lo pasan juntas); cuenta desactivada cae a la por defecto; una excepción corta sin cerrar sesiones SMTP.
- [BAJO] campanas.php campana_ritmo — Dice «tope de la casilla» pero usa limite_diario de la campaña.
- [BAJO] campanas.php variables_plantilla — {{cargo}} se ofrece en el editor pero campana_armar_mensaje no lo llena.
- [BAJO] bloques.php bloques_verificar — Busca {{nombre}} en HTML ya reemplazado: avisa siempre «no personalizado» si el asunto no tiene variables.
- [BAJO] bloques.php — WhatsApp vacío cae al de la empresa en HTML (?:) pero no en texto (??); firma sin nombre pone {{remitente}} en HTML y nada en texto; con strict_types un valor no string del JSON lanza TypeError.
- [BAJO] views/campana-ver.php — El <style> del correo insertado (.btn{width:100%!important} <620px) afecta a los botones del CRM en el celular.
- [BAJO] envio.php — Asunto en una sola palabra codificada de cualquier largo (RFC 2047: ≤75); respuesta de EHLO sin revisar.
- [BAJO] pages/imagenes.php — Borrar imagen no revisa si alguna plantilla la usa. (La subida está bien validada: getimagesize, sin SVG, nombre al azar, fuera del web root.)
- [BAJO] pages/px.php — Sin límite de pedidos; aperturas infladas por Apple MPP (documentado).
- [BAJO] views/campanas.php — «Se puede restaurar durante 60 días» fijo; el plazo real es papelera_dias.
- [BAJO] Documentación §11 — Regla 2 «un correo por campaña y por corrida» (manda varios); regla 1 25–45 s (permite desde 5 s); tabla eventos_envio (real: envio_eventos); «cron cada 5 min» (pensado para cada minuto).
- Sin inyección SQL; sin secretos en el código.

### Comentarios que estaban mal
- campanas.php constantes del cron — «con el cron cada cinco da cinco vueltas»: la corrida dura CRON_CORRIDA_SEG; con cron cada minuto las demás encuentran el turno tomado. Corregido. Mismo texto mal en app/cron.php.
- campanas.php ~670 — Docblock de campana_en_horario estaba sobre campana_ritmo. Movido.
- campanas.php ~1062 — Describía una lista blanca de testing que no existe. Reemplazado por nota.
- campanas.php ~1067 — Docblock de desuscribir estaba sobre reactivar_suscripcion. Movido.
- envio.php:21 — Docblock huérfano integrado al de smtp_cfg.
- campanas.php — «avanzan en paralelo»: se procesan en serie con un único presupuesto. Aclarado.
- db.php:139 (no tocado) — es_testing() dice que bloquea envíos y no lo hace.

---

## Lote D2 — Cuentas de correo, IMAP, conversaciones, listas, preajustes y editor
Verificador: fallas: 0. 15 archivos: imap.php, mensajes.php, cuentas_correo.php, preajustes.php; pages conversacion, cuentas-correo, lista-form, lista-ver, listas; views ídem; assets/js/editor.js.

### Resumen
La mitad del módulo Campañas que trata con casillas reales. cuentas_correo.php guarda casillas SMTP/IMAP con contraseña cifrada (AES-256-GCM con app.secret). imap.php es un cliente IMAP propio (con SNI), llamado por el cron y el despachador: recorre la INBOX desde el último UID en imap_estado, marca rebotes (pueden archivar contactos y dar de baja la dirección) y guarda respuestas en `mensajes`. mensajes.php + conversacion muestran el hilo por dirección y contestan por la misma casilla con In-Reply-To/References. Listas: por filtro del CRM o correos pegados. preajustes.php: plantillas prearmadas. editor.js: editor de bloques; la vista previa la arma el server.

### Hallazgos
- [CRÍTICO] pages/conversacion.php:13 — Llama a csrf_check(), que no existe (la del sistema es verify_csrf()). Todo POST de «Enviar respuesta» termina en error fatal: contestar desde el CRM está roto al 100 %. (Verificado: no hay definición en todo app/.) Sugerencia: verify_csrf().
- [ALTO] imap.php mime_partes — Pasa el Content-Type a minúsculas antes de sacar el boundary, que distingue mayúsculas. Con Outlook, Apple Mail, Yahoo o Thunderbird no se encuentran las partes y la respuesta se guarda vacía; como el UID avanza, se pierde para siempre. Sugerencia: boundary del encabezado original.
- [ALTO] imap.php imap_texto / mime_a_utf8 — En PHP 8 mb_convert_encoding() lanza ValueError ante un charset desconocido (unknown-8bit, iso-8859-8-i, spam); la @ no lo ataja. Corta imap_revisar antes de imap_guardar_uid: cada corrida choca con el mismo mensaje y la casilla queda trabada para siempre (sin rebotes ni respuestas). Sugerencia: try/catch y marca por mensaje o en finally.
- [MEDIO] imap.php imap_cmd + FETCH BODY.PEEK[] — `crudo` incluye la línea de cierre (`)` y `aNNNN OK`): en correos de una parte queda basura al final; en base64 decodifica bytes no UTF-8 y mime_a_utf8 reinterpreta todo como ISO-8859-1 (acentos rotos). Verificado localmente. Sugerencia: devolver sólo el literal.
- [MEDIO] imap.php imap_revisar — No mira UIDVALIDITY: si el servidor renumera la casilla, la marca queda arriba y no se lee nunca más, sin error.
- [MEDIO] imap.php extraer_destinatario_rebote — Sin Final-Recipient toma todas las direcciones del cuerpo (incluida la copia del original): cualquiera que figure en envios se marca rebotada y, si es duro, se archiva el contacto y se da de baja.
- [MEDIO] imap.php imap_revisar + mensaje_entrante — Cruce con la campaña por dirección, no por Message-ID/In-Reply-To: cualquier correo de alguien que recibió una campaña cuenta como respuesta del envío más reciente. Contradice DOCUMENTACION-MAESTRA §11 regla 5.
- [MEDIO, posible] cuentas_correo.php cuenta_correo_guardar — smtp_pass es NOT NULL sin default (migracion-002) y el INSERT del alta no la incluye: en modo estricto el alta falla (1364). db.php no fija sql_mode. Probar en crm-pruebas.
- [MEDIO] cuentas_correo.php + pages/cuentas-correo.php — remitente con clave única y baja lógica: recargar una casilla dada de baja o repetir remitente → PDOException sin atajar (500).
- [MEDIO] views/conversacion.php — iframe con sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox": comparte origen con el CRM; mensaje_html_seguro es lista negra por regex que no cubre href=javascript: sin comillas, &#106;avascript:, SVG. XSS plausible con un clic. rel="noopener" del <base> no tiene efecto. Sugerencia: quitar allow-same-origin o sanear con lista blanca.
- [MEDIO] imap.php imap_quizas_revisar, mensaje_entrante — Consultar y escribir sin atomicidad: cron y despachador (o dos cron) leen la misma casilla; uq_msg lanza PDOException y aborta la vuelta sin guardar la marca.
- [MEDIO] mensajes.php mensaje_entrante — message_id e in_reply_to VARCHAR(255) sin recortar: un encabezado largo falla en modo estricto y traba la casilla igual que el ValueError.
- [BAJO] imap.php imap_cmd — $limiteBytes no se aplica a literales (adjunto de 25 MB entero en memoria); si corta, lo no leído se mezcla con la respuesta siguiente.
- [BAJO] imap.php imap_texto — Limpieza ?=\s+=? corre después de decodificar (código muerto): asunto partido queda con un espacio.
- [BAJO] imap.php es_rebote_duro — Un «4xx » suelto en cualquier parte lo clasifica como temporal.
- [BAJO] mensajes.php mensaje_responder — Toma la casilla de la campaña sin mirar activo/eliminado_en.
- [BAJO] mensajes.php conversacion_de — ASC con LIMIT 100 oculta los últimos mensajes de hilos largos; de/para sin índice.
- [BAJO] pages/conversacion.php — No verifica que mensaje_id pertenezca al hilo; sin control de dueño.
- [BAJO] views/cuentas-correo.php — «Dueño: quién puede usarla» no se hace cumplir (campana-form ofrece todas).
- [BAJO] google.php crm_secret — Si cambia el secreto, descifrar devuelve '' sin avisar (cuentas «no configuradas»). Ajustes heredados imap_pass/smtp_pass en texto plano.
- [BAJO] pages/cuentas-correo.php — Sin redirect tras POST: refrescar reenvía y repite la prueba de red.
- [BAJO] editor.js refrescar — Respuestas de vista previa sin orden (una vieja pisa a la nueva), errores tragados; con sesión vencida se congela sin aviso; #previewFrame sin sandbox (mitigado por escape en bloques.php).
- [BAJO] views/listas.php — «60 días» fijo; el plazo real es papelera_dias.
- [BAJO] Código muerto: mensajes_sin_leer(), preajuste_get(), categoria_asegurar() (choca con UNIQUE en nombres >80), columna cuentas_correo.imap_ultimo_uid.
- Sin SQL injection; CSRF bien salvo conversacion (roto); sin redirecciones abiertas.

### Comentarios que estaban mal
- imap.php ~499-530 — Tres docblocks apilados sobre imap_quizas_revisar: dos eran de imap_revisar e imap_revisar_todas. Movidos y completados.

---

## Lote E1 — Frontend (CSS/JS) y pantalla de ajustes
Verificador: fallas: 0. 9 archivos: app.css, app.js, campos.js, idle.js, secciones.js, selects.js, vivo.js, pages/ajustes.php, views/ajustes.php. (También se comparó CSS/JS sin comentarios contra HEAD: iguales.)

### Resumen
Una sola hoja app.css para pantallas internas, login y públicas. layout.php carga siempre campos.js (teléfonos y CUIT) y selects.js (desplegable propio); con sesión suma app.js (confirmaciones, cajón lateral, filtros, «procesando», selección múltiple, alto de correos), idle.js (aviso y cierre por inactividad, latido a api-sesion), vivo.js (campana de alertas y avance de campañas) y secciones.js (repide la URL con __vivo=1; el server contesta 204 si nada cambió; reemplaza sólo las zonas data-vivo). Ajustes: sólo admin; guarda en app_settings datos generales, empresa, ritmo de campañas, papelera, clave del cron, avisos y módulos; elige casilla por defecto, prueba SMTP/IMAP y borra datos de prueba.

### Hallazgos
- [ALTO] assets/js/app.js:143 — Tras confirmar se envía con el.form.submit(), que no manda name/value del botón ni dispara submit (tampoco aparece data-cargando). Ajustes «Borrar los datos de prueba» (views/ajustes.php:163) llega con accion=guardar del hidden: guarda ajustes, dice «Ajustes guardados» y nunca borra. Feriados «Se trabaja» y «Eliminar» llegan sin accion y el switch sin default no hace nada, sin aviso. «Aprobar» en contactos/cuentas anda sólo porque es el valor por defecto. Sugerencia: el.form.requestSubmit(el).
- [ALTO] pages/ajustes.php:108 + views/ajustes.php:266 — La vista no dibuja el interruptor de 'sistema' pero el guardado pone en 0 los que no llegan: cada guardado de Ajustes apaga avisar_sistema (dejan de llegar por correo los avisos de Google caído o casilla con contraseña rechazada).
- [ALTO] assets/js/idle.js:189 — Reloj de inactividad por pestaña: una pestaña olvidada en segundo plano navega a logout a los 10 min y cierra la sesión de todas; se pierde lo que se escribía en la activa. Sugerencia: compartir la última actividad por localStorage/storage o consultar api-sesion antes de salir.
- [MEDIO] pages/ajustes.php:120 — Sin validación server: vaciar «Días en la papelera» guarda '' → campanas lo lleva a 1 día y la papelera se vacía con lo de más de un día (pérdida de datos); pausa mínima > máxima; límite diario vacío/0; cron_clave de un carácter (y app/cron.php compara sin tiempo constante); empresa_email sin formato.
- [MEDIO] views/ajustes.php:283 — Si todas las direcciones de avisar_email_a son inválidas, las alertas se marcan enviadas sin mandarse (el reparto a admins sólo ocurre con el campo vacío).
- [MEDIO] views/ajustes.php:81 — Única traba contra «reiniciar todo» es un select: cualquier admin vuelve a «Prueba» sin confirmación aparte y el registro sólo dice «Se actualizaron los ajustes».
- [MEDIO] assets/js/vivo.js:137 — api-alertas (15 s) y api-campana (6 s) sin __vivo: bootstrap las cuenta como actividad y la sesión nunca vence en el server con la pestaña visible.
- [MEDIO] assets/js/app.js:108 — En la ventana de confirmación Enter confirma con el foco en «Cancelar» (incluso borrados); no encierra ni devuelve el foco.
- [MEDIO] assets/js/selects.js:98 — Enter/Espacio abre y cierra en el mismo evento; con teclado sólo abren las flechas (que saltan una opción); sin rótulo del label ni aria-activedescendant.
- [MEDIO] bootstrap.php:101 — ASSETS_V sólo mira app.css, app.js, editor.js, idle.js, vivo.js: cambios en campos.js, selects.js o secciones.js no renuevan la caché.
- [BAJO] app.js:192 — Bloque de inactividad duplicado y viejo; manda a login (no cierra sesión).
- [BAJO] idle.js:49 — Con idle_ttl=0 el server no corta pero el navegador sí a 10 min; :203 motivo siempre 'inactividad' aunque venza el tope absoluto.
- [BAJO] selects.js:255 — Escape no devuelve el foco al botón; disabled copiado una sola vez.
- [BAJO] vivo.js:174 — campana-ver no tiene data-vivo-campanas: pintarFicha/pintarCola nunca corren (secciones.js la refresca igual).
- [BAJO] vivo.js:164 — Tres errores seguidos (incluido 440) cortan la campana para siempre.
- [BAJO] vivo.js:230, :102 — innerHTML del badge y href desde el server (hoy valores fijos).
- [BAJO] views/ajustes.php:230 — Sin predeterminada, al guardar queda la primera casilla; ofrece inactivas; dos UPDATE sin transacción (ajustes.php:131).
- [BAJO] campos.js:122 — Sector guardado fuera de la lista desaparece y hay que elegir otro.
- [BAJO] secciones.js:136 — Dos zonas data-vivo="" se pisarían.
- [BAJO] app.css:154 — Halo de foco del color del fondo; --placeholder con contraste ~2,6:1.
- [BAJO] app.css — .gsearch repetido (338, 347), .brand-logo repetido (262, 279), livePulse con verde viejo (609), body.publica con degradado (contra la regla de colores planos), encabezado «Barra de progreso de campaña» sin reglas.
- [BAJO] views/ajustes.php — Ayuda de WhatsApp pide sin «+» pero se acepta.

### Comentarios que estaban mal
- secciones.js:16 — Decía 304; es 204. Corregido.
- vivo.js:8 — «las tres pantallas»: en la ficha no corre. Corrección agregada debajo.
- app.css «Trabajo en curso» — «violeta sobre el verde del acento»: el acento es lila #9f88b1. Corrección agregada debajo.
- helpers.php:350 (no tocado) — Dice que la huella la lee vivo.js; la lee secciones.js.

---

## Lote E2 — Base de datos, tareas programadas, migraciones y despliegue
Verificador: fallas: 0. 13 archivos: app/db/schema.sql, migraciones 002–007, app/cron.php, app/migrar.php, app/reinicio.php, bin/deploy.py, bin/unificar_listas.py, test/verificar_unificacion.py. (Control extra: el schema y las migraciones pasados por el separador de sentencias de migrar.php dan las mismas sentencias antes y después.)

### Resumen
schema.sql crea las tablas; las migraciones las amplían y se aplican por instalación con `deploy.py migrar` (corre migrar.php con la config de esa instalación). cron.php corre cada pocos minutos: en vueltas de ~55 s lee las casillas IMAP y despacha campañas; luego completa reservas, manda recordatorios, sincroniza feriados y envía alertas; una vez por día purga la papelera (el único borrado físico) y anonimiza las entradas web. reinicio.php vacía y vuelve a sembrar la instalación de pruebas. deploy.py sube código, config, esquema y tests por SSH a crm/ o crm-pruebas/. unificar_listas.py y su verificador: herramientas locales de una sola vez para la migración de contactos desde Zoho.

### Hallazgos
- [ALTO] app/cron.php:61 (HEAD) — GET_LOCK('crm_cron', 0) con nombre fijo; los bloqueos con nombre valen para todo el servidor, y producción y pruebas lo comparten (agenda.php y feriados.php ya anteponen DATABASE()). Con ambos cron a la misma hora, la segunda instalación saltea la corrida entera (campañas, IMAP, recordatorios, purga) en silencio (salida a /dev/null). Sugerencia: GET_LOCK(CONCAT(DATABASE(), ':cron'), 0). (Verificado.)
- [ALTO] bin/deploy.py:166 (cmd_config) — No respeta --pruebas: siempre escribe crm_config/config.php, la config de producción. `deploy.py config --pruebas` con un config local de pruebas pisa la de producción. La config de pruebas (crm_config/crm-pruebas.php) no se puede subir con el script. (Verificado.)
- [ALTO] app/reinicio.php:42 (HEAD) — Única barrera: setting_get('app_entorno','testing') !== 'testing'. Si falta la fila el default es 'testing'; schema.sql siembra 'testing' en toda instalación nueva; sin confirmación; no mira carpeta ni nombre de base; bootstrap.php cae a crm_config/config.php (producción) si falta crm-pruebas.php. Escenario: falta crm-pruebas.php o producción nunca se pasó a 'produccion' → `php crm-pruebas/reinicio.php --hacerlo` borra contactos, empresas, negocios, campañas, tokens de API y conexiones Google de la base real. Sugerencia: exigir carpeta crm-pruebas o base *_pruebas; fila ausente = producción. (Verificado.)
- [ALTO] bin/deploy.py:98 — Sin --pruebas el destino es producción sin confirmación: un typo (--prueba) despliega a producción. Sugerencia: --produccion explícito o confirmación.
- [MEDIO] app/db/schema.sql — No coincide con una instalación migrada: no incluye 002–007 (cuentas_correo, categorias, imagenes, notificaciones, mensajes; plantillas.bloques/preajuste, campanas/envios.cuenta_correo_id, pendiente_norm/aprobado_*), pero sí 008 y 009. Una instalación sólo con schema.sql (INSTALL.md §2, deploy.py schema) falla en campañas, contactos y alertas. INSTALL.md dice 32 tablas; crea 35.
- [MEDIO] Migraciones 005, 006, 007 — ADD COLUMN/INDEX IF NOT EXISTS sólo existe en MariaDB; el schema dice MySQL 8.0+.
- [MEDIO] bin/deploy.py:256-261 (schema) — Contraseña en la línea de comandos (-p'{pw}'): visible en hosting compartido y una comilla rompe el comando (inyección de shell). CRM_DB_NAME/USER del entorno mandan sobre --pruebas. Reimportar sobre una base en uso pisa nombre del embudo 1 y probabilidades (ON DUPLICATE KEY UPDATE).
- [MEDIO] app/cron.php:333, :361 — Marca del día (papelera_ultima_purga, entradas_web_ultima_limpieza) escrita antes de trabajar: si falla no se reintenta hasta mañana y nadie se entera. En pasos 3b–6 el catch no llama a db_reanudar(): con la conexión muerta se pierde también el registro del error.
- [MEDIO] bin/unificar_listas.py:556 — Al fusionar dos filas de Zoho se conserva sólo el zoho_id de la primera; el de la segunda no aparece en ninguna salida y pierde el enlace a notas/negocios al reimportar.
- [MEDIO] Índices faltantes — envios.cuenta_correo_id sin índice y cuentas_correo.php:54 filtra con DATE(e.enviado_en)=CURDATE() (recorrido completo de envios por casilla en cada carga). Sin índice listas.categoria_id, campanas.cuenta_correo_id/categoria_id.
- [BAJO] app/reinicio.php:201 — Sólo borra de Google los eventos de agenda_reservas (no actividades.gcal_event_id) y siempre en `primary`.
- [BAJO] app/reinicio.php:223 — Vacía tablas sin transacción ni chequear existencia; si falta una se corta después de borrar eventos de Google; adjuntos se vacía pero los archivos quedan en disco.
- [BAJO] app/reinicio.php:376 — Gmail personal y teléfonos del responsable escritos en el código, y el repo es público.
- [BAJO] bin/deploy.py:136 — AutoAddPolicy: no verifica la clave del host.
- [BAJO] bin/deploy.py:108 — EXCLUIR filtra sólo «config.php» exacto: un config.local.php se sube al webroot.
- [BAJO] bin/deploy.py app — No borra en el server lo eliminado del repo (páginas viejas accesibles); durante la subida mezcla versiones.
- [BAJO] app/cron.php:66 — Clave del cron en query string (logs) y comparada con !== en vez de hash_equals.
- [BAJO] cron — Marca de «un día» con date() (zona PHP) vs plazos con NOW() (zona MariaDB).
- [BAJO] cron — Turno vence a 600 s; una corrida lenta puede solaparse (envíos protegidos por UPDATE condicionado).
- [BAJO] schema.sql — uq_ew_idem no protege con token_id NULL.
- [BAJO] migracion-004 — mensajes.cuenta_correo_id BIGINT vs cuentas_correo.id INT; uq_msg con message_id NULL permite duplicados.
- [BAJO] FKs y columnas — 002–004 sin FKs (la purga deja huérfanos en mensajes, etiqueta_registro, notas, envios, lista_miembros); cuentas_correo.imap_ultimo_uid sin uso; actividades.recordar_min/recordado_en se cargan pero nadie manda ese aviso.
- [BAJO] migracion-002 — uq_cuenta_remitente + borrado lógico: no se puede recrear una casilla eliminada.
- [BAJO] migracion-007 — ix_cont_aprob/ix_cta_aprob vuelven redundantes ix_cont_pend/ix_cta_pend.
- [BAJO] bin/unificar_listas.py — Codificación detectada con los primeros 16 K (resto con errors=replace → «�»); casillas_compartidas compara sólo contra la primera ficha; dup_zoho y conteos cuentan coincidencias, no fichas; «argentina» como sufijo une «Telecom Argentina» y «Telecom».
- [BAJO] test/verificar_unificacion.py:130 — Asume separador «,»: con «;» el conjunto queda vacío y «no se perdió nada» pasa sin controlar; piso >=13000 fijo; repite reglas en vez de importarlas.
- [BAJO] bin/deploy.py — Ayuda del comando app dice que sube cron.php; sube también reinicio.php y migrar.php.
- Sin inyección SQL explotable; sin secretos en el código.

### Comentarios que estaban mal
- app/cron.php encabezado — Orden de pasos invertido y desactualizado. Corregido (casilla → campañas → 3 reservas, 3b recordatorios, 3c feriados, 4 alertas, 5 papelera, 6 entradas web).
- app/cron.php — «la corrida ARRANCÓ» estaba en otro bloque. Movido.
- app/reinicio.php — «tres de rebote»: son seis. Corregido.
- app/reinicio.php — «dueño = primer admin activo»: primero busca bsierra@. Corregido.
- app/db/schema.sql:11 — «nada se borra físico»: la purga sí borra. Corregido.
- app/db/schema.sql — «Tokens de la API de lectura»: también de escritura. Corregido.
- bin/unificar_listas.py y test/verificar_unificacion.py — Docstrings inexactos aclarados con # (docstrings intactos).

---

## Lote F — Pruebas automáticas
Verificador: fallas: 0 · Suite: 418/418 · ui_test 331/331. 8 archivos: test/run_tests.php, ui_test.php, api_test.php, render_test.php, vivo_test.php, web_contrato_test.php, smoke.sh, render.sh.

### Resumen
Seis baterías. run_tests.php: lógica pura sobre SQLite en memoria, sin base real. ui_test.php: análisis textual de CSS/JS/vistas/módulos, sin base ni red. web_contrato_test.php: compara implementaciones web↔CRM localmente; necesita el repo hermano NEW-PAGE-ITH (no está en esta máquina). smoke.sh: HTTP contra el sitio. api_test.php: API v1 de punta a punta SÓLO contra crm-pruebas (escribe en base, crea eventos Google, manda mails). render_test.php + render.sh y vivo_test.php: en el servidor contra una instalación real. deploy.py test corre run_tests, ui_test, smoke y (en pruebas) api_test; render, vivo y contrato son manuales.

### Hallazgos
- [ALTO] test/render_test.php:26 y test/render.sh:14 — Por defecto corren contra PRODUCCIÓN (public_html/crm). Crean el admin render.test@intellihelp.tech y renderizan pantallas con efectos (feriados llama feriados_asegurar(), que sale a la red y escribe la tabla). Incluyen app/pages/*.php directo, salteando permisos y módulos de index.php. Si render.sh se corta antes de --limpiar, el admin queda en producción. Choca con "producción no se toca". Sugerencia: default a crm-pruebas o exigir la ruta.
- [ALTO] test/vivo_test.php:23,137 — Default también PRODUCCIÓN. Crea admin vivo.test, renombra el primer contacto real a «Zzz…» y lo restaura al final, sin try/finally: si falla en el medio quedan el admin con sesión y el contacto real renombrado. Sugerencia: default a pruebas + limpieza en finally.
- [MEDIO] test/api_test.php:~475 — $codigoSalida se fija al final del try, antes de la limpieza; si la comprobación «se borró todo» falla sin excepción, suma a $mal pero sale con 0, y deploy.py lo da por bueno con claves de API huérfanas. Sugerencia: fin($mal ? 1 : $codigoSalida).
- [MEDIO] test/ui_test.php:910, 1194, 1251, 1254 (original) — Comprobaciones de orden con strpos(A) < strpos(B): si A no está, false < número es true y pasa. Borrar agenda_bloquear( de agenda_reservar, feriados_del_rango(, agenda_feriado_de_inicio( o imap_quizas_revisar del cron no se detecta. Sugerencia: exigir strpos !== false.
- [MEDIO] test/web_contrato_test.php:104 — El token «alterado» reemplaza el último carácter por '0'; 1 de cada 16 ya terminaba en 0 → falla espuria. run_tests.php:667 lo hace bien alternando f/e.
- [MEDIO] test/run_tests.php:791 — Sin pdo_sqlite se saltean ~100 pruebas de base (idempotencia, límites, anonimización, feriados, reservas en feriado) y la corrida sale en verde. También se saltea si q() ya existe, con un aviso que miente. Si el PHP del hosting no trae pdo_sqlite, deploy.py nunca corre esa parte. Sugerencia: fallar o exigirlo con variable.
- [MEDIO] test/api_test.php:392 — «sin posibles clientes ni negocios» no mira posibles; cuenta negocios creados por cualquiera en 5 min: no prueba lo que dice y es frágil con uso concurrente.
- [MEDIO] test/api_test.php:299 — «contacto nuevo entra Sitio web y fuera de campañas» sólo corre si el contacto es nuevo; el correo de prueba ya existe, casi nunca corre y no avisa.
- [BAJO] test/api_test.php:253-255 — «domingo a las 3: 409» acepta cualquier 409 sin mirar el código (pasaría con horario_tomado o feriado).
- [BAJO] test/api_test.php:394-398 — El tope de 5 consultas/hora/IP está hardcodeado en la secuencia; si cambia en entrada.php falla sin explicación.
- [BAJO] test/ui_test.php:111 — «on/off usan el toggle propio» pasa con un solo class="track" en cualquier vista.
- [BAJO] test/ui_test.php:121-128, 160-165 — $conDisplay sólo para el mensaje; «Coherencia visual» recorre vistas sin comprobar nada; $hex sin usar. Código muerto.
- [BAJO] test/ui_test.php:1233, 1265 — Chequeos de migraciones 008/009 se saltean o pasan solos si faltan los archivos (en el server probablemente nunca corren).
- [BAJO] test/run_tests.php:68 — ok() acepta 2 parámetros; llamadas en 196, 202, 278, 412 pasan un detalle 3º que se descarta en silencio.
- [BAJO] test/run_tests.php:260-265 — La variante "java\tscript:" se verifica con /javascript/: prueba débil (el código hoy está bien).
- [BAJO] test/vivo_test.php:176-181 — Cuenta filas ver.contactos de todos los usuarios: falla si alguien usa Contactos a la vez.
- [BAJO] test/smoke.sh:65 — El chequeo de subdominio va siempre a crm.intellihelp.tech aunque BASE sea pruebas. Puede fallar intermitente por WordPress robando pedidos a /crm.
- [BAJO] bin/deploy.py:240 — Ambas instalaciones suben test/ a ~/crm_test: dos deploy.py test simultáneos se pisan.

### Partes SIN pruebas (funciones llamadas de verdad por run_tests)
- Login y permisos: auth.php 0/13 (provision_user, require_admin, roles); google_decode_id_token y dominio permitido sin prueba; CSRF sin prueba; modulos.php 0/4 (modulo_activo, ruta_habilitada).
- mensajes.php 0/7, incluido mensaje_html_seguro() (sanea HTML de correos entrantes → XSS) sin un solo caso.
- Subida de archivos: adjunto_guardar/adjunto_borrar sin pruebas de tipo, tamaño ni ruta (registros.php 2/15).
- Campañas: campanas.php 1/36 (campana_preparar, despacho, retome de trabados, baja/reactivación, lista_miembro_sql, cron_tomar_turno). envio.php 0/9 (MIME, cabeceras, In-Reply-To).
- IMAP: mime_partes, mime_a_utf8, mensaje_partir_cita sin casos de lógica.
- Negocios/embudo 0/12 (pronostico). Importador Zoho 0/5 salvo normalizar_*. CRUD crm.php sin prueba: contacto_guardar, cuenta_asegurar, _solo_completar, aprobar_registros, contacto_falta.
- Borrado de datos: datos_prueba_limpiar, purga de papelera del cron, reinicio.php: sólo búsqueda de textos.
- gcal.php 0/12, notificaciones 1/6, cuentas_correo 0/11, cron.php sin prueba de comportamiento.

### Comentarios que estaban mal
- test/run_tests.php:7-8 — Decía «Corre SIN base de datos ni red»: usa SQLite en memoria. Corregido.
- test/run_tests.php:17 — Al comando le faltaba -d extension_dir (en Windows no carga extensiones). Agregado.

---


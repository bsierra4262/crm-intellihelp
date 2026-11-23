# Changelog

Todos los cambios notables de este proyecto se documentan acá.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/)
y el versionado, [SemVer](https://semver.org/lang/es/).

## 2026-11-23 · Cierre de la auditoría

Lo que había quedado pendiente en [md/AUDITORIA.md](md/AUDITORIA.md).

- **Aviso «X minutos antes» de las actividades:** el campo del formulario de tareas, llamadas y
  reuniones ahora hace algo. El cron deja una alerta personal al dueño a la hora del aviso y le
  llega por correo (tipo nuevo «Avisos de mis actividades», con su interruptor en Ajustes). Si se
  cambia la fecha o los minutos, el aviso se vuelve a armar; lo que pasó hace más de una hora no
  se avisa tarde.
- **La purga de la papelera ya no deja nada colgado:** las notas y etiquetas de un registro
  borrado definitivamente se van con él, y sus adjuntos pasan a la papelera (el archivo se borra
  al cumplir el plazo). Migración **019**: claves foráneas en `envios`, `lista_miembros` y
  `mensajes` (el envío y la conversación quedan como historial; el miembro de una lista sale con
  su contacto), idempotencia de la agenda pública protegida (`token_clave`), fuera la columna sin
  uso `imap_ultimo_uid` y fuera las contraseñas viejas en texto plano (`smtp_pass`/`imap_pass` de
  los ajustes) cuando hay una casilla activa, que es la que se usa. Revisado antes en las dos
  bases: no había ninguna referencia rota que limpiar ni ningún contacto que rescatar.
- Comprobado por HTTP que WordPress ya no se queda con pedidos a `/crm` (el `.htaccess` raíz del
  9/11 lo resolvió): 72 de 72 pedidos llegan al CRM.

## 2026-11-22 · Arreglos de la auditoría

Arreglos de los hallazgos de [md/AUDITORIA.md](md/AUDITORIA.md), en diez lotes que se juntaron
en la rama `fix/auditoria`. Lo que no se arregló, y por qué, está al principio de ese documento.
Aplicado en crm-pruebas (código y migraciones 010–018); **producción todavía no**. El contrato
con la web no cambió.

### Lo que cambia para quien usa el CRM

- **Contestar un correo desde la conversación vuelve a andar** (antes fallaba siempre).
- **«Borrar los datos de prueba»**, y «Se trabaja» / «Eliminar» en Feriados, hacen lo que dicen.
- **Desplegables grandes con buscador:** en empresa, contacto, negocio y campaña se escriben dos
  letras o más y aparecen los que coinciden. Editar ya no borra un vínculo que no entraba en la
  lista.
- **Permisos:** exportar a CSV (contactos, empresas, posibles clientes, entradas de la web) es
  sólo de administradores; mandar a la papelera, del propietario o un administrador (sin
  propietario, cualquiera); iniciar o cancelar una campaña, de su creador, su responsable o un
  administrador. Los botones que no corresponden no se muestran.
- **Adjuntos:** los borra su autor o un administrador, y van a la papelera (solapa «Archivos»).
- **Montos en ARS y USD por separado** en negocios, Kanban, cuotas, pronóstico, reportes y la
  ficha de la empresa. No hay conversión.
- **Alertas:** las del equipo se marcan leídas por persona; las personales llegan por correo a
  su dueño.
- **Sesión:** una pestaña olvidada ya no cierra la sesión de las demás; una pestaña visible sin
  uso se cierra a los 10 minutos (la campana ya no la mantiene viva). Si el formulario vence,
  la pantalla muestra lo escrito.
- **Ajustes** valida lo que se carga (papelera de 7 a 365 días, clave del cron de 24 caracteres
  o más, correos, teléfonos) y, con un error, no guarda nada. Volver a «Prueba» pide una
  confirmación aparte.
- **Modo prueba:** las campañas sólo mandan a los usuarios del CRM y a la lista blanca
  (`testing_lista_blanca`).
- **Campañas:** los contactos en la papelera ya no reciben correos; un rechazo 5xx no se
  reintenta; aparecen envíos «fallido» y «dudoso» para revisar a mano; una casilla desactivada
  deja la campaña quieta en vez de mandar por otra.
- **Reuniones de la agenda:** la fecha se cambia con «Mover la reunión», no desde el formulario de
  la actividad. Una reunión reservada con menos de 24 h recibe el recordatorio 12 h después de
  reservar (o ninguno). Las campañas descuentan las reuniones canceladas.
- **Negocios:** al cambiar de etapa la probabilidad pasa a ser la de la etapa; sólo se mueve a
  etapas activas del mismo embudo; la fecha de cierre se conserva.
- **Conversión de posibles clientes:** lleva etiquetas, sector y empleados, no pisa datos y no
  pone «Precalificado»; los importes se leen en formato argentino.
- **Importador:** acepta los CSV de Excel en castellano; al reimportar no reasigna propietarios ni
  empresas y omite lo que está en la papelera.
- Un comercial ve la falla de Google de su propia agenda; las ajenas sólo un administrador.

### Correo e IMAP

- `conversacion.php` usa `verify_csrf()`; responder sale por la casilla correcta con
  `In-Reply-To` y queda guardado.
- IMAP: la conversión de caracteres nunca lanza, la marca de UID avanza por mensaje, boundary del
  encabezado original (Outlook, Apple Mail), literal separado del cierre del FETCH, `UIDVALIDITY`,
  turno atómico, `INSERT IGNORE`, cabeceras recortadas.
- Respuestas cruzadas por `In-Reply-To` / `References` (respaldo por dirección con envíos de 45
  días); rebotes por el `Message-ID` del envío y marcando sólo el envío más reciente.
- HTML entrante saneado con lista blanca sobre `DOMDocument` (requiere la extensión `dom`) y
  mostrado en un `iframe` sin `allow-same-origin`.
- Casillas: el alta guarda `smtp_pass`; una dada de baja se puede volver a cargar.
- Campañas: XSS de plantillas cerrado (JSON escapado, sólo plantillas visuales, variables
  escapadas, vistas previas en `iframe sandbox`); marca «Entregando al servidor…» y estado dudoso
  contra los duplicados; 5xx definitivos; el corte por presupuesto respeta las esperas; topes
  antes de cada correo; `bajas_tokens` mantiene vivos los enlaces de baja después de la purga;
  IP como hash en baja y píxel; `api-campana` liviano.

### Agenda, Google y API

- Mover y cancelar: bloqueo, `FOR UPDATE` y `estado = 'confirmada'`; base primero, Google
  después, con `gcal_pendiente` y reintento desde el cron.
- `freeBusy` con error ya no publica todo libre; falla de Google por vendedor; renovación del
  token dentro de los 6 s de la reserva y pausa de 15 minutos tras `invalid_grant`; evento con id
  propio.
- Feriados comparados por fecha argentina; consulta al vuelo de 2 s; un feriado manual no pisa
  uno oficial.
- API v1: cupo por visitante que cuenta sólo reservas que entran, tope de 20 intentos por hora
  (`429 demasiados_pedidos`), reparación de entradas trabadas (120 s), `codigo` en
  `503 migracion_pendiente`. `api/estado`: sólo `GET`, 60 por minuto por IP, claves de lectura o
  root. Auditoría que no se puede inundar.

### Núcleo, seguridad y frontend

- Logout por POST con CSRF (GET sólo desde el mismo sitio); `440` en JSON para `api-*`, `__vivo` y
  XHR sin sesión; pantalla `419` que conserva lo escrito.
- Último administrador protegido; `.htaccess` niega respaldos y archivos sueltos; adjuntos en
  disco sólo con extensiones de documento; `crm_config` deducido (`CRM_CONFIG_DIR`); secreto al
  azar en `STORAGE/.secreto-app` si falta `app.secret`.
- Confirmaciones con `requestSubmit`; ventana y desplegables accesibles; `ASSETS_V` cubre todos los
  CSS y JS; refresco con `__vivo=1`.

### Base, cron, despliegue y pruebas

- `schema.sql` completo: 42 tablas, todas las migraciones hasta la 018. Instalación nueva = sólo
  `schema.sql`.
- Migraciones nuevas:
  - **010** `notificacion_lecturas` (alertas leídas por persona).
  - **013** `agenda_reservas.gcal_pendiente` + índice.
  - **015** `bajas_tokens`; `ip` a `VARCHAR(64)` en `envio_eventos` y `desuscripciones`; ajuste
    `testing_lista_blanca`.
  - **016** `imap_estado.uidvalidity`; índices `ix_msg_de` / `ix_msg_para`; `smtp_pass` con
    `DEFAULT ''`.
  - **017** `avisar_sistema` vuelve a `1`; alertas de sistema viejas marcadas como avisadas.
  - **018** índices de `envios`, `listas` y `campanas`; fuera `ix_cont_pend` / `ix_cta_pend`;
    `mensajes.cuenta_correo_id` a `INT UNSIGNED`; `remitente_vivo` + `uq_cuenta_remitente_viva`.
- Cron: bloqueo con el nombre de la base, marca diaria después del éxito (reintento cada hora),
  cabecera `X-Cron-Clave` con `hash_equals`, reintentos de Google.
- `deploy.py`: destino obligatorio (`--pruebas` | `--produccion`), `config --pruebas` sube
  `crm-pruebas.php`, `schema` sólo sobre base vacía con `CRM_DB_PASS_<DESTINO>`, verificación de
  la clave del servidor (`--aceptar-clave-nueva`), `app --limpiar`.
- `reinicio.php`: carpeta `crm-pruebas` con su config o base `*_pruebas`, `app_entorno` ausente =
  producción, confirmación con el nombre de la base (`--confirmar=<base>`), borrado en
  transacción, datos personales en la sección `'pruebas'` de la config.
- Pruebas: `render` y `vivo` contra pruebas por defecto (producción con `--produccion`);
  `run_tests.php` exige `pdo_sqlite` (o `CRM_TEST_SIN_BASE=1`) y carga `test/casos/` y
  `test/casos_db/`. Resultados: `run_tests.php` 1187/1187 (1183/1183 en el servidor, PHP 8.3),
  `ui_test.php` 334/334, contrato con la web 24/24, `smoke.sh` 71/71, render 61/61,
  `api_test.php` 61/62 en pruebas (falta una casilla de correo cargada en crm-pruebas),
  herramientas Python 21/21.

## [Unreleased]

### Added

- **Feriados automáticos en la agenda** (migración 009, tabla `feriados`). Una vez por día, y al
  vuelo si faltan datos o tienen más de 24 h, el CRM trae el calendario oficial de feriados
  nacionales del año y del siguiente (ArgentinaDatos): agrega los que se decretan, reubica los
  trasladables, quita los que dejan de figurar y respeta los cargados a mano y los marcados
  «se trabaja». Si la fuente no responde, la agenda sigue con lo que tenía y se vuelve a probar
  a los 15 minutos; la consulta al vuelo espera 3 segundos como mucho.
- En un feriado nacional o de la jurisdicción configurada (`feriados_jurisdiccion`, CABA por
  defecto) **no hay turnos**: ni en la web, ni en el enlace público, ni al mover una reunión, ni
  en los días de una cobertura. Reservar o mover a un feriado se rechaza por cualquier camino
  con un mensaje que nombra el feriado; la API responde `409 feriado` con su fecha y nombre.
- `GET api/v1/disponibilidad` devuelve `feriados` (fecha, nombre, tipo, nacional, jurisdicción)
  del rango pedido, para que la web los muestre marcados.
- **Pantalla Más → Feriados** (administración): la lista del año, «Actualizar ahora», alta
  manual con jurisdicción (regionales), «Se trabaja» / «Volver a feriado», la jurisdicción y el
  interruptor general. Avisa qué reuniones confirmadas caen en un feriado.
- **Alerta al responsable** cuando un feriado nuevo cae sobre una reunión ya agendada, para que
  coordine con el invitado (la reunión no se cancela sola).
- «Próximos feriados» en Agenda y la marca «Feriado» en las reuniones reservadas.

- **Cobertura de la agenda por vacaciones** (ajuste `agenda_coberturas`). Entre dos fechas,
  otra persona atiende un tipo de reunión: los horarios salen de su calendario y la reunión,
  el evento con Meet y la confirmación quedan a su nombre. La web no cambia nada y al terminar
  el período vuelve sola al dueño. La confirmación, el recordatorio, el cambio de horario y la
  cancelación usan siempre a quien tiene la reserva, no al dueño del tipo.

- **API v1 para la web** (`api/v1/disponibilidad`, `api/v1/reserva`, `api/v1/entrada`). La
  landing `/diagnostico/` muestra los horarios libres reales del vendedor y reserva en la
  agenda del CRM; el formulario de contacto deja la consulta en el CRM además del correo. Cada
  pedido va firmado con HMAC-SHA256 (hora, método, ruta con la carpeta de la instalación y
  cuerpo), con ±5 minutos de tolerancia, clave de idempotencia y límites por clave y por hash
  de IP. Siempre responde JSON; los errores inesperados, con su código `ERR-XXXXXX`.
- **Permiso «Escritura (web)»** en la pantalla API: genera la clave y un secreto que se
  muestra una sola vez y se guarda cifrado.
- **Entradas desde la web.** Tabla `entradas_web` con la página, el canal, las UTM, `gclid`,
  `li_fat_id`, referrer, landing y consentimiento de cada consulta y reserva. Pantalla nueva con
  listado, filtros, exportación y tarjetas por canal (consultas, reuniones agendadas,
  realizadas y canceladas). Ahí se elige quién atiende las consultas.
- **Consulta sin reunión**: contacto y empresa sin pisar datos, nota con el mensaje, tarea
  «Responder consulta web» con vencimiento el próximo día hábil a las 12 y alerta nueva
  «Consultas desde la web». Sin posibles clientes ni negocios.
- **Cambio de horario desde el correo** (`/r/mover`): el invitado elige otro hueco y la
  invitación nueva reemplaza a la anterior en su calendario.
- **Consentimiento** (Ley 25.326) en la agenda pública y en la API: fecha, versión y huella
  del texto. Los contactos nuevos que llegan por la web quedan fuera de las campañas salvo que
  pidan novedades.
- **`migrar.php` y `deploy.py migrar`**: aplican una migración con la configuración de la
  propia instalación, sin credenciales de base. Migración 008.
- Tipo de reunión «Reunión de diagnóstico» (`diagnostico`) en los datos de prueba, que es el
  que usa la landing.
- Pruebas: firma (válida, vencida, alterada, de otra instalación), validación de cada campo,
  idempotencia, consultas duplicadas, límites, ventana y huecos de la agenda, token del
  formulario e invitación `.ics` en `run_tests.php` (con una SQLite en memoria para lo que usa
  base); la API v1 y el campo trampa en `smoke.sh`; reglas nuevas en `ui_test.php`; y
  `api_test.php`, que prueba la API de punta a punta contra pruebas y borra lo que creó.
- **Editor visual de campañas.** El correo se arma con bloques (encabezado, portada,
  título, párrafo, lista, imagen, botón, recuadro destacado, datos de contacto, firma,
  separador, espacio), se reordenan arrastrando y cada uno tiene sus colores, alineación y
  tamaños. **La vista previa la renderiza el servidor**, con el mismo motor que usa el envío
  real: lo que se ve es exactamente lo que llega. Se puede ver en escritorio y en celular.
- **El HTML generado respeta los límites reales del correo**, que no son los de una página web:
  maquetado con tablas (Outlook usa el motor de Word e ignora flexbox, grid y `border-radius`),
  CSS en línea (Gmail sanea las hojas de estilo), botones con respaldo VML, fuentes con
  respaldo a Arial, y sin imágenes de fondo, JavaScript, formularios ni video.
- **Control de entregabilidad en vivo.** Antes de mandar avisa —y explica el porqué— si el
  correo pasa los 102 KB en que Gmail lo recorta, si tiene demasiada imagen para el texto,
  si hay exceso de enlaces, si el asunto está en mayúsculas, si usa palabras que disparan los
  filtros, si faltan textos alternativos o si no está personalizado.
- **Cuatro plantillas prearmadas**: la campaña de Pulsemail reproducida bloque por bloque,
  más una sobria de sólo texto, una de novedad con imagen y una de invitación a reunión.
- **Varias cuentas de correo.** Se pueden correr campañas en paralelo, cada una desde su
  casilla, con su propio tope diario y su propia reputación: si una campaña quema una cuenta,
  las demás siguen limpias. El despachador abre una sesión SMTP por cuenta y las reusa.
- **Segmentos (categorías) de listas y campañas**: RRHH, energía, bancos, laboratorios,
  tecnología, industria, control de accesos.
- **Biblioteca de imágenes** con control de peso: avisa cuando una imagen pasa de 100 KB,
  y se sirven por URL en vez de incrustarlas, para no engordar el correo.
- **Limpieza automática de la base por rebote.** Cuando una dirección rebota de forma
  **definitiva** (estado DSN `5.x.x`, código SMTP `5xx`, «user unknown», «domain not found»…)
  el contacto se **archiva solo** y se da de baja de las campañas. Los rebotes **pasajeros**
  (buzón lleno, greylisting, `4.x.x`) sólo se marcan: no archivan a nadie. Ante la duda se
  trata como pasajero. Sólo se archiva si el correo rebotado era el único del contacto.
- **Papelera con plazo de 60 días.** Lo archivado se puede restaurar con un clic; la pantalla
  muestra por qué llegó ahí (lo borró una persona o rebotó el correo) y cuántos días quedan.
  Pasado el plazo, el cron lo elimina definitivamente: es el único lugar del sistema que borra
  de verdad. El plazo se configura en Ajustes.
- **Unificación de las listas de campaña** (`bin/unificar_listas.py`): funde las 15 listas
  sueltas de LinkedIn y outreach con la base de Zoho en un solo archivo limpio. Deduplica todo
  junto —Zoho incluido, que también traía duplicados propios— con fusión transitiva por correo,
  perfil de LinkedIn y nombre + empresa; detecta las casillas compartidas (`info@`, `ventas@`)
  para no convertir a varias personas en un solo contacto; ordena los correos por calidad de
  verificación y corrige la geografía. Sobre el material real: **32.221 filas → 29.338
  contactos únicos**, 2.883 duplicados fusionados, cero direcciones repetidas.
- **`test/verificar_unificacion.py`**: 14 aserciones sobre la base unificada.
- **Cierre automático de sesión**, con el mismo criterio que la aplicación de tickets:
  10 minutos de inactividad y 8 horas de sesión como máximo. Quien corta de verdad es el
  servidor —destruye la sesión vencida en la request siguiente, colabore o no el navegador—,
  pero ahora además hay **aviso con cuenta regresiva** un minuto antes, para que la sesión no
  se caiga sin explicación mientras alguien está mirando la pantalla. Mientras se trabaja se
  manda un latido (`api-sesion`, como mucho uno por minuto) para que no venza en las pantallas
  que no recargan, como el editor de plantillas. El aviso usa el modal de la aplicación, y el
  foco arranca en «Seguir conectado» para que un Enter distraído no cierre la sesión. El login
  explica por qué se cerró, y la auditoría distingue el cierre manual del automático.
- **Tests**: 99 pruebas unitarias sin base ni red, 49 comprobaciones HTTP de rutas, permisos y
  assets, 32 pantallas ejecutadas de verdad con una sesión de administrador, y 14 aserciones
  sobre la base de contactos unificada.

### Changed

- **Una sola dirección.** `crm.intellihelp.tech` ahora redirige con un **301** a
  `intellihelp.tech/crm`, que pasa a ser la URL canónica de la aplicación y de todos los
  enlaces públicos (agenda, baja de suscripción, callback de Google).
- **El enlace de agenda que se inserta en las campañas lleva el id de la campaña**, para poder
  medir cuántas reuniones trajo cada envío.
- **Las vistas pasan a usar el sistema de diseño** en las 39 pantallas. Había 163 estilos en
  línea en el marcado, y el mismo párrafo debajo del título de una tarjeta tenía seis márgenes
  distintos (`-.5rem 0 1rem`, `-.3rem 0 .8rem`, `-.4rem 0 .8rem`…). Ahora esas piezas tienen
  clase propia (`.card-intro`, `.col-stack`, `.row-sep`, `.act-row`, `.correo-vista`,
  `.pub-pad`…) y el espaciado sale de la escala `--sp-*`. Quedan 17 estilos en línea: anchos de
  barras de avance y colores que elige el usuario. El test los distingue.
- **El par etiqueta/valor de las fichas vive en un solo lugar** (`dato()` en `helpers.php`).
  Estaba copiado como función anónima dentro de tres vistas y cada copia había quedado un poco
  distinta, así que el mismo correo se veía de una forma en la ficha de un contacto y de otra
  en la de una empresa.
- **Los estados vacíos dicen qué hacer.** «Todavía no hay campañas» no ayuda a nadie: ahora
  explica que se arma con una plantilla, una lista y la campaña que junta las dos, con los
  enlaces. Lo mismo en claves de API, reservas y actividades.
- **Los campos dentro del encabezado de una pantalla** usan el estilo de campo de la aplicación.
  El selector de mes de Reportes estaba estilado a mano porque la regla base no lo alcanzaba,
  así que era el único campo del sistema que se veía distinto.

### Fixed

- El título de los correos de la agenda y de las alertas salía **sin tamaño de letra**: el
  bloque sin `nivel` leía la clave igual y dejaba dos avisos de PHP en la auditoría por cada
  correo.
- La reserva aceptaba **cualquier** inicio que llegara por POST (un domingo a las 3, o encima de
  una reunión de Google). Ahora tiene que ser un hueco que la agenda ofrece y que siga libre.
- **Doble reserva**: entre comprobar el horario y guardarlo no había bloqueo. Ahora se bloquea
  el calendario del vendedor (`GET_LOCK` con el nombre de la base).
- Los horarios del **último día** se ofrecían sin mirar Google: la consulta terminaba al
  comienzo de ese día.
- **Dos invitaciones** por reunión (la de Google y la del CRM, con identificadores distintos).
  Google ya no avisa; la del CRM usa el `iCalUID` del evento. Al cambiar el horario la
  invitación conserva el identificador y sube la secuencia, así no queda la reunión vieja en
  el calendario del cliente.
- El evento de Google llevaba **el comentario del cliente** en la descripción.
- La reunión no guardaba **la empresa**, y el contacto se buscaba sólo por el primer correo.
- El enlace de cancelar de un correo viejo dejaba de servir después de un cambio de horario.
- `/r/` seguía respondiendo con el módulo Agenda apagado.
- La invitación mostraba «\n» como texto en la descripción.
- Las direcciones de dominios reservados para ejemplos (`example.com`, `.test`…) se tomaban
  como corporativas: en pruebas, cada consulta inventada quedaba colgada de la empresa de otro
  contacto con ese dominio.
- `deploy.py migrar` aplicaba la migración pero no dejaba la marca `migracion_<nnn>`: subía
  el archivo con un nombre fijo y `migrar.php` saca el número del nombre.
- `api_test.php` dejaba en la campana la alerta de la consulta de prueba cuando el correo ya
  era de un contacto existente.
- **Los correos largos se salían de la tarjeta en el celular.** Las celdas de tabla no tenían
  regla de corte, y una dirección no tiene espacios donde partir. Medido sobre la base real:
  **3.577 de 28.112 direcciones (el 12,7 %) no entran en una pantalla de 320 px**, y la más
  larga se pasaba por 126 px. Va `overflow-wrap:anywhere` y no `break-word` porque la celda es
  flex: sólo `anywhere` reduce el ancho mínimo del elemento, que es lo que desborda.
- **Los botones de acción no se podían tocar con el dedo.** Medían 29 px, pensados para el
  mouse; en el celular pasan a 40 px sin cambiar de aspecto.
- La pantalla **CRON** no abría: el listado hace `JOIN` con `usuarios`, que también tiene
  `creado_en`, y las columnas del filtro iban sin calificar, así que MySQL rechazaba la consulta
  por ambigüedad. Ahora todas van con el alias de la tabla, con test de regresión.
- El cron no arrancaba en el servidor: buscaba el núcleo en la ruta del repositorio y al
  desplegar la estructura cambia. Ahora prueba las dos ubicaciones.
- Las tarjetas del panel mostraban el rótulo, el número y el pie **en una sola línea**:
  `.stat-link{display:block}` pisaba el `flex-direction:column` de `.stat`.
- Los botones **Filtrar** y **Limpiar** salían gigantes en las pantallas donde el filtro tenía
  un toggle de dos líneas: `.filter-actions` era flex sin `align-items`, así que los botones se
  estiraban a la altura de la fila.
- Las etiquetas de los toggles se mostraban en mayúsculas porque la regla de versalitas usaba el
  selector `.filters label span`, que también alcanzaba al texto del switch.
- La barra de navegación **no entraba ni en 1920 px** y la última solapa quedaba cortada al
  medio. Ahora la barra lleva sólo el trabajo diario, lo administrativo va a un menú «Más», y
  hay tres escalones responsive (achica el buscador → oculta los rótulos → drawer).
- En el embudo la última columna quedaba cortada: las columnas tenían ancho fijo. Ahora se
  reparten el ancho disponible con un mínimo, y sólo scrollean cuando de verdad no entran.
- El unificador de listas **no deduplicaba la base de Zoho contra sí misma**: sólo limpiaba las
  listas nuevas y las cruzaba contra Zoho, así que los duplicados internos de Zoho pasaban
  enteros. Ahora todo se deduplica en una sola pasada. (179 duplicados internos.)
- Los nombres mal escritos impedían fusionar («Chris B.» y «Chris Bevacqua» con el mismo correo
  quedaban como dos contactos). La comparación ahora tolera abreviaturas y tratamientos, sin
  dejar de distinguir a dos personas distintas.
- La dirección se armaba mezclando el bloque de la persona con el de la empresa, y salían
  combinaciones imposibles como «New York, Argentina». Se toma un bloque completo, y si el país
  y la provincia se contradicen manda la provincia. (4.960 → 0 casos.)

### Security

- Agenda pública con **campo trampa, token de formulario firmado** (mínimo 3 s, máximo 2 h) y
  **límite de envíos** por hash de IP; cancelar y mover piden el token.
- La auditoría guarda la IP de los visitantes sin sesión **como hash**, no en claro.
- `deploy.py schema --pruebas` importaba en **la base de producción**: el nombre salía de una
  variable con producción como valor por defecto. Ahora sale del destino elegido.
- La API v1 no abre sesión: la autentica la firma de cada pedido.
- La firma de la API v1 cubre también la clave de idempotencia y el hash de IP del visitante,
  y cada escritura firmada se acepta una sola vez (`401 firma_repetida`).
- `api/estado` sólo acepta claves de lectura: la de la web (escritura) ya no lee los contadores.
- Los límites de la agenda pública y la auditoría usan la IP que vio el servidor
  (`ip_confiable()`), no `X-Forwarded-For` ni `Client-IP`, que se podían inventar; en IPv6 se
  cuenta por red /64. Los tokens de formulario de `/r/` sirven una sola vez.
- Topes que no dependen de la IP: dos reuniones futuras por correo y 15 reservas por día y por
  vendedor desde afuera, con alerta al llegar (`agenda_max_por_correo`, `agenda_tope_diario`).
- El correo de confirmación ya no repite el comentario del visitante ni convierte en enlace lo
  que escribió; nombre y empresa no aceptan direcciones web. La invitación `.ics` no lleva el
  comentario y pone el nombre entre comillas en `CN`.
- La exportación CSV neutraliza las celdas que una planilla tomaría como fórmula.
- Caché de 60 s de la consulta a Google para mostrar horarios, límite a las páginas públicas y
  verificación final de la reserva con 6 s como máximo.
- Nombre de hasta 120 caracteres en la agenda y la API (lo que entra en la ficha del contacto).
- El editor de correos no rechazaba los enlaces `javascript:`, `data:` ni `vbscript:`: les
  pegaba un `https://` adelante en vez de descartarlos.

## [1.0.0] - 2026-07-30

### Added

- **Núcleo CRM**: Contactos, Empresas (cuentas) y Posibles clientes, con búsqueda global,
  filtros, orden, paginación, exportación a CSV y ficha con listas relacionadas, notas,
  actividades e historial.
- **Conversión de posible cliente** a Empresa + Contacto (+ Negocio opcional), reutilizando
  la empresa y el contacto si ya existen y arrastrando notas, adjuntos y actividades.
- **Embudo de ventas**: negocios con etapas, probabilidad, ingreso ponderado calculado en la
  base, vista **Kanban**, historial de tiempo por etapa y motivo de pérdida.
- **Pronóstico**: objetivo (cuota) vs. ganado vs. embudo ponderado por vendedor y período.
- **Actividades** unificadas (tareas, llamadas y reuniones) con vencimientos, vistas rápidas
  (hoy / semana / vencidas / completadas), prioridad, resultado de llamada y completado en
  un clic desde cualquier ficha.
- **Campañas de correo** — el motor de Pulsemail llevado al servidor: plantillas HTML con
  variables por destinatario, listas de destinatarios cargables desde el filtro del CRM,
  **cola persistente** y despacho por cron con **pausa aleatoria de 25–45 s**, ventana horaria,
  límite diario, reintentos con espera creciente y vista previa real del correo.
- **Rebotes y respuestas por IMAP**: detección por remitente (`mailer-daemon`, `postmaster`,
  `mail-daemon`) y por asunto, extracción del destinatario que falló desde el DSN, marcado en
  todo el CRM y seguimiento por UID para no reescanear la casilla.
- **Desuscripción**: enlace propio en cada correo, cabecera `List-Unsubscribe` con soporte de
  un clic, lista negra global respetada al armar la cola y en el momento del envío.
- **Agenda propia** en reemplazo de Calendly: tipos de reunión con horario laboral,
  disponibilidad calculada contra la **ocupación real de Google Calendar** (`freeBusy`),
  página pública de reserva, creación del evento con **Google Meet automático**, confirmación
  y recordatorio por correo, y cancelación por enlace.
- **Login 100 % Google Workspace** con restricción de dominio, aprovisionamiento automático y
  cierre de sesión por inactividad (10 min) y sesión máxima (8 h) impuesto por el servidor.
- **Auditoría (CRON)**: cada acción, navegación, acceso y error —de servidor y de navegador—
  queda registrado, con código estable `ERR-XXXXXX` por archivo:línea.
- **Importador del CSV de Zoho**: crea las empresas únicas y las enlaza, normaliza provincia,
  país y localidad, descarta la basura de los campos mal cargados, unifica `Fax` con
  `Conmutador` y permite **simular** antes de escribir. Reimportar actualiza, no duplica.
- **Papelera** con restauración: ningún registro se borra de forma permanente.
- **API de sólo lectura** con tokens por permiso, para integrar el CRM con ITHOS/Jarvis.
- **Reportes**: embudo por etapa, origen de los negocios ganados, actividades por vendedor y
  rendimiento de las campañas (tasa de respuesta y de rebote).
- **Documentación**: [mapa 1:1 de Zoho CRM](md/ZOHO-CRM-REFERENCIA.md) con marcas de uso real
  contra la base exportada de Zoho, documento maestro y requerimientos permanentes.

### Security

- El código de la aplicación no se sirve por HTTP (`app/` bloqueado por `.htaccess`).
- El `refresh_token` de Google se guarda cifrado con AES-256-GCM.
- CSRF en todo formulario; cookies `HttpOnly` + `SameSite=Lax` + `Secure`.
- **Modo prueba**: ninguna campaña puede alcanzar a un destinatario real.

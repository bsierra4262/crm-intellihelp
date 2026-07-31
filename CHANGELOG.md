# Changelog

Todos los cambios notables de este proyecto se documentan acá.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/)
y el versionado, [SemVer](https://semver.org/lang/es/).

## [Unreleased]

### Added

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

### Added

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

### Changed

- **Una sola dirección.** `crm.intellihelp.tech` ahora redirige con un **301** a
  `intellihelp.tech/crm`, que pasa a ser la URL canónica de la aplicación y de todos los
  enlaces públicos (agenda, baja de suscripción, callback de Google).
- **El enlace de agenda que se inserta en las campañas lleva el id de la campaña**, para poder
  medir cuántas reuniones trajo cada envío.

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
  contra la base exportada del cliente, documento maestro y requerimientos permanentes.
- **Tests**: 99 pruebas unitarias sin base ni red, 49 comprobaciones HTTP de rutas, permisos y
  assets, 32 pantallas ejecutadas de verdad con una sesión de administrador, y 14 aserciones
  sobre la base de contactos unificada.

### Fixed

- **BUG #6** — Las tarjetas del panel mostraban el rótulo, el número y el pie **en una sola
  línea**: `.stat-link{display:block}` pisaba el `flex-direction:column` de `.stat`.
- **BUG #7** — Los botones **Filtrar** y **Limpiar** salían gigantes en las pantallas donde
  el filtro tenía un toggle de dos líneas: `.filter-actions` era flex sin `align-items`, así
  que los botones se estiraban a la altura de la fila.
- **BUG #8** — Las etiquetas de los toggles salían GRITADAS EN MAYÚSCULAS porque la regla de
  versalitas usaba el selector `.filters label span`, que también alcanzaba al texto del switch.
- **BUG #9** — La barra de navegación **no entraba ni en 1920 px** y la última solapa quedaba
  cortada al medio. Ahora la barra lleva sólo el trabajo diario, lo administrativo va a un menú
  «Más», y hay tres escalones responsive (achica el buscador → oculta los rótulos → drawer).
- **BUG #10** — En el embudo la última columna quedaba cortada: las columnas tenían ancho fijo.
  Ahora se reparten el ancho disponible con un mínimo, y sólo scrollean cuando de verdad no entran.
- **BUG #11** *(seguridad)* — El editor de correos no rechazaba los enlaces `javascript:`,
  `data:` ni `vbscript:`: les pegaba un `https://` adelante en vez de descartarlos.
- **BUG #3** — El unificador de listas **no deduplicaba la base de Zoho contra sí misma**:
  sólo limpiaba las listas nuevas y las cruzaba contra Zoho, así que los duplicados internos de
  Zoho pasaban enteros. Ahora todo se deduplica en una sola pasada. (179 duplicados internos.)
- **BUG #4** — Los nombres mal escritos impedían fusionar («Chris B.» y «Chris Bevacqua»
  con el mismo correo quedaban como dos contactos). La comparación ahora tolera abreviaturas
  y tratamientos, sin dejar de distinguir a dos personas distintas.
- **BUG #5** — La dirección se armaba mezclando el bloque de la persona con el de la empresa,
  y salían combinaciones imposibles como «New York, Argentina». Se toma un bloque completo, y
  si el país y la provincia se contradicen manda la provincia. (4.960 → 0 casos.)

- **BUG #1** — La pantalla **CRON** no abría: el listado hace `JOIN` con `usuarios`, que también
  tiene `creado_en`, y las columnas del filtro iban sin calificar, así que MySQL rechazaba la
  consulta por ambigüedad. Ahora todas van con el alias de la tabla, con test de regresión.
- **BUG #2** — El cron no arrancaba en el servidor: buscaba el núcleo en la ruta del
  repositorio y al desplegar la estructura cambia. Ahora prueba las dos ubicaciones.

### Security

- El código de la aplicación no se sirve por HTTP (`app/` bloqueado por `.htaccess`).
- El `refresh_token` de Google se guarda cifrado con AES-256-GCM.
- CSRF en todo formulario; cookies `HttpOnly` + `SameSite=Lax` + `Secure`.
- **Modo prueba**: ninguna campaña puede alcanzar a un destinatario real.

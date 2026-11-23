# DOCUMENTACIÓN MAESTRA · CRM INTELLIHELP

> **Fuente única de verdad del proyecto.** Documenta absolutamente todo lo construido y las
> decisiones tomadas. Se actualiza con **cada** cambio (ver [Changelog](#15-changelog)).
>
> Documentos complementarios:
> [ZOHO-CRM-REFERENCIA.md](ZOHO-CRM-REFERENCIA.md) · [REQUERIMIENTOS.md](REQUERIMIENTOS.md) ·
> [MIGRACION-ZOHO.md](MIGRACION-ZOHO.md) · [CAMPANAS.md](CAMPANAS.md) · [AGENDA.md](AGENDA.md) ·
> [INTEGRACION-WEB.md](INTEGRACION-WEB.md)

---

## Índice

1. [Qué es](#1-qué-es)
2. [Arquitectura y stack](#2-arquitectura-y-stack)
3. [Estructura de archivos](#3-estructura-de-archivos)
4. [Base de datos](#4-base-de-datos)
5. [Acceso y sesión](#5-acceso-y-sesión)
6. [Auditoría · CRON](#6-auditoría--cron)
7. [Núcleo CRM](#7-núcleo-crm)
8. [Negocios y embudo](#8-negocios-y-embudo)
9. [Actividades](#9-actividades)
10. [Agenda propia de reuniones](#10-agenda-propia-de-reuniones)
    · [Reservas y consultas desde la web](#101-reservas-y-consultas-desde-la-web)
    · [Feriados](#102-feriados)
11. [Campañas de correo](#11-campañas-de-correo)
12. [Diseño e interfaz](#12-diseño-e-interfaz)
13. [Despliegue](#13-despliegue)
14. [Reglas del proyecto](#14-reglas-del-proyecto)
15. [Changelog](#15-changelog)

---

## 1. Qué es

CRM web interno de **INTELLIHELP S.A.** que reemplaza a **Zoho CRM** y absorbe el motor de
campañas de **Pulsemail** (la app de escritorio de correo masivo). Un solo lugar para:

- La base comercial (empresas, contactos, posibles clientes).
- El embudo de ventas (negocios, etapas, pronóstico).
- El seguimiento (tareas, llamadas, reuniones).
- Las campañas de correo en frío, con rebotes, respuestas y desuscripción.
- La **agenda propia de reuniones** — reemplaza a Calendly: crea el evento en Google Calendar
  y genera el **Google Meet** solo, sin depender de servicios externos.

**En vivo:** `https://intellihelp.tech/crm` — **una sola dirección**. El subdominio
`crm.intellihelp.tech` existe porque el hosting lo necesita para servir la carpeta, pero
redirige acá con un 301.
**Uso:** herramienta interna. Acceso exclusivo con cuenta **@intellihelp.tech**.

---

## 2. Arquitectura y stack

Misma filosofía que [APP Tickets](../../APP%20Tickets) y [APP Remitos](../../APP%20Remitos):
PHP sin framework, front-controller propio, MySQL, JavaScript sin build. Nada que no se pueda
desplegar en un hosting compartido.

| Capa | Tecnología |
|---|---|
| Backend | **PHP 8.3** (Hostinger), front-controller propio, sin framework ni Composer. Extensiones: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `dom` |
| Base de datos | **MariaDB 11.8** (`uXXXXXXXXX_crm`), utf8mb4. El esquema pide MariaDB 10.5+ |
| Front-end | HTML + CSS con variables + **JavaScript vanilla**. Fuente **Saira** |
| Autenticación | **OAuth 2.0 Google Workspace** (dominio `intellihelp.tech`) |
| Correo saliente | **SMTP de Gmail/Workspace** con contraseña de aplicación |
| Correo entrante | **IMAP de Gmail** (rebotes, respuestas, Calendly propio) |
| Calendario | **Google Calendar API** (`freeBusy` + `events.insert` con `conferenceData`) |
| Trabajo en segundo plano | **Cron de Hostinger** (hPanel) → `app/cron.php` |
| Zona horaria | **America/Argentina/Buenos_Aires (UTC−3)** en PHP y MySQL |
| Hosting | Hostinger · SSH `IP_DEL_SERVIDOR:65002` · webroot `public_html/crm` |

**Decisiones de arquitectura y por qué:**

1. **Sin framework.** Es lo que ya usamos y lo que el hosting soporta sin fricción. El
   front-controller de APP Tickets ya está probado en producción; se reusa tal cual.
2. **El motor de campañas corre en el servidor, no en el navegador.** Pulsemail hoy depende de
   que la PC esté prendida. En web eso no sirve: la campaña se **encola** y un **cron** la va
   despachando con la misma pausa aleatoria anti-spam (25–45 s) y los mismos reintentos.
   La campaña sigue aunque cierres el navegador.
3. **Una sola dirección: `intellihelp.tech/crm`.** El subdominio no se puede eliminar (el
   hosting lo usa para mapear la carpeta), pero el `.htaccess` lo redirige con un 301 para que
   no queden dos URLs circulando ni dos sesiones distintas. Además, `BASE_PATH` se deduce de
   `SCRIPT_NAME` en tiempo de ejecución, así que la app funciona igual si mañana cambia la
   ruta o se sirve desde otro lugar.
4. **Aislamiento del WordPress del raíz.** `public_html` tiene WordPress + Wordfence. El
   `.htaccess` de la app desactiva el WAF (`auto_prepend_file none`) y define su propio
   `RewriteEngine`, que no hereda las reglas del padre. Es exactamente lo que ya funciona en
   `/comprobantes` y `/remitos`. Falta la otra mitad, que no es del código: el `.htaccess`
   **raíz** del servidor todavía no excluye `/crm`, y una parte de los pedidos a la app (baja,
   píxel, imágenes) termina en el WordPress. Se arregla en el servidor (ver
   [AUDITORIA.md](AUDITORIA.md)).
5. **Nada se borra de golpe.** Todo borrado es lógico (`eliminado_en`) en las tablas de
   negocio, también el de los **adjuntos**. Lo eliminado vive en la papelera **60 días** por
   defecto (ajuste `papelera_dias`, de 7 a 365) —restaurable por un administrador— y recién
   después el cron lo borra de verdad (en los adjuntos, también el archivo del disco). El plazo
   existe porque la limpieza ahora también es automática: un rebote definitivo archiva el
   contacto sin intervención humana, y hace falta una ventana para revertirlo si el sistema se
   equivocó.

---

## 3. Estructura de archivos

Sólo la carpeta **`app/`** se sube a Hostinger. El resto es interno del repositorio.

```
CMR/
├── app/                          ← lo único que se publica
│   ├── web/                      document root (public_html/crm)
│   │   ├── index.php             front controller
│   │   ├── .htaccess             rutas + aislamiento del WordPress raíz
│   │   ├── app/                  dominio de la aplicación
│   │   │   ├── bootstrap.php     config, errores, sesión, TTL
│   │   │   ├── db.php            PDO singleton
│   │   │   ├── helpers.php       url, csrf, flash, render, formato, sumar_por_moneda()
│   │   │   ├── auth.php          sesión y usuarios
│   │   │   ├── registros.php     notas, etiquetas y adjuntos; puede_mandar_papelera()
│   │   │   ├── notificaciones.php alertas en pantalla y por correo (lecturas por persona)
│   │   │   ├── ajustes_validar.php reglas de la pantalla Ajustes (funciones puras)
│   │   │   ├── google.php        OAuth 2.0
│   │   │   ├── gcal.php          Google Calendar + Meet
│   │   │   ├── actividad.php     auditoría (CRON)
│   │   │   ├── crm.php           cuentas, contactos, posibles clientes, entradas de la web
│   │   │   ├── api.php           API v1 de la web: firma, límites, validación, idempotencia
│   │   │   ├── negocios.php      embudo
│   │   │   ├── actividades.php   tareas, llamadas, reuniones
│   │   │   ├── campanas.php      campañas, plantillas, listas
│   │   │   ├── envio.php         motor SMTP (cola, reintentos, pausa)
│   │   │   ├── imap.php          rebotes y respuestas (cliente IMAP propio)
│   │   │   ├── mensajes.php      conversaciones y saneado del HTML entrante
│   │   │   ├── agenda.php        huecos, reservas, cambio de horario, invitación .ics
│   │   │   ├── feriados.php      feriados: sincronización con el calendario oficial y consultas de la agenda
│   │   │   ├── importador.php    CSV de Zoho
│   │   │   ├── icons.php         iconos SVG en línea
│   │   │   ├── pages/            un controlador por ruta (incl. api/ y api/v1/)
│   │   │   └── views/            plantillas (layout + una por pantalla)
│   │   └── assets/               css, js (incl. buscador.js, idle.js, vivo.js), img
│   ├── db/                       schema.sql y migraciones (migracion-NNN-….sql, hasta la 019)
│   ├── cron.php                  tareas periódicas (CLI y cron-URL)
│   ├── migrar.php                aplica una migración con la config de SU instalación (sólo CLI)
│   ├── reinicio.php              vacía la instalación de pruebas y siembra los datos de prueba (sólo CLI)
│   └── server-config/            config.example.php (los reales —config.php, crm-pruebas.php— no se versionan)
├── bin/                          setup.php, deploy.py, utilidades
├── test/                         batería de regresión (run_tests.php carga test/casos/ y test/casos_db/)
├── md/                           esta documentación
├── docs/                         banner y capturas
└── Documentacion a consultar/    material de referencia (CSV de Zoho, etc.)
```

**Cache-busting:** los assets se referencian con `?v=<ASSETS_V>`. `ASSETS_V` (bootstrap.php) es
la fecha de modificación más reciente de **todos** los `assets/css/*.css` y `assets/js/*.js`,
así que cambia sola al desplegar un cambio en cualquiera de ellos; no hay número que subir a mano.

---

## 4. Base de datos

Esquema completo en [`app/db/schema.sql`](../app/db/schema.sql): crea **42 tablas** e incluye
todas las migraciones hasta la 019. Una instalación nueva se arma **sólo con `schema.sql`**; las
migraciones son para las instalaciones que ya existían. Grupos de tablas:

| Grupo | Tablas |
|---|---|
| Plataforma | `usuarios`, `app_settings`, `actividad` (auditoría), `api_tokens`, `notificaciones`, `notificacion_lecturas` (quién leyó cada alerta del equipo; migración 010) |
| CRM | `cuentas`, `contactos`, `posibles_clientes`, `etiquetas`, `etiqueta_registro`, `notas`, `adjuntos` |
| Embudo | `embudos`, `etapas`, `negocios`, `negocio_etapas` (historial), `negocio_contactos`, `cuotas` |
| Actividades | `actividades` (tareas, llamadas y reuniones en una sola tabla), `actividad_invitados` |
| Agenda | `agenda_tipos`, `agenda_horarios`, `agenda_reservas`, `feriados` (los días sin turnos; migración 009), `google_tokens` |
| Entradas desde la web | `entradas_web` (cada consulta y reserva que llega de intellihelp.tech, con página, canal y consentimiento), `api_limites` (contadores de los límites de envíos) |
| Campañas | `campanas`, `plantillas`, `listas`, `lista_miembros`, `categorias`, `imagenes`, `envios`, `envio_eventos`, `desuscripciones`, `bajas_tokens` (tokens de baja que sobreviven a la purga; migración 015) |
| Correo | `cuentas_correo`, `imap_estado`, `mensajes` |
| Migración | `import_lotes` |

Reglas transversales de todas las tablas de negocio: `id` BIGINT autoincremental,
`creado_por` / `creado_en` / `actualizado_en`, `eliminado_en` (borrado lógico) y
`zoho_id` en las que vienen migradas, para poder reimportar sin duplicar.

**Migraciones.** Se aplican por instalación con `deploy.py migrar` (ver [§13](#13-despliegue)).
Todas son idempotentes. `schema.sql` ya trae todas: en una instalación nueva no se corren.

| N.º | Archivo | Qué hace |
|---|---|---|
| 002 | `migracion-002-campanas.sql` | Varias cuentas de correo, categorías de listas, plantillas con editor por bloques |
| 003 | `migracion-003-notificaciones.sql` | Alertas del sistema (`notificaciones`) |
| 004 | `migracion-004-mensajes.sql` | Conversaciones: correo entrante y saliente guardado (`mensajes`) |
| 005 | `migracion-005-firma.sql` | Firma de cada casilla |
| 006 | `migracion-006-normalizacion.sql` | Qué ficha está completa (`pendiente_norm`) |
| 007 | `migracion-007-aprobacion.sql` | Aprobación de fichas (`aprobado_en` / `aprobado_por`) |
| 008 | `migracion-008-entradas-web.sql` | `entradas_web` y `api_limites` (API v1 de la web) |
| 009 | `migracion-009-feriados.sql` | `feriados` |
| 010 | `migracion-010-lecturas-alertas.sql` | `notificacion_lecturas`: las alertas del equipo se leen por persona. Marca como leídas por todos las que ya estaban leídas |
| 013 | `migracion-013-agenda-google-pendiente.sql` | `agenda_reservas.gcal_pendiente` + índice: cambios de Google pendientes de reintento |
| 015 | `migracion-015-campanas-bajas.sql` | `bajas_tokens`; `envio_eventos.ip` y `desuscripciones.ip` pasan a `VARCHAR(64)` (hash); ajuste `testing_lista_blanca` |
| 016 | `migracion-016-correo-entrante.sql` | `imap_estado.uidvalidity`; índices `ix_msg_de` / `ix_msg_para` en `mensajes`; `cuentas_correo.smtp_pass` con `DEFAULT ''` |
| 017 | `migracion-017-avisar-sistema.sql` | Vuelve a encender `avisar_sistema` y marca como avisadas las alertas de sistema de más de un día sin mandar (no borra nada) |
| 018 | `migracion-018-indices-casillas.sql` | Índices de `envios`, `listas` y `campanas`; saca `ix_cont_pend` / `ix_cta_pend` (redundantes); `mensajes.cuenta_correo_id` a `INT UNSIGNED`; columna calculada `remitente_vivo` con `UNIQUE` (una casilla dada de baja se puede volver a cargar). Requiere 002–007 |
| 019 | `migracion-019-integridad.sql` | Claves foráneas de `envios`, `lista_miembros` y `mensajes` hacia contactos, posibles clientes, envíos y campañas (antes pone en NULL las referencias rotas; no borra filas); `entradas_web.token_clave` calculada y `UNIQUE (token_clave, idempotencia)` (la agenda pública con `token_id` NULL queda protegida); saca `cuentas_correo.imap_ultimo_uid` (sin uso); borra los ajustes `smtp_pass`/`imap_pass` en texto plano si hay una casilla activa con contraseña. Requiere 002–018 |

Los números 011, 012 y 014 no existen. Las migraciones 005–009, 013, 016, 018 y 019 usan
`ADD COLUMN/INDEX IF NOT EXISTS`, que es sintaxis de MariaDB (`schema.sql` sirve también en
MySQL 8). Sin la 010, la 013 o la 015 el código sigue andando, con lo que cada una agrega
apagado: sin la 010 las alertas del equipo se leen como antes (para todos), sin la 013 no hay
reintentos de Google y sin la 015 no se purgan las campañas vencidas.

---

## 5. Acceso y sesión

Copia exacta del modelo de APP Tickets, que ya está probado:

- **Login 100 % Google Workspace.** Sólo cuentas `@intellihelp.tech` (parámetro `hd` de OAuth).
  No hay contraseñas en la base.
- **Aprovisionamiento automático:** el primer ingreso de una cuenta del dominio crea el usuario
  con rol `comercial`; el administrador lo promueve si corresponde.
- **Roles:** `admin` (todo, incluida configuración, usuarios, API y CRON) y `comercial`
  (su trabajo diario).
- **Cierre automático de sesión, impuesto por el servidor:** 10 minutos de inactividad y
  8 horas de sesión máxima (`idle_ttl` / `abs_ttl`; con `idle_ttl = 0` no hay corte por
  inactividad, tampoco en el navegador). El aviso del navegador es cosmético; la seguridad la
  aplica el servidor en la siguiente request.
- **La inactividad se mide entre todas las pestañas.** `idle.js` comparte la última actividad
  por `localStorage` (clave por instalación) y, antes de cerrar, pregunta al servidor
  (`api-sesion?__vivo=1`): una pestaña olvidada ya no cierra la sesión de la que se está usando.
  Al vencer va a `logout?motivo=inactividad`. Las consultas automáticas (campana de alertas,
  avance de campañas, refresco de pantallas) van con `__vivo=1` y **no renuevan la sesión**: una
  pestaña visible sin uso también se cierra a los 10 minutos.
- **Sin sesión**, los endpoints `api-*` (salvo la pantalla `api-keys`), `__vivo` y los pedidos
  XHR reciben `440` en JSON —el navegador manda a `login?expiro`—; el resto va al login.
- **Cierre de sesión:** por POST con CSRF. Por GET sólo si el pedido viene del mismo sitio
  (`Sec-Fetch-Site` o `Referer`); si no, una pantalla pide confirmar con un botón (un sitio de
  afuera ya no puede desloguear a nadie). Acepta `?motivo=` y `?expiro=`.
- **CSRF** en todo formulario (`verify_csrf()`, comparación con `hash_equals`). Si el token
  venció, la pantalla `419` muestra lo que se había escrito para no perderlo; un XHR recibe JSON.
  **Cookie** `HttpOnly` + `SameSite=Lax` + `Secure` bajo HTTPS.

**Permisos por rol.** Además de las pantallas sólo de administración:

| Acción | Quién |
|---|---|
| Exportar a CSV contactos, empresas, posibles clientes y entradas de la web | sólo admin |
| Mandar un registro a la papelera | admin, o el propietario. Sin propietario (p. ej. importados), cualquiera. Listas y plantillas: quien las creó; campañas: propietario o creador |
| Restaurar de la papelera | admin |
| Borrar un adjunto | su autor o admin (va a la papelera, solapa «Archivos») |
| Iniciar o cancelar una campaña | su creador, su responsable o admin |
| Editar negocios, contactos y empresas | cualquiera (la base se comparte en el equipo); aprobar en masa, también |

La regla de la papelera vive en `puede_mandar_papelera()` (registros.php); las vistas usan
`puedo_mandar_papelera($entidad, $fila)` y **ocultan el botón** a quien no puede, en todos los
listados y fichas (también campañas, listas y plantillas). El servidor lo controla igual.

**Credenciales OAuth:** viven en el archivo de configuración de cada instalación, **fuera del
web root** (`/home/uXXXXXXXXX/crm_config/config.php` en producción,
`crm_config/crm-pruebas.php` en pruebas). Nunca en el repositorio. `bootstrap.php` elige el
archivo por el nombre de la carpeta de la instalación; la carpeta `crm_config/` se deduce de la
ruta (`/home/<usuario>/…`) o, si el hosting la tiene en otro lado, de la variable de entorno
`CRM_CONFIG_DIR`. La clave `storage` es obligatoria en la práctica: sin ella los adjuntos irían
al lado de la instalación, que en Hostinger queda dentro de `public_html`.

---

## 6. Auditoría · CRON

Igual que en APP Tickets: una tabla `actividad` con dos canales.

- **`app`** — qué hace cada usuario: navegación, altas, ediciones, borrados, conversiones,
  importaciones, envíos, ingresos y cierres de sesión, y **todos los errores** (PHP y
  JavaScript), cada uno con un código estable `ERR-XXXXXX` por archivo:línea.
- **`api`** — cada pedido a la API de lectura y a la API v1 de la web: acción, clave, código
  de respuesta y, si corresponde, el número de entrada. **Nunca** nombre, correo, teléfono ni
  mensaje: esos datos viven en `entradas_web`, que se anonimiza.

**La IP de quien no tiene sesión no se guarda en claro** (Ley 25.326). Los visitantes de las
páginas públicas —agenda, baja de suscripción, píxel de apertura— y los pedidos de la web quedan
con `h:` y el hash de la IP con el secreto de la instalación: alcanza para ver que diez pedidos
vinieron del mismo lugar, sin saber de cuál. `envio_eventos.ip` y `desuscripciones.ip` guardan
ese hash (migración 015). Con sesión (el equipo) se guarda como siempre.

**Errores.** Las excepciones de base y los errores inesperados van a `log_error()` con su código
`ERR-XXXXXX` (`archivo:línea`); la pantalla muestra un texto claro, nunca el mensaje técnico.

**Que no se pueda inundar.** `api-log` (errores del navegador) acepta sólo acciones `js.*`, un
tipo acotado, 300 caracteres y 30 por minuto por usuario. En `api/estado` y en la API v1 los
rechazos se registran hasta 20 por origen cada 10 minutos; los pedidos aceptados, siempre.

**Ajustes.** Guardar Ajustes registra `ajustes.guardar` con la lista de claves que cambiaron, y
pasar de modo a modo deja `ajustes.entorno_prueba` / `ajustes.entorno_produccion`. Los textos de
la auditoría usan los tiempos de sesión reales de la configuración.

**Secreto de la instalación.** Si la configuración no trae `app.secret`, `crm_secret()` genera
uno al azar y lo guarda en `STORAGE/.secreto-app` (antes era derivable de la base). Lo que se
había cifrado con el respaldo viejo se sigue pudiendo leer. La API v1 igual exige un
`app.secret` propio de 32 caracteres o más.

La sección **CRON** (solo admin) muestra la bitácora con filtros por canal, tipo, texto y
fechas, tarjetas de resumen y paginación. **El histórico nunca se borra**; en pantalla se
muestran los últimos 4 meses salvo que se pida una fecha anterior.

`log_evento()` nunca puede romper una request: todo va dentro de `try/catch`.

---

## 7. Núcleo CRM

Tres módulos que comparten el mismo patrón de pantalla (listado con filtros → ficha →
formulario), para que aprender uno sea aprender los tres.

| Módulo | Qué guarda | Pantallas |
|---|---|---|
| **Empresas** (`cuentas`) | La organización: nombre, tipo, sector, CUIT con validación módulo 11, teléfono, sitio, domicilio | `cuentas`, `cuenta-ver`, `cuenta-form` |
| **Contactos** | La persona: hasta 3 correos, teléfono directo, móvil, conmutador, LinkedIn, cargo, domicilio | `contactos`, `contacto-ver`, `contacto-form` |
| **Posibles clientes** | El contacto crudo sin calificar, con estado y calificación | `posibles`, `posible-ver`, `posible-form` |

**Decisiones:**

- Los campos «extra» (conmutador, teléfono directo, LinkedIn, tercer correo) **no son
  invento nuestro**: son los campos personalizados que el equipo ya usa en Zoho, y sin ellos
  se perdería el 77 % de los teléfonos de la base.
- **Alta de empresa al vuelo** desde el formulario de contacto: escribir el nombre alcanza,
  no hay que salir a crearla aparte (mientras hay texto en «crear empresa nueva» el selector deja
  de ser obligatorio). La empresa se crea recién cuando todo el formulario valida, y el nombre
  repetido se compara con `empresa_clave()` («ACME S.A.» = «Acme SA»).
- **Conversión de posible cliente** (`posible-convertir`): crea Empresa + Contacto + Negocio
  opcional dentro de una transacción. Si la empresa ya existe por nombre, o la persona por
  correo, **las reutiliza**. Notas, adjuntos, actividades y **etiquetas** se trasladan al
  contacto; sector y empleados pasan a la empresa sin pisar lo que tenía; un contacto reutilizado
  se completa sólo en los campos vacíos, y lo demás queda en una nota «Datos del posible
  cliente». El estado del posible no se pisa. El importe se lee en formato argentino o inglés
  («1.500.000», «1.234,56», «1234.56»), la moneda es ARS o USD y la fecha se valida. Un doble
  clic no convierte dos veces (`UPDATE … WHERE convertido_en IS NULL`: el segundo recibe 409 y un
  aviso). «Crear negocio» se ofrece sólo con el módulo Negocios encendido, y avisa si no hay
  embudo predeterminado. El posible cliente no se borra: queda marcado con los ids de lo que
  generó.
- **Búsqueda global** (`buscar`): una sola caja encuentra personas, empresas, posibles
  clientes y negocios. Respeta los módulos apagados y toma `%` y `_` como texto.
- **Buscador con autocompletado** en los desplegables grandes (empresa, contacto, negocio y
  campaña en los formularios de negocio, actividad y contacto): se escribe desde 2 letras y
  `api-buscar` devuelve hasta 20 registros vivos (`assets/js/buscador.js`). El valor actual
  se conserva siempre, aunque esté en la papelera: editar un registro ya no borra en silencio
  el vínculo que no entraba en la lista.
- **Exportación a CSV** con BOM y separador `;`, porque el equipo abre los archivos con Excel
  en español. Sólo administradores.
- **«No incluirlo en campañas»** se puede marcar y desmarcar; «Volver a habilitar» informa bien
  si la dirección figuraba dada de baja.
- **Validación en el servidor** de listas de valores, fechas e ids vinculados; los textos se
  recortan al largo de cada columna; el sitio web se guarda como texto (se acepta sin `https://`).
- **Dominio corporativo** por la parte registrable del dominio (`mail.empresa.com` es de
  `empresa.com`); el sitio web tiene que coincidir con el dominio entero, no parecerse.
- **Totales por moneda.** ARS y USD nunca se suman entre sí: `sumar_por_moneda()` (helpers.php)
  devuelve siempre las dos y cada pantalla las muestra por separado (ficha de empresa, negocios,
  Kanban, cuotas, pronóstico, reportes). No hay conversión de cambio.

**Notas, etiquetas y adjuntos** (registros.php). Exigen que el registro exista y no esté en la
papelera. Borrar una nota o un adjunto puede su autor o un admin, y el botón sólo lo ve quien
puede. Los adjuntos se borran de forma lógica y aparecen en la solapa **«Archivos»** de la
papelera, donde un admin los restaura; la purga del cron borra el archivo del disco. En disco se
guardan con su extensión sólo si es de documento (el resto queda como `.bin`), y la carpeta lleva
un `.htaccess` que niega todo. La descarga (`adjunto.php`) exige que el registro siga vivo y su
módulo encendido, y manda el nombre con `filename*` UTF-8. Las etiquetas se asignan en una
transacción.

**Alertas** (notificaciones.php). Las alertas del equipo (sin dueño) se marcan leídas **por
persona** (`notificacion_lecturas`): que una persona las lea ya no las oculta al resto. El
correo de una alerta personal va **a su dueño**; las del equipo, a `avisar_email_a` o, si está
vacío o todas sus direcciones son inválidas, a los administradores. Las alertas de un tipo con
el aviso apagado se marcan como tratadas, así al encenderlo no sale un correo con todo lo
acumulado. Los avisos de «Problemas del sistema» (`avisar_sistema`) no tienen interruptor en
Ajustes y quedan siempre encendidos.

**Importador del CSV de Zoho** (importador.php, pantalla Importar, sólo admin):

- Lee UTF-8 con o sin BOM, UTF-16 con BOM y **Windows-1252** (lo que guarda Excel en castellano),
  y reconoce los encabezados con o sin tildes. Fechas `d/m/Y` (`fecha_zoho`).
- Busca el contacto por `zoho_id` y por **los tres correos**. Los que están en la papelera se
  omiten con motivo. En una actualización **no pisa** el propietario ni la empresa si ya tenían.
- Empresas únicas por `empresa_clave()`.
- Trabaja en tandas de 200 filas por transacción, sin tope de tiempo, y anota el lote en
  `import_lotes` desde el principio: si el servidor corta, el lote queda como «Cortada» (más de
  1 h sin terminar) en vez de desaparecer.
- Avisa los correos inválidos que descartó; la simulación cuenta las filas repetidas.
- El destino «lista de campaña» valida que la lista exista y deja su lote.

---

## 8. Negocios y embudo

- **Embudos y etapas configurables.** El embudo «Ventas» viene con siete etapas
  (Calificación 10 % → Relevamiento 20 % → Propuesta enviada 40 % → Negociación 60 % →
  A firmar 80 % → Ganado 100 % / Perdido 0 %).
- **Ingreso ponderado calculado en la base** (columna generada `importe × probabilidad`): no
  se puede desincronizar de los datos.
- **Vista Kanban** (`embudo`) con una columna por etapa, total por columna y tarjeta por
  negocio; y **vista lista** (`negocios`) con filtros y totales.
- **Historial de etapas** (`negocio_etapas`): cada movimiento abre y cierra un tramo, así se
  puede medir cuánto tarda un negocio en cada paso.
- **Pronóstico** (`reportes`): cuota del período vs. ganado vs. embudo abierto y ponderado,
  por vendedor. Los montos van **por moneda** (columnas `*_usd` aparte); el cumplimiento de la
  cuota se calcula sólo en ARS. Reportes filtra «Fuentes» y «Campañas» por el período elegido y
  sus barras muestran cantidades. El panel muestra «Ganado este mes · desde el 01/mm».
- **Atribución de campaña**: cada negocio puede apuntar a la campaña que lo originó, que es
  lo que permite medir qué campaña trae plata de verdad.

**Reglas al editar y mover:**

- El formulario ofrece las etapas **del embudo del negocio** (más la actual, aunque esté
  apagada). Si llega una etapa que no vale, el negocio conserva la que tenía.
- Al cambiar de etapa, la probabilidad pasa a ser la de la etapa nueva (por el formulario y por
  «Mover de etapa»).
- `cerrado_en` se escribe al pasar de abierta a cerrada y se conserva en los guardados
  siguientes (`negocio_cerrado_en()`): corregir la descripción de un negocio ganado en marzo no
  le cambia la fecha de cierre.
- `negocio_mover()` corre en una transacción, sólo a etapas **activas del mismo embudo**; mover a
  la misma etapa no hace nada, y reabrir un negocio borra el motivo de pérdida.
- El propietario dado de baja se conserva al editar (no se reasigna a otro).
- Los vínculos (empresa, contacto, campaña) se validan antes de guardar (`negocio_validar_vinculos()`).
- Cualquier usuario edita y mueve cualquier negocio: es una decisión del equipo. La papelera de
  un negocio, en cambio, sólo la ven su dueño, un admin o cualquiera si no tiene dueño.

**Embudos y etapas** (pantalla `embudos`, admin): renombrar, activar y crear con
redirección después de guardar (recargar no duplica), en transacción y sin nombres repetidos.
Una etapa **apagada** que todavía tiene negocios sigue en el Kanban, marcada, y la ficha la
incluye en el selector. Para borrar una etapa se cuentan también los negocios en la papelera;
el historial de un negocio muestra «Etapa eliminada» en los tramos de una etapa borrada.

**Cuotas:** el importe se lee con `importe_parsear()` y el período se valida (`periodo_valido()`).

---

## 9. Actividades

Tareas, llamadas y reuniones viven en **una sola tabla** (`actividades`, con
`actividad_invitados`) porque comparten casi todos los campos; así la agenda del día y la línea
de tiempo de un registro salen de una consulta. No confundir con `actividad` (singular), que es
la auditoría.

- Vistas rápidas: pendientes, hoy, esta semana, vencidas, completadas.
- El formulario muestra sólo los campos que aplican al tipo elegido (una tarea no tiene
  duración ni resultado; una llamada no tiene hora de fin).
- Completar una actividad actualiza la **fecha de último contacto** del contacto o del
  posible cliente, que es lo que después permite filtrar «sin tocar hace 30 días».
- Cada alta y cada completado queda también en la línea de tiempo del registro asociado.
  **Reabrir** una actividad no deja «completada» en la línea de tiempo ni toca la fecha de último
  contacto; editar una completada conserva su `completada_en`.
- Una **reunión que vino de la agenda** tiene la fecha bloqueada en el formulario: se cambia con
  «Mover la reunión», que mueve también la reserva, el evento de Google y la invitación.
- En los formularios (actividades, contactos, empresas) el propietario se preselecciona sólo en
  las altas: guardar sin tocar ya no asigna la ficha a quien edita. «Nuevo contacto» desde la
  ficha de una empresa la trae elegida (`?cuenta_id=`). El enlace de Meet se muestra sólo si es
  `https`. El filtro conserva «vencidas». Completar una actividad comprueba que exista.

---

## 10. Agenda propia de reuniones

Reemplazo de Calendly. Detalle del diseño en
[ZOHO-CRM-REFERENCIA.md §10](ZOHO-CRM-REFERENCIA.md#10-reuniones-y-agenda).

| Pieza | Implementación |
|---|---|
| Tipos de reunión | `agenda_tipos` + `agenda_horarios` (horario laboral por día de la semana) |
| Disponibilidad | `agenda_huecos_rango()` — horario laboral **menos** `freeBusy` de Google Calendar **menos** lo ya reservado, con colchón entre reuniones y antelación mínima, en la zona horaria del tipo. La consulta a Google cubre hasta el **final** del último día ofrecido |
| Página pública | `/r/<usuario>/<tipo>` — sin login, elige día y hora y completa sus datos. Se apaga con el módulo Agenda |
| Reserva | `agenda_reservar()` — bloquea el calendario del vendedor (`GET_LOCK` con el nombre de la base), comprueba que el inicio sea un hueco ofrecido y siga libre —**consultando Google en vivo, sin caché**, con 6 s como máximo en total, renovación del token incluida— y guarda reserva, contacto, empresa y reunión en una transacción. No es «todo base de datos»: lo que tarda es esa consulta a Google. `reservar.php` exige que el dueño del tipo siga activo |
| Alta del evento | `agenda_reserva_completar()` → `gcal_crear_evento()` con Meet y `sendUpdates=none`, con un id de evento propio (`gcal_id_evento`): si se reintenta y Google responde 409, se reutiliza el que ya existe. Después, el correo con la invitación `.ics`. Si el proceso se corta, el cron la completa (`avisada_en` vacío). No se crea el evento si la reserva ya no está confirmada |
| Vuelta al CRM | Contacto por cualquiera de sus tres correos, empresa sin duplicar («ACME S.A.» = «Acme SA», dominio corporativo) y la reunión en Actividades **con la empresa** |
| Avisos | Una sola invitación, la del CRM, con el `iCalUID` de Google. Confirmación, recordatorio (cron) y cancelación, siempre con el mismo `UID` y la secuencia siguiente. Mover una reunión no genera una segunda alerta de «agendó una reunión» |
| Recordatorio | 24 h antes, si se reservó (o movió) con 24 h o más de anticipación. Si se reservó con menos, sale 12 h después de reservar (`AGENDA_RECORDATORIO_GRACIA_H`), y si la reunión es antes, no hay recordatorio: la confirmación acaba de llegar |
| Cambio de horario | `/r/mover?t=<token>` — el invitado elige otro hueco; la invitación nueva reemplaza a la anterior. **Base primero, Google después** (ver abajo) |
| Cancelación | `/r/cancelar?t=<token>` — sirve aunque sea el enlace de un correo viejo (sigue la cadena hasta la reserva vigente); borra el evento en Google, da de baja la actividad y descuenta la reunión de su campaña (`campanas.reuniones`). Cancelar también toma el bloqueo del vendedor (espera hasta 10 s; si no, «hay otro cambio en curso»). Una doble cancelación manda un solo aviso, y la pantalla promete el correo sólo si salió |
| Protección | Campo trampa, token de formulario firmado (mínimo 3 s, máximo 2 h; hace de CSRF), consentimiento obligatorio y, por hash de IP, 3 envíos por hora / 6 por día |
| Tope diario | 15 reservas por día y por vendedor desde afuera (`agenda_tope_diario`). Los cambios de horario no cuentan; las canceladas sí, a propósito |
| Feriados | Nacionales sincronizados solos con el calendario oficial, más los de la jurisdicción configurada (CABA por defecto). Ningún feriado tiene turnos: ni en la web, ni en `/r/`, ni al mover una reunión, ni en los días de una cobertura. Se comparan con la **fecha argentina** del horario, aunque el tipo esté en otra zona horaria. Ver [§10.2](#102-feriados) |
| Cobertura (vacaciones) | Ajuste `agenda_coberturas`: quién atiende un tipo de reunión entre dos fechas. Esos días los horarios salen del calendario de quien cubre y la reunión queda a su nombre (evento, Meet, confirmación y «responder a»). Terminado el período vuelve sola al dueño. Ver abajo |

**Detalles que costaron:**

- Google puede devolver la conferencia en estado `pending` **sin el enlace**: se relee el
  evento hasta tres veces antes de darse por vencido.
- Se pide **acceso offline** (`prompt=consent` la primera vez) porque quien reserva es un
  cliente de afuera, no el vendedor: hace falta el `refresh_token` para poder crear el evento
  sin que el vendedor tenga sesión abierta.
- Antes de guardar se **revalida el horario** con el calendario del vendedor bloqueado: entre
  que el visitante elige y confirma, otro pudo haber tomado el mismo hueco. Sin el bloqueo, dos
  pedidos simultáneos se quedaban con el mismo horario.
- Si Google falla, la reserva **no se pierde**: queda en el CRM con el error registrado.
- **Mover y cancelar: base primero, Google después.** Dentro del bloqueo del vendedor se relee
  la reserva con `FOR UPDATE` y el cambio exige `estado = 'confirmada'` (`rowCount`): si el
  invitado y el equipo mueven o cancelan a la vez, gana uno solo. En la misma transacción se
  marca `agenda_reservas.gcal_pendiente` (`'mover'` o `'borrar'`, migración 013); después del
  commit se toca Google y, si anda, se desmarca. Lo que queda marcado lo reintenta el cron
  (`agenda_sincronizar_google()`, paso 3): hasta 20 por vuelta, las tocadas hace más tiempo
  primero, con dos minutos de gracia para el pedido original, y deja de insistir un día después
  de la reunión. `agenda_bloquear()` lanza un error si se lo llama con una transacción abierta.
- **Cuando Google contesta mal no se publica nada.** Un `freeBusy` con `errors`, sin el
  calendario pedido o con código distinto de 200 cuenta como «no se pudo leer» (sin caché): ese
  vendedor queda sin horarios en vez de mostrar todo libre. Una caída de red (código 0 de curl)
  ya no borra el aviso de falla.
- **La falla de Google es por vendedor** (ajuste `gcal_falla_u<id>`): el aviso lo ve el vendedor
  afectado, con «Reconectar» para su propia cuenta; uno ajeno lo ve sólo un admin, con el
  nombre de quién. Si Google rechaza la renovación del permiso (`invalid_grant`), se marca
  `google_invalido_u<id>`, se deja de intentar por 15 minutos y la agenda dice «Falta conectar
  tu Google Calendar». `google-callback` avisa si al conectar no se dieron los permisos del
  calendario. La renovación del token tiene su propio tope de conexión y de tiempo.
- `agenda-form` valida en el servidor nombre, días, horas, zona y slug (60 caracteres, sin
  chocar con otro tipo); guarda el horario en una transacción y muestra al dueño aunque esté
  inactivo. Sin Google conectado, la pantalla dice la verdad: no se ofrecen horarios ni se
  toman reservas.
- **Una sola invitación.** Antes salían dos —la de Google y la del CRM, con identificadores
  distintos— y el cliente quedaba con dos eventos para la misma reunión. Google ya no avisa a
  nadie; la `.ics` del CRM usa el `iCalUID` del evento, así que para Gmail son el mismo. Si en
  algún caso duplicara, `agenda_invitado_en_google = 0` saca al cliente de los invitados de
  Google (entra a la Meet pidiendo permiso).
- **El evento de Google sólo lleva el nombre y la empresa**, en el título. Ni el comentario, ni
  el teléfono, ni de dónde llegó: el evento lo ven todos los invitados. La reserva queda
  enlazada en `extendedProperties.private.crm_reserva_id`, que sólo ve el dueño del calendario.

**Cobertura por vacaciones.** La web y el enlace público piden siempre el mismo tipo
(`cbaldelli/diagnostico`); lo que cambia es quién lo atiende cada día. Se carga en el ajuste
`agenda_coberturas` (tabla `app_settings`), una lista JSON:

```json
[{"tipo_id": 4, "usuario_id": 4, "desde": "2026-11-09", "hasta": "2026-11-24"}]
```

- Las fechas van **incluidas** y se leen en la zona horaria del tipo.
- Los días cubiertos, `agenda_huecos_rango()` calcula con el Google Calendar y las reservas de
  quien cubre; el resto de los días, con los del dueño. Si el calendario de uno no se puede
  leer, sólo sus días quedan sin horarios.
- `agenda_reservar()` decide el anfitrión por la fecha del inicio (`agenda_tipo_para()`): la
  reserva, la reunión, el contacto nuevo y la entrada de la web quedan a su nombre.
- Lo que viene después (evento con Meet, confirmación, recordatorio, cambio de horario,
  cancelación) usa siempre a quien tiene la reserva (`agenda_tipo_de_reserva()`). Una reunión
  ya tomada no cambia de manos: si el invitado la mueve, se le ofrecen los horarios de esa
  misma persona.
- Quien cubre tiene que tener su Google conectado en el CRM; si no, sus días no muestran
  horarios (nunca se publica sin ver el calendario).
- Si quien cubre deja de estar activo, esos días vuelven al dueño.

### 10.1 Reservas y consultas desde la web

La landing `intellihelp.tech/diagnostico/` y el formulario «Agendá una reunión» entran al CRM
por la **API v1**, servidor a servidor. Contrato completo y decisiones en
[INTEGRACION-WEB.md](INTEGRACION-WEB.md).

| Recurso | Qué hace |
|---|---|
| `GET api/v1/disponibilidad` | Los horarios libres de un tipo de reunión (hasta 31 días) y los **feriados del rango** (`feriados`: fecha y nombre). Sin calendario responde `503` y la web muestra el formulario |
| `POST api/v1/reserva` | Toma el horario y responde `201`; el evento con Meet y el correo salen después de responder. En un feriado, `409 feriado` con el nombre del feriado |
| `POST api/v1/entrada` | Consulta sin reunión: contacto, empresa, nota con el mensaje, tarea «Responder consulta web» para el responsable (vence el próximo día hábil, descontando los feriados de la tabla) y alerta |

**Seguridad.** Clave con el permiso «Escritura (web)» y un secreto que nunca viaja: cada pedido
va firmado con HMAC-SHA256 sobre la hora, el método, la ruta completa (con la carpeta de la
instalación: una firma de pruebas no sirve en producción) y el cuerpo. Se aceptan 5 minutos de
diferencia de reloj. El secreto se guarda cifrado y se muestra una sola vez. La API exige que
la instalación tenga su `app.secret` propio, de 32 caracteres o más.

**Reintentos sin duplicados.** Cada escritura trae una clave de idempotencia y se guarda en
`entradas_web` **antes** de tocar la agenda. El mismo pedido otra vez recibe la respuesta
original; la misma clave con otro pedido, `409`. Si el horario ya estaba tomado, la web puede
volver a mandar con la misma clave y otro horario. Una consulta igual (mismo correo, servicio y
mensaje) dentro de los 30 minutos queda descartada: es un doble clic o el botón «atrás» (la
comprobación corre bajo un `GET_LOCK` por correo, así dos envíos simultáneos no abren dos
tareas). Una entrada que quedó trabada en `procesando` se repara sola (`api_reparar_previa()`):
si tiene reserva enlazada, el reintento recibe la respuesta de esa reserva (`repetida: true`);
si no tiene nada, vence a los 120 s y se puede volver a intentar.

**Límites.** 120 consultas de horarios y 30 escrituras por minuto por clave; por visitante
(hash de la IP que calcula la web), 3 reservas por hora y 6 por día —contando **sólo las
reservas que entran**: un horario tomado, un feriado, un error o un reintento con la misma
idempotencia no gastan cupo—, más un tope de 20 intentos de reserva por hora; 5 consultas por
hora y 10 por día. Al pasarse responde `429` con `Retry-After`.

**De dónde llegó.** Cada entrada guarda la página, el canal, las UTM, `gclid` y `li_fat_id`, el
referrer, la landing y las visitas. La pantalla **Entradas desde la web** muestra el listado
con filtros y exportación, y arriba las tarjetas por canal con consultas, reuniones agendadas,
realizadas y canceladas: son las métricas que la consultora mira cada mes. La exportación a CSV
(trae datos personales) es sólo para administradores. Ahí también se elige
quién atiende las consultas (`web_dueno_id`; si no hay nadie elegido, el vendedor del tipo
`diagnostico`, y si tampoco, el primer administrador).

**Datos personales (Ley 25.326).** Consentimiento expreso con fecha, versión y huella del
texto. Los contactos nuevos que entran por la web quedan con «No incluir en campañas» salvo que
pidan novedades; a uno que ya existía no se le cambia nada (ni dueño, ni fuente, ni bajas). La
IP nunca se guarda: sólo su hash. El cron borra los datos personales de las entradas viejas
sin contacto o con error, y conserva el canal y la página para las métricas.

**Sin posibles clientes ni negocios.** Una consulta todavía no es una oportunidad: el negocio lo
abre quien califique la reunión.

### 10.2 Feriados

En un feriado no hay turnos ni se puede reservar, sin que nadie tenga que cargarlos. Código en
`app/feriados.php`; tabla `feriados` (migración 009).

**De dónde salen.** Del calendario oficial de feriados nacionales que publica
[ArgentinaDatos](https://api.argentinadatos.com/v1/feriados/2026) (el del Ministerio del
Interior), el mismo que usa el portal de RRHH. Se consulta el año en curso y el siguiente:

- se **agregan** los que se decretan durante el año (días puente, feriados por decreto);
- los **trasladables** se reubican: cuando se fija la fecha, la vieja deja de figurar y aparece
  la nueva;
- se **quitan** los que dejan de figurar (un puente que se deja sin efecto);
- lo **cargado a mano** (`origen = 'manual'`) no se toca nunca; cargar uno a mano el mismo día
  que uno oficial no pisa al oficial ni lo vuelve manual;
- lo marcado **«se trabaja»** (`ignorado = 1`) queda en la tabla para que no vuelva a aparecer.

Un año para el que la fuente no responde, o responde con menos de diez feriados, **no se
modifica**: se sigue con lo que había.

**Cuándo.** Una vez por día desde el cron (la primera corrida del día) y **al vuelo** antes de
calcular horarios si nunca se sincronizó, si no hay datos del año o si lo sincronizado tiene más
de 24 h. La consulta al vuelo espera **2 segundos** como mucho por año y corta en el primer año
que falla (desde el cron, 15 s); si falla, no se vuelve a probar hasta 15 minutos después, así
una fuente caída no demora a ningún visitante. Una sola
sincronización a la vez (`GET_LOCK`). **Nada de esto puede trabar la agenda**: sin respuesta, o
sin la tabla todavía, la agenda sigue con lo que tiene.

**Regionales.** El calendario oficial trae sólo los nacionales. Los de una provincia se cargan
a mano con su jurisdicción. Rigen los nacionales **más** los de la jurisdicción del ajuste
`feriados_jurisdiccion` (por defecto `CABA`, donde está la oficina); los de otras provincias se
ven en la lista pero no quitan turnos.

**Dónde se aplica.**

| Lugar | Qué pasa en un feriado |
|---|---|
| `agenda_huecos_rango()` | El día no tiene horarios. Se saca **antes** de consultar a Google: un rango que es todo feriado no mira el calendario. Cubre la API, `/r/`, `/r/mover` y las coberturas (cada tramo pasa por acá) |
| `agenda_hueco_libre()` | Motivo `feriado` |
| `agenda_reservar()` | `AgendaExcepcion` motivo `feriado` con el nombre, **por cualquier camino** (web, `/r/`, CRM, con o sin validar el hueco), antes de bloquear el calendario |
| `agenda_reprogramar()` | Igual: ni el invitado ni el equipo mueven una reunión a un feriado |
| `GET api/v1/disponibilidad` | `feriados: [{fecha, nombre, tipo, nacional, jurisdiccion}]` del rango pedido |
| `POST api/v1/reserva` | `409 {"error":"feriado","mensaje":"El martes 8 de diciembre de 2026 es feriado (…)…","feriado":{"fecha","nombre"}}` |
| Agenda interna | «Próximos feriados» (60 días) en Agenda y la marca «Feriado» en las reuniones reservadas que caen en uno |

**Aviso al responsable.** Si un feriado nuevo (sincronizado, cargado a mano o restituido) cae
sobre reuniones confirmadas, quien atiende cada una recibe una alerta —y su correo, con el resto
de las alertas del cron— para que coordine con el invitado. La reunión **no se cancela sola**.

**Pantalla** Más → **Feriados** (sólo administración, se apaga con el módulo Agenda): la lista
del año con origen y si rige, «Actualizar ahora» (si falla, muestra el error real), agregar uno a mano (fecha, nombre, dónde
rige, tipo), «Se trabaja» / «Volver a feriado», la jurisdicción, el interruptor general
(`feriados_activo`) y la lista de reuniones agendadas en feriados.

**Ajustes:** `feriados_activo` (1), `feriados_jurisdiccion` (`CABA`), `feriados_sincronizado_en`,
`feriados_intento_en`, `feriados_ultimo_error`.

---

## 11. Campañas de correo

El motor de Pulsemail llevado al servidor. Diagrama completo en el
[README](../README.md#motor-de-campañas).

| Pieza | Tabla / archivo |
|---|---|
| Plantillas con variables | `plantillas` · editor por bloques (`bloques.php`) · `render_plantilla()` |
| Listas de destinatarios | `listas`, `lista_miembros` · cargables desde el filtro del CRM |
| Cola de envío | `envios` (una fila por destinatario, con clave única por campaña+correo) |
| Bitácora de cada envío | `envio_eventos` (apertura, clic, rebote, respuesta, baja; la IP como hash) |
| Despacho | `campanas_despachar()`, llamado por `app/cron.php` |
| Envío SMTP | `envio.php` — sin dependencias, multipart texto + HTML |
| Rebotes y respuestas | `imap.php` — cliente IMAP propio, con seguimiento por UID y `UIDVALIDITY` |
| Conversaciones | `mensajes` · `mensajes.php` · pantalla `conversacion` |
| Bajas | `desuscripciones` (lista negra global) + `List-Unsubscribe` + `bajas_tokens` |

**Reglas que no se negocian:**

1. **Pausa aleatoria entre correos** de la misma campaña: **25–45 s por defecto**,
   configurable por campaña desde 5 s (mínimo) y nunca con la mínima por encima de la máxima.
   Por debajo de lo normal el ritmo parece robótico; por encima, la campaña no termina nunca.
2. **Varios correos por corrida del cron.** Cada corrida trabaja en vueltas de ~1 minuto
   (`CRON_CICLO_SEG` 55 s, `CRON_PRESUPUESTO_SEG` 45 s por vuelta) hasta gastar
   `CRON_CORRIDA_SEG` (280 s): por vuelta salen uno o dos correos por campaña, según la pausa
   sorteada. Da lo mismo programar el cron cada minuto o cada cinco: las corridas que arrancan
   mientras otra sigue viva encuentran el turno tomado (`cron_turno`) y se van, y la casilla se
   mira una vez por minuto mientras hay campañas andando (cada 15 minutos si no).
3. **Nunca dos veces al mismo destinatario** en la misma campaña (clave única en la base).
4. **Se chequea dos veces**: al armar la cola y justo antes de enviar. Quedan afuera los dados
   de baja, los marcados «No incluir en campañas», los rebotados y los contactos o posibles
   clientes que están en la papelera.
5. **`Message-ID` propio y guardado**: con él se cruza un rebote o una respuesta con su envío
   (ver «Correo entrante» abajo).
6. **Texto plano + HTML siempre**: un correo sólo-HTML es señal de spam.
7. **En modo prueba sólo sale a la lista blanca**: los usuarios activos del CRM más las
   direcciones del ajuste `testing_lista_blanca` (separadas por coma, punto y coma o espacio;
   migración 015). Lo demás queda `omitido` con su motivo, al iniciar y al despachar. El ajuste
   no tiene pantalla: se carga directo en `app_settings`.

**Estados de un envío.** `pendiente` → `enviando` → `enviado` (después, `rebotado` si llega el
rebote), o `omitido`, `rechazado` o `error` con su motivo:

| Resultado SMTP | Qué queda | ¿Se reintenta? |
|---|---|---|
| 5xx en el `RCPT` del destinatario | `rechazado` (se marca el rebote) | no |
| 5xx en `MAIL FROM` o en el `DATA` | `error` **fallido** | no |
| Se mandó el mensaje entero y se perdió el `250` | `error` **dudoso**: pudo haber salido | no; se revisa a mano |
| Temporal (4xx, red, `5.4.5`, cuota, *rate*) | `pendiente` con espera creciente (de a 5 min, hasta 60) | sí, hasta 20 intentos |

Justo antes del `DATA` el envío se marca «Entregando al servidor…» (callback `$alEntregar` de
`smtp_enviar()`). Si el proceso muere después de esa marca, el rescate de los 15 minutos lo pasa a
`error` dudoso en vez de volver a mandarlo; sólo vuelve a la cola lo que se cortó antes del
`DATA`. La conexión a la base se renueva (`db_reanudar()`) antes de anotar el resultado. El corte
por presupuesto no pisa las esperas de reintento.

**Topes.** El tope diario de la campaña y el cupo de la casilla se miran antes de **cada**
correo (también cuando varias campañas comparten casilla). Si la campaña eligió una casilla y
está desactivada, la campaña espera: no se cae a la predeterminada. Las sesiones SMTP se
cierran siempre (`try/finally`).

**Quién y cuándo.** Iniciar y cancelar puede el creador, el responsable o un admin
(`campana_puede_gestionar()`), y sólo por transiciones válidas (`campana_transicion_valida()`):
no se inicia una campaña completada o cancelada, e iniciar exige `campana_lista_para_enviar()`
y que la casilla de la campaña esté activa. Los botones se ocultan a quien no puede. No hay paso
de aprobación.

**Seguridad de las plantillas.** El editor sólo guarda plantillas `visual` (por bloques); una
plantilla `html` vieja se sigue mandando, pero al editarla pasa a visual. El JSON del editor va
con `JSON_HEX_TAG|AMP|APOS|QUOT`. Los valores de las variables (`{{nombre}}`, `{{empresa}}`,
`{{cargo}}`…) se escapan al insertarlos en el HTML. Los botones sólo pueden apuntar a
`{{agenda}}`, `{{baja}}` o `{{empresa_web}}`. Las vistas previas (`plantilla-vista` con CSP
`sandbox`, `campana-ver` y el editor en `iframe sandbox=""`) no corren en el origen del CRM, y
el `<style>` del correo queda aislado dentro del marco. El asunto se codifica según RFC 2047
(palabras de hasta 75). Una imagen en uso por una plantilla no se puede borrar.

**Baja y seguimiento.** Antes de purgar una campaña, sus tokens se copian a `bajas_tokens`: el
enlace de baja de un correo ya entregado sigue funcionando después de la purga (se busca en
`envios` y, si no está, en `bajas_tokens`). Una baja repetida no suma dos veces. El píxel de
apertura valida el token, registra con un `UPDATE` condicional y guarda la IP como hash; la vista
previa no lleva píxel. Los textos de las pantallas usan el plazo real de la papelera
(`dias_papelera()`), no «60 días» fijos.

**Pantalla de la campaña.** `api-campana` devuelve sólo las filas que la pantalla tiene a la
vista (`envios=1,2,3`, hasta 100; sin el parámetro, 50) y las cifras con una consulta agrupada.
La ficha se actualiza cada 6 s.

**Correo entrante** (`imap.php`, `mensajes.php`):

- **Respuestas:** se cruzan por `In-Reply-To` / `References` contra `envios.message_id` y
  después contra `mensajes.message_id`. Si no cruza, se toma como respuesta sólo si hubo un
  envío a ese remitente en los últimos **45 días** (`IMAP_RESPALDO_DIAS`).
- **Rebotes:** se atribuyen por el `Message-ID` del envío que viene en la copia del original;
  si no, por el DSN (`Final-Recipient`); si no, sólo por el aviso, sin mirar la copia. Un
  «4xx» suelto en el texto ya no clasifica el rebote. `marcar_rebote()` marca sólo el envío más
  reciente a esa dirección de los últimos 7 días.
- **Robustez:** la marca de UID avanza por mensaje (uno que falla se saltea y queda
  registrado); la conversión de juegos de caracteres nunca lanza (`texto_a_utf8()`: alias,
  iconv, Windows-1252); el boundary se toma del encabezado original (Outlook, Apple Mail,
  Gmail); si cambia `UIDVALIDITY` se relee la ventana inicial sin duplicar (migración 016); el
  turno de cada casilla se toma de forma atómica y se inserta con `INSERT IGNORE`; los literales
  que superan el tope se descartan sin desfasar la lectura; `message_id` e `in_reply_to` se
  recortan al largo de la columna.
- **Conversación:** muestra los 100 mensajes más recientes (con aviso si hay más). Contestar
  exige CSRF (`verify_csrf()`), sale por la casilla correcta —nunca por una inactiva— con
  `In-Reply-To`, «Re:» una sola vez y el saliente guardado. El HTML entrante se sanea con una
  **lista blanca sobre `DOMDocument`** (sin la extensión `dom` se muestra como texto plano) y se
  muestra en un `iframe` sin `allow-same-origin`, con CSP con nonce y alto ajustado por
  `postMessage` verificado. Se pierden `position` y `url()` del HTML entrante.
- **Casillas** (`cuentas-correo`): el alta guarda `smtp_pass`; recargar un remitente dado de baja
  lo reactiva y uno repetido vivo da un mensaje claro (migración 018: `remitente_vivo`). La
  pantalla redirige después de guardar. Cualquier usuario puede usar cualquier casilla: la base
  es compartida y el «dueño» es informativo.

---

## 12. Diseño e interfaz

Se hereda el sistema de diseño de **APP Remitos** / **APP Tickets**, que ya está afinado:

| Token | Valor |
|---|---|
| Primario | `#663399` (violeta INTELLIHELP) |
| Acento | `#9CC930` (verde) |
| Tipografía | **Saira** (400–800) |
| Radio | `15px` (tarjetas), `10px` (controles), `20px` (pills) |
| Sombra | tinte violeta, `0 1px 2px …, 0 8px 24px -12px rgba(74,36,112,.18)` |
| Iconos | **SVG de línea en el HTML**, nunca fuentes de iconos |

Reglas que se auditan de raíz (no se parchan):

- **Responsive sin scroll horizontal en ningún ancho.** El menú colapsa a drawer.
- **Sin controles nativos crudos:** toggles `.switch`, selects con combobox propio, fechas con
  datepicker propio.
- **Ventanas emergentes propias**, nunca `confirm()` del navegador. Al confirmar, el formulario
  se envía con `requestSubmit(botón)` (`enviarConBoton()` en app.js), así llega el botón apretado
  y corren la validación del navegador, `formnovalidate` y `data-cargando`. La ventana es
  accesible: Enter actúa según el foco, Tab queda encerrado, el foco vuelve al cerrar y tiene
  rol `alertdialog`. El desplegable propio (`selects.js`) es un combobox accesible.
- **Refresco «vivo»:** `vivo.js` y `secciones.js` piden con `__vivo=1`; un `440` los detiene y
  otros errores los pausan y se rearman. La huella de cada pantalla la lee `secciones.js`.
- **Colores y espaciados por variables CSS**, nunca hex crudo.
- **Nada de código ni jerga de desarrollador en la UI** fuera de las secciones API y CRON.
- **Una sección nueva copia el diseño de la equivalente ya aprobada** y sólo cambia la lógica.

**Ajustes** (sólo admin) se validan en el servidor (`ajustes_validar.php`); con un error no se
guarda nada y el formulario conserva lo escrito:

| Campo | Regla |
|---|---|
| Días en la papelera | entero de 7 a 365 (vacío conserva el valor) |
| Pausas entre correos | enteros de 1 a 3600; la mínima no supera a la máxima |
| Límite diario | entero de 1 a 100000 |
| Clave del cron | vacía (apaga la vía por URL) o de 24 a 128 caracteres |
| Correo de contacto · avisos a | vacío o correos válidos (separados por coma) |
| Teléfono · WhatsApp | teléfono válido; el WhatsApp se guarda en dígitos (acepta `+`, mínimo 8) |
| Modo de trabajo | `testing` o `produccion`; volver a «Prueba» desde «Producción» pide una confirmación aparte |

Un campo que no llega en el formulario no se toca: guardar Ajustes ya no apaga `avisar_sistema`.
La casilla predeterminada no se elige sola y sólo se ofrecen las activas. Un sector guardado
que no está en la lista se conserva.

---

## 13. Despliegue

- **Servidor:** Hostinger, `servidor.del.hosting`. SSH `IP_DEL_SERVIDOR:65002`,
  usuario `uXXXXXXXXX`.
- **Webroot:** `/home/uXXXXXXXXX/domains/intellihelp.tech/public_html/crm`
- **Configuración:** `/home/uXXXXXXXXX/crm_config/config.php` en producción y
  `crm_config/crm-pruebas.php` en pruebas (fuera del webroot, `chmod 600`). Cada instalación
  tiene su base, su `storage` y su `secret`. Ver [§5](#5-acceso-y-sesión) (`CRM_CONFIG_DIR`).
  La de pruebas lleva además `mail.desviar_a`: todo correo saliente (y los invitados de Google
  Calendar) va sólo a esa casilla (`correo_desvio()` en `envio.php`). En producción no se pone.
- **Base:** `uXXXXXXXXX_crm` en `localhost`.
- **Cron:** `app/cron.php` cada 5 minutos desde el hPanel (cada minuto también sirve, ver
  [§11](#11-campañas-de-correo)). Pasos: casillas IMAP y despacho de campañas en vueltas;
  3 reservas que quedaron sin evento o sin correo y reintentos de Google
  (`agenda_sincronizar_google()`); 3b recordatorios; 3c feriados (una vez por día); 4 alertas por
  correo; 5 purga de la papelera y 6 anonimización de las entradas de la web y purga de los
  contadores (una vez por día). El bloqueo lleva el nombre de la base
  (`GET_LOCK(CONCAT(DATABASE(), ':cron'))`): los cron de producción y de pruebas pueden correr a
  la vez. La marca diaria se escribe recién cuando la tarea terminó bien; si falló, se reintenta
  cada hora. «Hoy» sale de `CURDATE()` de la base. Por URL, la clave va en la cabecera
  `X-Cron-Clave` (`?clave=` sigue andando) y se compara con `hash_equals`; la clave tiene que
  tener 24 caracteres o más.
- **Destino obligatorio:** todo comando de `bin/deploy.py` lleva `--pruebas` o `--produccion`.
  Sin destino, con los dos o con uno desconocido, termina con error (código 2): un typo ya no
  despliega a producción. La clave del servidor SSH se verifica contra `known_hosts`; la primera
  vez se acepta con `--aceptar-clave-nueva` (muestra la huella).
- **Subir la configuración:** `deploy.py config --pruebas` sube `app/server-config/crm-pruebas.php` y
  `--produccion` sube `config.php`; verifica que el nombre de la base coincida con el destino.
- **Esquema:** `deploy.py schema --pruebas|--produccion` importa `schema.sql` sólo sobre una base
  **vacía**, con la contraseña de `CRM_DB_PASS_PRUEBAS` / `CRM_DB_PASS_PRODUCCION` en un archivo
  temporal `600` (nunca en la línea de comandos).
- **Migraciones:** `python bin/deploy.py migrar app/db/migracion-NNN-….sql --pruebas|--produccion`.
  Sube el `.sql` fuera del webroot y lo aplica `migrar.php` con la configuración de esa
  instalación: no pide credenciales de base y no puede equivocarse de base. Con `--simular` sólo
  lista las sentencias. En producción, siempre con un respaldo previo de la base. Lista en
  [§4](#4-base-de-datos).
- **Pruebas:** `python bin/deploy.py test --pruebas|--produccion` corre la lógica
  (`run_tests.php`), la interfaz (`ui_test.php`) y el HTTP (`smoke.sh`); contra pruebas suma
  `api_test.php`, que prueba la API v1 de punta a punta y borra lo que creó. `run_tests.php`
  exige `pdo_sqlite` (sin ella falla, salvo `CRM_TEST_SIN_BASE=1`). `render.sh` / `render_test.php`
  y `vivo_test.php` corren por defecto contra **pruebas**; contra producción, sólo con
  `--produccion`.
- **Subida:** `deploy.py app --pruebas|--produccion` (SFTP/SSH) sube `app/web/` y, a la raíz,
  `cron.php`, `reinicio.php` y `migrar.php`, sin secretos (excluidos por patrón). Con `--limpiar`
  lista lo que hay en el servidor y ya no está en el repositorio y, con confirmación, lo mueve a
  `~/crm_retirados` (no borra). No sube encima de lo que está en uso: arma la versión en
  `~/crm_build/<destino>-<fecha>` (copia del webroot + lo nuevo), le pasa `php -l` y recién
  entonces cambia las carpetas con dos `mv` (instantáneo, nunca conviven archivos viejos y
  nuevos; probado con 400 pedidos durante tres cambios, todos 200). Si algo falla, el sitio sigue
  como estaba. Las 5 últimas versiones reemplazadas quedan en `~/crm_versiones/<destino>` y
  `deploy.py app --volver` pone la anterior.
  Los assets no necesitan cambio de versión (ver `ASSETS_V`, [§3](#3-estructura-de-archivos)).
- **Reinicio de pruebas:** `php reinicio.php` (en la carpeta de la instalación) simula; `--hacerlo`
  vacía y siembra pidiendo escribir el nombre de la base (o `--confirmar=<base>`). Sólo corre
  si es la carpeta `crm-pruebas` con su propia config o una base `*_pruebas`, y si `app_entorno`
  dice `testing` (sin la fila, se toma como producción). Borra en una transacción, después
  Google y los adjuntos del disco; conserva los feriados. Los datos personales de los contactos
  de prueba salen de la sección `'pruebas'` de la config (o de ajustes `pruebas_<clave>`).

---

## 14. Reglas del proyecto

1. **Relevar antes de implementar.** Los supuestos que no se pudieron confirmar quedan
   documentados.
2. **Documentación siempre al día** — este documento maestro, el README, el changelog y los
   `.md` de cada módulo, en el mismo cambio que el código.
3. **Un test de regresión por cada defecto** encontrado: falla con el bug, pasa con el arreglo.
4. **Sin secretos ni datos reales en el repositorio.**
5. **Nada se borra de golpe:** borrado lógico (también los adjuntos) + papelera de 60 días por
   defecto (`papelera_dias`, mínimo 7). El único borrado real lo hace el cron, y sólo sobre lo
   que ya cumplió el plazo.
6. **Nada de datos de prueba en producción.** La base arranca vacía; los datos los carga el equipo.
7. **El trabajo del usuario nunca se pierde:** la campaña retoma donde quedó, los formularios
   guardan borrador.
8. **Código de producción, sin restos de prueba ni código muerto.** Comentarios en castellano
   que explican el *porqué*, no el *qué*.
9. **Cada cambio se aplica en todo por igual:** si algo mejora en un módulo, se replica en los
   equivalentes.

---

## 15. Changelog

> Cada cambio se registra acá, del más nuevo al más viejo.

- **2026-11-22** — Arreglos de la auditoría. Se juntaron diez ramas, una por lote de
  [AUDITORIA.md](AUDITORIA.md) (estado y lo que quedó sin arreglar, al principio de ese
  documento). Lo principal: contestar correos desde el CRM vuelve a andar (CSRF); IMAP ya no se
  traba con un juego de caracteres raro, guarda las respuestas de Outlook y Apple Mail y cruza
  respuestas por `In-Reply-To`; la confirmación de las ventanas manda el botón apretado («Borrar
  los datos de prueba», feriados); guardar Ajustes ya no apaga los avisos de sistema y los
  ajustes se validan; un `freeBusy` con error ya no publica todo libre; el cron de cada
  instalación tiene su propio bloqueo. Producción blindada: `deploy.py` con destino obligatorio,
  `config --pruebas` correcto, `schema` sólo sobre base vacía, `reinicio.php` con tres barreras y
  las pruebas de render y vivo contra pruebas por defecto. Sin pérdidas de datos: buscador con
  autocompletado en los desplegables grandes, etapas del embudo del negocio, importador con
  Windows-1252 y sin pisar propietarios, `cerrado_en` conservado, importes argentinos, adjuntos
  con papelera, inactividad compartida entre pestañas. Seguridad: XSS de plantillas y de correos
  entrantes cerrados, permisos por rol (exportar, papelera, campañas). Correo: lista blanca real
  en modo prueba, `bajas_tokens`, papelera fuera de las campañas, 5xx sin reintento, estados
  «fallido» y «dudoso», IP como hash en baja y píxel. Agenda: base primero y Google después con
  reintento (`gcal_pendiente`), carreras de mover/cancelar, recordatorio de último momento,
  feriados por fecha argentina. API: cupo por visitante que cuenta sólo reservas que entran, tope
  de 20 intentos por hora, reparación de entradas trabadas, `api/estado` sólo GET y 60/min. Montos
  separados por moneda. Migraciones 010, 013, 015, 016, 017 y 018; `schema.sql` crea 42 tablas
  con todas incluidas. El contrato con la web no cambió. Pruebas: `run_tests.php` 1187/1187 en
  local y 1183/1183 en el servidor (PHP 8.3), `ui_test.php` 334/334, contrato con la web 24/24,
  `smoke.sh` 71/71, render 61/61 pantallas, `api_test.php` 61/62 en pruebas (falla sólo «SMTP
  configurado en pruebas»: crm-pruebas no tiene casillas cargadas) y herramientas Python 21/21.
  Aplicado en crm-pruebas (código y migraciones 010–018); producción sin tocar.

- **2026-11-22** — Auditoría completa y comentarios en todo el código. Cada archivo (PHP, vistas,
  JS, CSS, SQL, Python, pruebas) lleva encabezado, docblock por función y el porqué de la lógica
  no obvia; se corrigieron los comentarios que no coincidían con el código. El código no cambió
  (verificado pieza por pieza, y las pruebas dan igual que antes: 418/418 y 331/331). Los hallazgos
  (1 crítico, 25 altos, 78 medios, 142 bajos), con sus prioridades, están en
  [AUDITORIA.md](AUDITORIA.md). En esta pasada no se arregló ninguno.

- **2026-11-08** — Feriados automáticos en la agenda. Tabla `feriados` (migración 009) que se
  sincroniza sola una vez por día, y al vuelo si faltan datos o tienen más de 24 h, con el
  calendario oficial de ArgentinaDatos: agrega los decretados, reubica los trasladables, quita
  los que dejan de figurar y respeta los cargados a mano y los marcados «se trabaja». Con la
  fuente caída la agenda sigue con lo que tenía. Ningún feriado nacional ni de la jurisdicción
  configurada (CABA por defecto) tiene turnos, en la web, en `/r/`, al mover y en las
  coberturas; reservar o mover a un feriado se rechaza con su nombre (`409 feriado` en la API) y
  `disponibilidad` devuelve los feriados del rango. Pantalla Más → Feriados y alerta al
  responsable cuando un feriado nuevo cae sobre una reunión agendada. Pruebas de nacional,
  regional, trasladable movido, puente, fuente caída y reserva rechazada.

- **2026-10-30** — Integración con la web (landing `/diagnostico/` y formulario de contacto),
  sólo en la parte de reuniones. API v1 firmada (`disponibilidad`, `reserva`, `entrada`) con
  idempotencia, límites y auditoría sin datos personales; tabla `entradas_web` con la página y
  el canal de cada consulta y reserva, y pantalla «Entradas desde la web» con las métricas por
  canal. Agenda corregida: el inicio tiene que ser un hueco ofrecido y libre, bloqueo por
  vendedor contra la doble reserva, la consulta a Google cubre el último día, una sola
  invitación con el identificador de Google, el evento sin el comentario del cliente, la
  reunión con su empresa, cambio de horario desde el correo (`/r/mover`), cancelación desde
  cualquier correo de la cadena, campo trampa, token de formulario, límite por IP,
  consentimiento y apagado real de `/r/` con el módulo Agenda. La auditoría guarda la IP de los
  visitantes como hash. Migración 008 con `migrar.php`; `deploy.py schema --pruebas` ya no
  apunta a la base de producción. Pruebas nuevas: firma, validación, idempotencia, duplicados,
  límites, ventana y huecos de la agenda, invitación `.ics`, y `api_test.php` de punta a punta
  contra pruebas.

- **2026-07-30** — Primera versión en producción sobre `crm.intellihelp.tech`: núcleo CRM
  (empresas, contactos, posibles clientes con conversión), embudo con Kanban y pronóstico,
  actividades unificadas, campañas de correo con cola + cron + IMAP, agenda propia con Google
  Calendar y Meet, importador del CSV de Zoho, papelera, auditoría, API de lectura y reportes.
  Batería de 85 pruebas unitarias y 48 comprobaciones HTTP. Repositorio publicado en GitHub.
  Base del diseño: el análisis de Zoho CRM y del CSV real de la empresa (13.573 contactos,
  71 columnas), con el mapa 1:1 en [ZOHO-CRM-REFERENCIA.md](ZOHO-CRM-REFERENCIA.md) y marcas
  de uso real para que el equipo comercial indique qué sobra; este documento maestro y los
  requerimientos permanentes. Decisiones cerradas con la dirección comercial: login 100 %
  Google Workspace, campañas por cola + cron + IMAP completo, SMTP de Gmail/Workspace, agenda
  propia con Google Calendar y Meet automático en reemplazo de Calendly.

---

*Se mantiene al día con cada cambio del sistema.*

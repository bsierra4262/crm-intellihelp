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
| Backend | **PHP 8.2** (Hostinger), front-controller propio, sin framework ni Composer |
| Base de datos | **MariaDB 11.8** (`uXXXXXXXXX_crm`), utf8mb4 |
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
   `/comprobantes` y `/remitos`.
5. **Nada se borra de golpe.** Todo borrado es lógico (`eliminado_en`) en las tablas de
   negocio. Lo eliminado vive en la papelera **60 días** —restaurable con un clic— y recién
   después el cron lo borra de verdad. El plazo existe porque la limpieza ahora también es
   automática: un rebote definitivo archiva el contacto sin intervención humana, y hace falta
   una ventana para revertirlo si el sistema se equivocó.

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
│   │   │   ├── helpers.php       url, csrf, flash, render, formato
│   │   │   ├── auth.php          sesión y usuarios
│   │   │   ├── google.php        OAuth 2.0
│   │   │   ├── gcal.php          Google Calendar + Meet
│   │   │   ├── actividad.php     auditoría (CRON)
│   │   │   ├── crm.php           cuentas, contactos, posibles clientes, entradas de la web
│   │   │   ├── api.php           API v1 de la web: firma, límites, validación, idempotencia
│   │   │   ├── negocios.php      embudo
│   │   │   ├── actividades.php   tareas, llamadas, reuniones
│   │   │   ├── campanas.php      campañas, plantillas, listas
│   │   │   ├── envio.php         motor SMTP (cola, reintentos, pausa)
│   │   │   ├── imap.php          rebotes y respuestas
│   │   │   ├── agenda.php        huecos, reservas, cambio de horario, invitación .ics
│   │   │   ├── importador.php    CSV de Zoho
│   │   │   ├── icons.php         iconos SVG en línea
│   │   │   ├── pages/            un controlador por ruta (incl. api/ y api/v1/)
│   │   │   └── views/            plantillas (layout + una por pantalla)
│   │   └── assets/               css, js, img
│   ├── db/                       schema.sql y migraciones (migracion-NNN-….sql)
│   ├── cron.php                  tareas periódicas (CLI y cron-URL)
│   ├── migrar.php                aplica una migración con la config de SU instalación (sólo CLI)
│   ├── reinicio.php              vacía la instalación de pruebas y siembra los datos de prueba (sólo CLI)
│   └── server-config/            config.example.php (el real no se versiona)
├── bin/                          setup.php, deploy, utilidades
├── test/                         batería de regresión + documento maestro de pruebas
├── md/                           esta documentación
├── docs/                         banner y capturas
└── Documentacion a consultar/    material de referencia (CSV de Zoho, etc.)
```

**Cache-busting:** los assets se referencian con `?v=NNN`; al desplegar un cambio se incrementa.

---

## 4. Base de datos

Esquema completo en [`app/db/schema.sql`](../app/db/schema.sql). Grupos de tablas:

| Grupo | Tablas |
|---|---|
| Plataforma | `usuarios`, `app_settings`, `actividad` |
| CRM | `cuentas`, `contactos`, `posibles_clientes`, `etiquetas`, `etiqueta_registro`, `notas`, `adjuntos` |
| Embudo | `negocios`, `embudos`, `etapas` |
| Actividades | `tareas`, `llamadas`, `reuniones`, `reunion_invitados` |
| Agenda | `agenda_tipos`, `agenda_horarios`, `agenda_reservas` |
| Entradas desde la web | `entradas_web` (cada consulta y reserva que llega de intellihelp.tech, con página, canal y consentimiento), `api_limites` (contadores de los límites de envíos) |
| Campañas | `campanas`, `plantillas`, `listas`, `lista_miembros`, `envios`, `eventos_envio`, `desuscripciones` |
| Migración | `import_lotes`, `import_filas` |

Reglas transversales de todas las tablas de negocio: `id` BIGINT autoincremental,
`creado_por` / `creado_en` / `actualizado_en`, `eliminado_en` (borrado lógico) y
`zoho_id` en las que vienen migradas, para poder reimportar sin duplicar.

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
  8 horas de sesión máxima. El aviso del navegador es cosmético; la seguridad la aplica el
  servidor en la siguiente request.
- **CSRF** en todo formulario. **Cookie** `HttpOnly` + `SameSite=Lax` + `Secure` bajo HTTPS.

**Credenciales OAuth:** viven en `config.php`, **fuera del web root**
(`/home/uXXXXXXXXX/crm_config/config.php`). Nunca en el repositorio.

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
páginas públicas —agenda, baja de suscripción— y los pedidos de la web quedan con `h:` y el
hash de la IP con el secreto de la instalación: alcanza para ver que diez pedidos vinieron del
mismo lugar, sin saber de cuál. Con sesión (el equipo) se guarda como siempre.

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
  no hay que salir a crearla aparte.
- **Conversión de posible cliente** (`posible-convertir`): crea Empresa + Contacto + Negocio
  opcional dentro de una transacción. Si la empresa ya existe por nombre, o la persona por
  correo, **las reutiliza**. Notas, adjuntos y actividades se trasladan al contacto. El
  posible cliente no se borra: queda marcado con los ids de lo que generó.
- **Búsqueda global** (`buscar`): una sola caja encuentra personas, empresas, posibles
  clientes y negocios.
- **Exportación a CSV** con BOM y separador `;`, porque el equipo abre los archivos con Excel
  en español.

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
  por vendedor.
- **Atribución de campaña**: cada negocio puede apuntar a la campaña que lo originó, que es
  lo que permite medir qué campaña trae plata de verdad.

---

## 9. Actividades

Tareas, llamadas y reuniones viven en **una sola tabla** porque comparten casi todos los
campos; así la agenda del día y la línea de tiempo de un registro salen de una consulta.

- Vistas rápidas: pendientes, hoy, esta semana, vencidas, completadas.
- El formulario muestra sólo los campos que aplican al tipo elegido (una tarea no tiene
  duración ni resultado; una llamada no tiene hora de fin).
- Completar una actividad actualiza la **fecha de último contacto** del contacto o del
  posible cliente, que es lo que después permite filtrar «sin tocar hace 30 días».
- Cada alta y cada completado queda también en la línea de tiempo del registro asociado.

---

## 10. Agenda propia de reuniones

Reemplazo de Calendly. Detalle del diseño en
[ZOHO-CRM-REFERENCIA.md §10](ZOHO-CRM-REFERENCIA.md#10-reuniones-y-agenda).

| Pieza | Implementación |
|---|---|
| Tipos de reunión | `agenda_tipos` + `agenda_horarios` (horario laboral por día de la semana) |
| Disponibilidad | `agenda_huecos_rango()` — horario laboral **menos** `freeBusy` de Google Calendar **menos** lo ya reservado, con colchón entre reuniones y antelación mínima, en la zona horaria del tipo. La consulta a Google cubre hasta el **final** del último día ofrecido |
| Página pública | `/r/<usuario>/<tipo>` — sin login, elige día y hora y completa sus datos. Se apaga con el módulo Agenda |
| Reserva | `agenda_reservar()` — bloquea el calendario del vendedor (`GET_LOCK` con el nombre de la base), comprueba que el inicio sea un hueco ofrecido y siga libre, y guarda reserva, contacto, empresa y reunión en una transacción. Es todo base de datos: responde en menos de un segundo |
| Alta del evento | `agenda_reserva_completar()` → `gcal_crear_evento()` con Meet y `sendUpdates=none`; después el correo con la invitación `.ics`. Si el proceso se corta, el cron la completa (`avisada_en` vacío) |
| Vuelta al CRM | Contacto por cualquiera de sus tres correos, empresa sin duplicar («ACME S.A.» = «Acme SA», dominio corporativo) y la reunión en Actividades **con la empresa** |
| Avisos | Una sola invitación, la del CRM, con el `iCalUID` de Google. Confirmación, recordatorio 24 h antes (cron) y cancelación, siempre con el mismo `UID` y la secuencia siguiente |
| Cambio de horario | `/r/mover?t=<token>` — el invitado elige otro hueco; el evento de Google se mueve y la invitación nueva reemplaza a la anterior |
| Cancelación | `/r/cancelar?t=<token>` — sirve aunque sea el enlace de un correo viejo (sigue la cadena hasta la reserva vigente); borra el evento en Google y da de baja la actividad |
| Protección | Campo trampa, token de formulario firmado (mínimo 3 s, máximo 2 h; hace de CSRF), consentimiento obligatorio y 3 envíos por hora / 6 por día por hash de IP |
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
| `GET api/v1/disponibilidad` | Los horarios libres de un tipo de reunión (hasta 31 días). Sin calendario responde `503` y la web muestra el formulario |
| `POST api/v1/reserva` | Toma el horario y responde `201`; el evento con Meet y el correo salen después de responder |
| `POST api/v1/entrada` | Consulta sin reunión: contacto, empresa, nota con el mensaje, tarea «Responder consulta web» para el responsable y alerta |

**Seguridad.** Clave con el permiso «Escritura (web)» y un secreto que nunca viaja: cada pedido
va firmado con HMAC-SHA256 sobre la hora, el método, la ruta completa (con la carpeta de la
instalación: una firma de pruebas no sirve en producción) y el cuerpo. Se aceptan 5 minutos de
diferencia de reloj. El secreto se guarda cifrado y se muestra una sola vez. La API exige que
la instalación tenga su `app.secret` propio, de 32 caracteres o más.

**Reintentos sin duplicados.** Cada escritura trae una clave de idempotencia y se guarda en
`entradas_web` **antes** de tocar la agenda. El mismo pedido otra vez recibe la respuesta
original; la misma clave con otro pedido, `409`. Si el horario ya estaba tomado, la web puede
volver a mandar con la misma clave y otro horario. Una consulta igual (mismo correo, servicio y
mensaje) dentro de los 30 minutos queda descartada: es un doble clic o el botón «atrás».

**Límites.** 120 consultas de horarios y 30 escrituras por minuto por clave; por visitante
(hash de la IP que calcula la web), 3 reservas por hora y 6 por día, 5 consultas por hora y 10
por día. Al pasarse responde `429` con `Retry-After`.

**De dónde llegó.** Cada entrada guarda la página, el canal, las UTM, `gclid` y `li_fat_id`, el
referrer, la landing y las visitas. La pantalla **Entradas desde la web** muestra el listado
con filtros y exportación, y arriba las tarjetas por canal con consultas, reuniones agendadas,
realizadas y canceladas: son las métricas que la consultora mira cada mes. Ahí también se elige
quién atiende las consultas (`web_dueno_id`; si no hay nadie elegido, el vendedor del tipo
`diagnostico`, y si tampoco, el primer administrador).

**Datos personales (Ley 25.326).** Consentimiento expreso con fecha, versión y huella del
texto. Los contactos nuevos que entran por la web quedan con «No incluir en campañas» salvo que
pidan novedades; a uno que ya existía no se le cambia nada (ni dueño, ni fuente, ni bajas). La
IP nunca se guarda: sólo su hash. El cron borra los datos personales de las entradas viejas
sin contacto o con error, y conserva el canal y la página para las métricas.

**Sin posibles clientes ni negocios.** Una consulta todavía no es una oportunidad: el negocio lo
abre quien califique la reunión.

---

## 11. Campañas de correo

El motor de Pulsemail llevado al servidor. Diagrama completo en el
[README](../README.md#motor-de-campañas).

| Pieza | Tabla / archivo |
|---|---|
| Plantillas HTML con variables | `plantillas` · `render_plantilla()` |
| Listas de destinatarios | `listas`, `lista_miembros` · cargables desde el filtro del CRM |
| Cola de envío | `envios` (una fila por destinatario, con clave única por campaña+correo) |
| Despacho | `campanas_despachar()`, llamado por `app/cron.php` |
| Envío SMTP | `envio.php` — sin dependencias, multipart texto + HTML |
| Rebotes y respuestas | `imap.php` — lectura IMAP con seguimiento por UID |
| Bajas | `desuscripciones` (lista negra global) + `List-Unsubscribe` |

**Reglas que no se negocian:**

1. **Pausa aleatoria 25–45 s** entre correos de la misma campaña. Fuera de ese rango, o
   caemos en spam o la campaña no termina nunca.
2. **Un correo por campaña y por corrida del cron**: el ritmo lo marca el reloj del servidor.
3. **Nunca dos veces al mismo destinatario** en la misma campaña (clave única en la base).
4. **Se chequea la baja dos veces**: al armar la cola y justo antes de enviar.
5. **`Message-ID` propio y guardado**, única forma de cruzar un rebote o una respuesta con su
   envío.
6. **Texto plano + HTML siempre**: un correo sólo-HTML es señal de spam.
7. **En modo prueba no sale nada** a un destinatario que no esté en la lista blanca interna.

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
- **Ventanas emergentes propias**, nunca `confirm()` del navegador.
- **Colores y espaciados por variables CSS**, nunca hex crudo.
- **Nada de código ni jerga de desarrollador en la UI** fuera de las secciones API y CRON.
- **Una sección nueva copia el diseño de la equivalente ya aprobada** y sólo cambia la lógica.

---

## 13. Despliegue

- **Servidor:** Hostinger, `servidor.del.hosting`. SSH `IP_DEL_SERVIDOR:65002`,
  usuario `uXXXXXXXXX`.
- **Webroot:** `/home/uXXXXXXXXX/domains/intellihelp.tech/public_html/crm`
- **Configuración:** `/home/uXXXXXXXXX/crm_config/config.php` (fuera del webroot, `chmod 600`).
- **Base:** `uXXXXXXXXX_crm` en `localhost`.
- **Cron:** `app/cron.php` cada 5 minutos desde el hPanel (despacho de campañas, IMAP,
  reservas que quedaron sin evento o sin correo, recordatorios de agenda y, una vez por día,
  la anonimización de las entradas de la web y la purga de los contadores de límites).
- **Migraciones:** `python bin/deploy.py migrar app/db/migracion-NNN-….sql [--pruebas]`. Sube el
  `.sql` fuera del webroot y lo aplica `migrar.php` con la configuración de esa instalación: no
  pide credenciales de base y no puede equivocarse de base. Con `--simular` sólo lista las
  sentencias. En producción, siempre con un respaldo previo de la base.
- **Pruebas:** `python bin/deploy.py test [--pruebas]` corre la lógica (`run_tests.php`), la
  interfaz (`ui_test.php`) y el HTTP (`smoke.sh`); contra pruebas suma `api_test.php`, que
  prueba la API v1 de punta a punta y borra lo que creó.
- **Subida:** SFTP/SSH con `bin/deploy.py`. Los assets llevan `?v=NNN`; se incrementa al cambiar.

---

## 14. Reglas del proyecto

1. **Relevar antes de implementar.** Los supuestos que no se pudieron confirmar quedan
   documentados.
2. **Documentación siempre al día** — este documento maestro, el README, el changelog y los
   `.md` de cada módulo, en el mismo cambio que el código.
3. **Un test de regresión por cada defecto** encontrado: falla con el bug, pasa con el arreglo.
4. **Sin secretos ni datos reales en el repositorio.**
5. **Nada se borra de golpe:** borrado lógico + papelera de 60 días. El único borrado real lo
   hace el cron, y sólo sobre lo que ya cumplió el plazo.
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

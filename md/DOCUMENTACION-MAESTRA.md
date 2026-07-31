# DOCUMENTACIÓN MAESTRA · CRM INTELLIHELP

> **Fuente única de verdad del proyecto.** Documenta absolutamente todo lo construido y las
> decisiones tomadas. Se actualiza en **cada** pedido del usuario (ver [Changelog](#15-changelog)).
>
> Documentos complementarios:
> [ZOHO-CRM-REFERENCIA.md](ZOHO-CRM-REFERENCIA.md) · [REQUERIMIENTOS.md](REQUERIMIENTOS.md) ·
> [MIGRACION-ZOHO.md](MIGRACION-ZOHO.md) · [CAMPANAS.md](CAMPANAS.md) · [AGENDA.md](AGENDA.md)

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
│   │   │   ├── crm.php           cuentas, contactos, posibles clientes
│   │   │   ├── negocios.php      embudo
│   │   │   ├── actividades.php   tareas, llamadas, reuniones
│   │   │   ├── campanas.php      campañas, plantillas, listas
│   │   │   ├── envio.php         motor SMTP (cola, reintentos, pausa)
│   │   │   ├── imap.php          rebotes y respuestas
│   │   │   ├── agenda.php        reservas públicas
│   │   │   ├── importador.php    CSV de Zoho
│   │   │   ├── icons.php         iconos SVG en línea
│   │   │   ├── pages/            un controlador por ruta (incl. api/)
│   │   │   └── views/            plantillas (layout + una por pantalla)
│   │   └── assets/               css, js, img
│   ├── db/                       schema.sql y migraciones
│   ├── cron.php                  tareas periódicas (CLI y cron-URL)
│   └── server-config/            config.example.php (el real no se versiona)
├── bin/                          setup.php, deploy, utilidades
├── test/                         batería de regresión + documento maestro de pruebas
├── md/                           esta documentación
├── docs/                         banner y capturas
└── Documentacion a consultar/    material que aporta el cliente (CSV de Zoho, etc.)
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
| Agenda | `agenda_tipos`, `agenda_disponibilidad`, `agenda_reservas` |
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
- **`api`** — cada pedido a la API de lectura.

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
  invento nuestro**: son los campos personalizados que el cliente ya usa en Zoho, y sin ellos
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
| Disponibilidad | `agenda_huecos()` — horario laboral **menos** `freeBusy` de Google Calendar **menos** lo ya reservado, con colchón entre reuniones y antelación mínima |
| Página pública | `/r/<usuario>/<tipo>` — sin login, elige día y hora y completa sus datos |
| Alta del evento | `gcal_crear_evento()` con `conferenceData.createRequest` y `conferenceSolutionKey.type = hangoutsMeet`, `conferenceDataVersion=1` |
| Vuelta al CRM | Crea o enlaza el contacto, deja la reunión en Actividades y registra la reserva |
| Avisos | Confirmación al reservar y recordatorio 24 h antes (por el cron) |
| Cancelación | `/r/cancelar?t=<token>` — borra el evento en Google y da de baja la actividad |

**Detalles que costaron:**

- Google puede devolver la conferencia en estado `pending` **sin el enlace**: se relee el
  evento hasta tres veces antes de darse por vencido.
- Se pide **acceso offline** (`prompt=consent` la primera vez) porque quien reserva es un
  cliente de afuera, no el vendedor: hace falta el `refresh_token` para poder crear el evento
  sin que el vendedor tenga sesión abierta.
- Antes de guardar se **revalida el horario**: entre que el visitante elige y confirma, otro
  pudo haber tomado el mismo hueco.
- Si Google falla, la reserva **no se pierde**: queda en el CRM con el error registrado.

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
  recordatorios de agenda).
- **Subida:** SFTP/SSH con `bin/deploy.py`. Los assets llevan `?v=NNN`; se incrementa al cambiar.

---

## 14. Reglas del proyecto

1. **Investigar a fondo antes de implementar.** Nada se inventa; lo que no se pudo verificar se
   dice explícitamente.
2. **Documentación siempre al día** — este documento maestro, el README, el changelog y los
   `.md` de cada módulo, en el mismo cambio que el código.
3. **Un test de regresión por cada defecto** encontrado: falla con el bug, pasa con el arreglo.
4. **Sin secretos ni datos reales en el repositorio.**
5. **Nada se borra de golpe:** borrado lógico + papelera de 60 días. El único borrado real lo
   hace el cron, y sólo sobre lo que ya cumplió el plazo.
6. **Nada de datos de prueba en producción.** La base arranca vacía; los datos los carga el equipo.
7. **El trabajo del usuario nunca se pierde:** la campaña retoma donde quedó, los formularios
   guardan borrador.
8. **Sin rastros de IA.** Código de producción, comentarios en castellano rioplatense que
   explican el *porqué*, no el *qué*.
9. **Cada cambio se aplica en todo por igual:** si algo mejora en un módulo, se replica en los
   equivalentes.

---

## 15. Changelog

> Cada pedido del usuario se registra acá, del más nuevo al más viejo.

- **2026-07-30** — Primera versión completa en producción sobre `crm.intellihelp.tech`:
  núcleo CRM (empresas, contactos, posibles clientes con conversión), embudo con Kanban y
  pronóstico, actividades unificadas, campañas de correo con cola + cron + IMAP,
  agenda propia con Google Calendar y Meet, importador del CSV de Zoho, papelera, auditoría,
  API de lectura y reportes. Batería de 85 pruebas unitarias y 48 comprobaciones HTTP, todas
  en verde. Repositorio publicado en GitHub.
- **2026-07-30** — Arranque del proyecto. Investigación a fondo de Zoho CRM y del CSV real del
  cliente (13.573 contactos, 71 columnas): se documenta el mapa 1:1 en
  [ZOHO-CRM-REFERENCIA.md](ZOHO-CRM-REFERENCIA.md) con marcas de uso real para que el cliente
  indique qué sobra. Se crean este documento maestro, los requerimientos permanentes y la
  estructura del repositorio. Decisiones cerradas con el cliente: login 100 % Google Workspace,
  campañas por cola + cron + IMAP completo, SMTP de Gmail/Workspace, agenda propia con Google
  Calendar y Meet automático en reemplazo de Calendly.

---

*Se mantiene al día con cada cambio del sistema.*

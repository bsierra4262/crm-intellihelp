# Integración de la web con la agenda del CRM

> Documento de diseño. Fecha: 2026-10-30.
> Alcance: **sólo reuniones** (Agenda y Actividades) y **la entrada de datos desde la web**
> (`intellihelp.tech/diagnostico/` y el formulario «Agendá una reunión»). Campañas, negocios,
> posibles clientes e importador **no se tocan**.
> Relacionados: [DOCUMENTACION-MAESTRA.md §10](DOCUMENTACION-MAESTRA.md#10-agenda-propia-de-reuniones),
> [REQUERIMIENTOS.md § Agenda](REQUERIMIENTOS.md), embudo de la consultora de marketing
> (`NEW-PAGE-ITH/DOCS/marketing/embudo-ventas-intellihelp.pdf`, etapa 03 «Convertir»).

---

## Índice

1. [Qué tiene que pasar](#1-qué-tiene-que-pasar)
2. [Recorrido de punta a punta](#2-recorrido-de-punta-a-punta)
3. [Decisiones](#3-decisiones)
4. [Lo que hay que corregir en la agenda actual](#4-lo-que-hay-que-corregir-en-la-agenda-actual)
5. [Contrato de la API v1](#5-contrato-de-la-api-v1)
6. [Qué datos viajan y dónde quedan](#6-qué-datos-viajan-y-dónde-quedan)
7. [Migración 008](#7-migración-008)
8. [CRM: funciones y firmas](#8-crm-funciones-y-firmas)
9. [Web: intermediario, configuración y pantalla](#9-web-intermediario-configuración-y-pantalla)
10. [Datos personales (Ley 25.326)](#10-datos-personales-ley-25326)
11. [Pruebas](#11-pruebas)
12. [Paso a producción](#12-paso-a-producción)
13. [Orden de implementación](#13-orden-de-implementación)
14. [Riesgos y pendientes](#14-riesgos-y-pendientes)

---

## 1. Qué tiene que pasar

Lo que pide el embudo, etapa 03, en cuatro pasos:

1. **Elige día y hora** con los horarios que Carlos tiene libres, en la misma landing.
2. **Deja sus datos**: nombre, empresa y qué servicio le interesa.
3. **Recibe la confirmación** por mail, con recordatorio antes de la reunión y el enlace de Meet.
4. **Se reúne con Carlos**, que llega con el contexto ya leído.

Y del lado nuestro: en el CRM queda **todo registrado** —contacto, empresa, reunión— y se sabe
**desde qué página y por qué canal llegó** (Google, LinkedIn, anuncio, IA, mail, directo), para
poder medir «reuniones agendadas», «reuniones realizadas» y «canal de origen», que son las
métricas que la consultora va a mirar cada mes.

Quien no reserva y manda el formulario común también tiene que quedar en el CRM, con alguien
responsable de contestarle.

---

## 2. Recorrido de punta a punta

```
Navegador (www.intellihelp.tech/diagnostico/)
   │  GET  /api/agenda.php?desde=…&hasta=…        (mismo origen, sin claves)
   │  POST /api/agenda.php                         (reserva)
   │  POST /api/contact.php                        (consulta sin reserva, como hoy)
   ▼
PHP de la web (public/api/agenda.php · contact.php · _crm.php)
   │  honeypot, límite por IP, validación, caché de horarios 60 s
   │  firma HMAC con la clave de la web (config fuera del webroot)
   ▼
CRM  intellihelp.tech/crm/api/v1/…   (servidor a servidor)
   │  GET  disponibilidad   → agenda_huecos_rango()  → freeBusy de Google + reservas del CRM
   │  POST reserva          → agenda_reservar()      → contacto + empresa + reunión + entrada
   │                          (responde) → agenda_reserva_completar() → evento en Google con Meet
   │                                                                 + mail con la invitación .ics
   │  POST entrada          → entrada_web_consulta() → contacto + empresa + nota + tarea + entrada
   ▼
Base del CRM: contactos · cuentas · actividades · agenda_reservas · notas · entradas_web
Google Calendar de Carlos: el evento, con Meet, sin datos de marketing
Mail al invitado: confirmación + invitación .ics + enlaces para cambiar el horario o cancelar
Cron: recordatorio 24 h antes y reintento de lo que haya quedado sin completar
```

La web **nunca** habla con Google ni guarda la clave del CRM en el navegador. El navegador sólo
conoce `/api/agenda.php` y `/api/contact.php`, que son del mismo sitio.

Si el CRM no responde, la landing muestra el formulario de siempre: la consulta sale por mail a
`info@` como hoy y queda en una cola para entrar al CRM cuando vuelva.

---

## 3. Decisiones

### 3.1 La agenda es la del CRM

La agenda del CRM ya calcula los huecos sobre la ocupación real del Google Calendar del vendedor
(`agenda_huecos()` → `gcal_ocupado()` → `freeBusy`), crea el evento con Meet y deja la reunión en
Actividades. Es la única opción que cumple a la vez:

- **REQUERIMIENTOS → Agenda:** herramienta propia, sin Calendly ni Cal.com, gratis y sin límites.
- **Registro en el CRM con el canal de origen.** La página de citas de Google no avisa nada a la
  página que la embebe (ver `docs/marketing/medicion-embudo.md`): no hay forma de saber que
  reservó, ni desde dónde llegó, ni de dejarlo en el CRM.
- **Diseño propio.** El selector de horarios se dibuja en la landing con los estilos del sitio, no
  en un iframe ajeno. Tampoco hace falta abrir la CSP a terceros.

### 3.2 Reserva desde la web: igual que la agenda pública, más la entrada

Se reutiliza `agenda_reservar()`, mejorado (ver §4 y §8.2):

- **Contacto**: se busca por correo (`email`, `email2`, `email3`). Si existe, sólo se completan
  los huecos (teléfono, empresa); **no se pisa nada**, ni el dueño ni la fuente. Si no existe se
  crea con fuente **«Sitio web»** y dueño = vendedor del tipo de reunión.
- **Empresa**: `cuenta_asegurar_web()`, una versión de `cuenta_asegurar()` que además reconoce
  «ACME S.A.» y «Acme SA» como la misma empresa y usa el dominio del correo corporativo. No se
  cambia `cuenta_asegurar()` porque la usa el importador, que queda fuera de alcance.
- **Reunión**: actividad tipo `reunion` con `contacto_id` **y `cuenta_id`** (hoy no se guarda la
  empresa), dueño el vendedor del tipo de reunión.
- **Entrada**: una fila en `entradas_web` con la página, el canal, las UTM y el consentimiento.

### 3.3 Consulta sin reserva

`POST api/v1/entrada` (lo manda `contact.php` además del mail a `info@`):

- contacto + empresa con el mismo criterio de §3.2 (núcleo, sin pisar datos);
- **nota** en el contacto con el mensaje y el servicio elegido;
- **tarea** «Responder consulta web: <empresa>», prioridad Alta, vence el próximo día hábil a las
  12 h, a nombre del **responsable de las consultas web**: ajuste nuevo `web_dueno_id`; si está
  vacío, el vendedor del tipo de reunión `diagnostico`; si tampoco existe, el primer administrador
  activo;
- **alerta** en la campana para ese responsable (tipo nuevo `web`);
- fila en `entradas_web`.

**Sin posibles clientes ni negocios.** El módulo de posibles clientes no está en uso y abrir un
negocio por cada consulta llenaría el embudo de cosas que todavía no son oportunidades. Cuando
Carlos califique la reunión, el negocio lo abre él, como hoy.

### 3.4 Una sola invitación, y la manda el CRM

Hoy salen **dos**: `gcal_crear_evento()` crea el evento con `sendUpdates=all` (Google le manda su
invitación al cliente) y `agenda_avisar()` manda además el mail del CRM con su propia invitación
`.ics`, con otro identificador. Resultado: dos mails, y en el calendario del cliente dos eventos
para la misma reunión; al cancelar, dos avisos.

Se queda **la del CRM** como única, por lo que ya explica el propio código (`agenda_ics()`,
comentario de la línea 399): funciona aunque Google esté caído —que es justo lo que pasó con la
API de Calendar deshabilitada—, sale con el diseño de la empresa, en castellano, con los enlaces
propios para cambiar el horario y cancelar, y el organizador coincide con el remitente, que es lo
que Outlook exige para mostrar los botones de aceptar.

Cómo queda:

- El evento de Google se crea, se mueve y se borra con **`sendUpdates=none`**. Google no manda nada.
- El cliente **sigue como invitado** del evento de Google (ajuste `agenda_invitado_en_google`,
  por defecto `1`): así entra a la Meet sin pedir permiso para unirse.
- La `.ics` del CRM usa como `UID` el **`iCalUID` que devuelve Google** al crear el evento. Para
  quien usa Gmail o Google Workspace, la invitación del mail y el evento que ya tiene en el
  calendario son el mismo: no se duplica. Si Google falló, se usa el `UID` propio
  (`reserva-<id>-<token>@intellihelp.tech`, el de hoy) y el mail sale igual.
- El `UID` y la secuencia se guardan en la reserva (`ics_uid`, `ics_secuencia`). Al cambiar el
  horario se manda un `REQUEST` con **el mismo `UID`** y la secuencia +1; al cancelar, `CANCEL`
  con la secuencia +1. Hoy el cambio de horario genera un `UID` nuevo (la reserva nueva tiene
  otro id y otro token) y la reunión vieja queda en el calendario del cliente.
- Plan B, si en las pruebas Gmail igual duplicara: `agenda_invitado_en_google = 0`. El cliente
  deja de ser invitado en Google, recibe sólo la `.ics` y Carlos lo admite en la Meet. Se cambia
  desde la base, sin tocar código.

### 3.5 Contrato de API servidor a servidor

Detalle en §5. Resumen: `GET api/v1/disponibilidad`, `POST api/v1/reserva`, `POST api/v1/entrada`.
Clave en `api_tokens` con el alcance nuevo **`escritura`** y un **secreto HMAC** guardado con
`cifrar()`. Cada pedido va firmado con marca de tiempo (±300 s) y clave de idempotencia. Límites
por clave y por hash de IP del visitante. Cada pedido queda en la auditoría (`log_api`) sin datos
personales.

La web nunca expone la clave: la usan sólo los PHP de la web, que la leen de un archivo fuera del
webroot.

### 3.6 La reserva responde rápido; Google y el mail van después

Crear el evento con Meet puede tardar varios segundos (Google devuelve la conferencia `pending` y
se relee hasta tres veces) y el mail otros tantos. Si la web esperara todo eso, un corte por
tiempo dejaría al visitante con un error y la reunión tomada.

Por eso `agenda_reservar()` se parte en dos:

- `agenda_reservar()`: valida, bloquea el calendario del vendedor, comprueba el hueco, inserta la
  reserva, el contacto, la empresa y la reunión. Es todo base de datos: menos de un segundo.
- `agenda_reserva_completar()`: evento de Google con Meet, mail con la `.ics`, alerta.

La API responde después del primer paso y corre el segundo con `finish_request()`. Si el proceso
se corta, el cron completa las reservas confirmadas con `avisada_en` vacío y más de 2 minutos de
antigüedad. La página pública `/r/` sigue llamando a las dos seguidas, porque muestra el enlace
de Meet en la pantalla de confirmación.

### 3.7 Datos que viajan

Nombre, empresa, email, teléfono, servicio, mensaje, consentimiento (fecha, versión y texto),
página de origen, canal, `utm_source/medium/campaign/term/content`, `gclid`, `li_fat_id`,
referrer, landing, cantidad de visitas a la landing y, para la reserva, tipo de reunión e inicio.
**Al evento de Google sólo van el nombre y la empresa**, en el título. Ni el mensaje, ni las UTM,
ni el teléfono. Detalle en §6.

### 3.8 Agenda pública `/r/`

Pasa a tener: honeypot, límite de envíos por hash de IP, token de formulario firmado (sirve de
CSRF y de tiempo mínimo de llenado), consentimiento, bloqueo por vendedor (`GET_LOCK`) alrededor
del choque y la inserción, apagado real cuando el módulo Agenda está apagado, y un enlace
`/r/mover?t=` para que el invitado cambie el horario solo, además de cancelar
(REQUERIMIENTOS → Agenda: «enlaces propios para reprogramar y cancelar»; hoy sólo existe cancelar).

### 3.9 En la web

`public/api/agenda.php` (intermediario del mismo origen, caché de horarios de 60 s, honeypot,
límite por IP) y `contact.php`, que **sigue mandando el mail a `info@`** y además manda la
entrada firmada al CRM con 5 s de tiempo máximo; si falla, queda en una cola fuera del webroot y
se reintenta. Configuración fuera del webroot. `site.json` → `diagnostico.calendario = "crm"`.

---

## 4. Lo que hay que corregir en la agenda actual

Encontrado leyendo `agenda.php`, `gcal.php`, `pages/r.php` y `pages/reservar.php`. Cada uno
lleva su prueba de regresión (§11).

| # | Dónde | Qué pasa hoy | Corrección |
|---|---|---|---|
| 1 | `agenda.php:166-189` | `agenda_reservar()` acepta **cualquier** `inicio` que llegue por POST: un domingo a las 3, o encima de una reunión que Carlos tiene en Google. Sólo controla choque contra reservas del CRM y sin el margen. | `agenda_hueco_libre()`: el inicio tiene que caer en un hueco del tipo (horario laboral, paso de la duración, antelación, ventana) y estar libre en Google y en el CRM **con el margen**. |
| 2 | `agenda.php:185-201` | Entre el `SELECT` del choque y el `INSERT` no hay bloqueo: dos pedidos simultáneos toman el mismo horario. | `GET_LOCK(CONCAT(DATABASE(), ':agenda:', usuario_id), 10)` alrededor de comprobar e insertar. El nombre lleva la base porque producción y pruebas comparten el servidor de MariaDB y los bloqueos son del servidor. |
| 3 | `agenda.php:113,123,142` | `freeBusy` se pide hasta el **comienzo** del último día, pero el bucle ofrece también ese día: los horarios del último día se publican sin mirar Google. | La consulta llega hasta el final del último día ofrecido (`agenda_ventana()`). |
| 4 | `gcal.php:177,216,226` | `sendUpdates=all` al crear, mover y borrar: dos invitaciones. | `sendUpdates=none` por defecto (§3.4). |
| 5 | `agenda.php:211` | La descripción del evento de Google lleva el comentario del cliente. | Descripción fija: «Reunión agendada desde intellihelp.tech.». Datos internos en `extendedProperties.private` (`crm_reserva_id`), que sólo ve el dueño del calendario. |
| 6 | `agenda.php:210` | `$d['empresa']` sin `?? ''`: si no viene, aviso de PHP que termina en la auditoría como error. | `($d['empresa'] ?? '')`. |
| 7 | `agenda.php:222-249` | El contacto se busca sólo por `email`; si existe no se le asocia la empresa; la reunión no lleva `cuenta_id`. | `contacto_asegurar_web()` + `cuenta_asegurar_web()` y `cuenta_id` en la actividad. |
| 8 | `agenda.php:298-357` + `417` | Al cambiar el horario se crea una reserva nueva con otro token y la `.ics` sale con otro `UID`: el cliente queda con las dos reuniones en el calendario. El enlace de cancelar del primer mail deja de servir. | `ics_uid` y `ics_secuencia` se copian a la reserva nueva; `reprogramada_de` encadena las dos y `agenda_reserva_vigente()` sigue la cadena desde cualquier token viejo. |
| 9 | `pages/r.php:13-22` | Cancelar por POST sin ningún token de formulario. | Token de formulario firmado (§8.2). |
| 10 | `index.php` + `modulos.php` | `r` y `reservar` no están en el módulo Agenda: con la Agenda apagada en producción, `/r/<usuario>/<tipo>` responde igual si hay un tipo cargado. | El controlador público comprueba `modulo_activo('agenda')` y, si está apagada, muestra «Agenda no disponible» con 404. Se hace en el controlador y no en `modulos()` para que la pantalla de error sea la pública y no la del sistema. |
| 11 | `pages/reservar.php` | Sin honeypot ni límite: un bot puede llenar el calendario de Carlos. | Honeypot `sitio_web`, token de formulario (mínimo 3 s, máximo 2 h), 3 reservas por hora y 6 por día por hash de IP. |
| 12 | `actividad.php:42` | La auditoría guarda la **IP en claro** de cualquier visitante de las páginas públicas. | Sin sesión, se guarda `h:` + los primeros 40 caracteres de `ip_hash()`. Con sesión (el equipo), como hoy. |
| 13 | `bin/deploy.py:175-187` | `schema --pruebas` importa en **la base de producción**: el nombre de la base sale de `CRM_DB_NAME`, con producción como valor por defecto, y no mira `DESTINO`. | El valor por defecto pasa a ser `DESTINOS[DESTINO]["db"]`. Además, comando nuevo `migrar` (§7.2) que no necesita credenciales de base. |
| 14 | Pruebas | En `crm-pruebas` **no hay SMTP configurado**: `agenda_avisar()` devuelve `false` y no sale ningún mail. | Configurarlo desde Ajustes antes de probar (se carga a mano: lleva la contraseña de aplicación). Sin eso no se puede probar la confirmación. |

---

## 5. Contrato de la API v1

Base: `https://intellihelp.tech/crm/api/v1/` (producción) y
`https://intellihelp.tech/crm-pruebas/api/v1/` (pruebas). Son rutas del front controller
(`index.php`), públicas en el sentido de que no usan sesión: la autenticación es la firma.

### 5.1 Clave y secreto

- Se crean en **API** (`api-keys`), sólo administradores, eligiendo el permiso **«Escritura (web)»**.
- Clave: `crm_` + 40 hex (`random_bytes(20)`). Se guarda sólo su SHA-256 (`token_hash`), como hoy.
- Secreto: 64 hex (`random_bytes(32)`). Se guarda con `cifrar()` en `api_tokens.secreto_cifrado`
  y se descifra sólo para verificar la firma.
- Las dos se muestran **una única vez** en pantalla. No se mandan por mail ni quedan en la auditoría.
- Una clave `escritura` sólo sirve para `api/v1/*`. `api/estado` sigue aceptando cualquier clave
  activa, como hoy.
- Si `app.secret` de la instalación tiene menos de 32 caracteres, la API v1 responde
  `503 configuracion_incompleta` (`crm_secret()` tiene un respaldo débil derivado de la base que
  no sirve para cifrar el secreto).
- Si cambia `app.secret`, el secreto cifrado deja de poder leerse: hay que generar otra clave.
- `app/reinicio.php` borra `api_tokens`: después de reiniciar pruebas hay que generar otra clave
  y actualizar la configuración local de la web.

### 5.2 Cabeceras

| Cabecera | Obligatoria | Contenido |
|---|---|---|
| `X-ITH-Key` | siempre | la clave `crm_…` |
| `X-ITH-Timestamp` | siempre | segundos Unix; se acepta ±300 s respecto del reloj del CRM |
| `X-ITH-Signature` | siempre | `hex(HMAC-SHA256(secreto, timestamp + "\n" + MÉTODO + "\n" + ruta + "\n" + idempotencia + "\n" + hash_ip + "\n" + cuerpo))` |
| `X-ITH-Idempotency` | POST | `^[A-Za-z0-9_-]{16,80}$`, única por entrada |
| `X-ITH-Client-IP-Hash` | POST | 64 hex: hash de la IP del visitante, calculado por la web con su propia sal |
| `Content-Type` | POST | `application/json` (otro → `415`) |

Definición exacta de la firma:

- `MÉTODO` en mayúsculas (`GET`, `POST`).
- `ruta` = **la ruta y la consulta tal cual van en la línea del pedido**, incluida la carpeta de
  la instalación. Del lado del CRM es `$_SERVER['REQUEST_URI']` sin tocar. Ejemplos:
  `/crm/api/v1/reserva` · `/crm-pruebas/api/v1/disponibilidad?tipo=diagnostico&vendedor=bsierra&desde=2026-11-02&hasta=2026-11-15`.
  Una firma de pruebas no sirve en producción, y al revés.
- `idempotencia` y `hash_ip` = los valores de `X-ITH-Idempotency` y `X-ITH-Client-IP-Hash` tal
  cual viajan; en `GET`, cadenas vacías. Van firmados porque de ellos dependen la idempotencia y
  los límites por visitante: si quedaran afuera, un pedido capturado se podría reenviar dentro de
  los ±300 s cambiándolos, como si fuera otro pedido de otro visitante.
- `cuerpo` = los bytes exactos del cuerpo (`php://input`); en `GET`, cadena vacía.
- Comparación con `hash_equals()`.
- Una escritura (`POST`) se acepta una sola vez por firma: el CRM guarda la firma en
  `api_limites` (`sig:<firma>`, 10 minutos) y la misma firma otra vez responde
  `401 firma_repetida`. Cada reintento legítimo de la web se vuelve a firmar con hora nueva.

### 5.3 Límites

Tabla `api_limites` (§7). Al pasarse: `429 demasiados_pedidos` con `Retry-After` en segundos.

| Clave | Límite |
|---|---|
| por clave, `disponibilidad` | 120 por minuto |
| por clave, `reserva` + `entrada` | 30 por minuto |
| por hash de IP, `reserva` | 3 por hora · 6 por día |
| por hash de IP, `entrada` | 5 por hora · 10 por día |
| `/r/` pública, por hash de IP, reservar/mover/cancelar | 3 por hora · 6 por día |
| `/r/` pública, por hash de IP, páginas (GET) | 30 cada 10 minutos |
| reservas desde afuera (web y `/r/`), por correo | 2 reuniones futuras confirmadas (ajuste `agenda_max_por_correo`); la parte `+algo` de la dirección no cuenta → `429 limite_reservas` |
| reservas desde afuera (web y `/r/`), por vendedor | 15 por día (ajuste `agenda_tope_diario`); al llegar, alerta de sistema al vendedor → `429 limite_reservas` |

- El hash de IP es de la IP en IPv4 y de la red `/64` en IPv6 (los dos lados: `ip_red()` en el
  CRM, `crm_ip_red()` en la web).
- La IP es siempre `REMOTE_ADDR` (`ip_confiable()`): el CDN de Hostinger ya deja ahí la del
  visitante, y `X-Forwarded-For` o `Client-IP` los escribe el que hace el pedido. Sólo si
  `REMOTE_ADDR` está en `app.proxies_confiables` (configuración) se lee `X-Forwarded-For`, de
  derecha a izquierda.
- Los tokens de formulario de `/r/` sirven una sola vez (`ft:<nonce>` en `api_limites`).
- Los horarios que se muestran (API y `/r/`) usan una caché de 60 s de la consulta a Google
  (`STORAGE/cache-gcal`, sólo franjas ocupadas). La verificación final de una reserva consulta a
  Google sin caché y con 6 s como máximo: si Google tarda más, `503 sin_calendario` antes de que
  la web corte a los 10 s.

### 5.4 Respuestas

Siempre JSON UTF-8, con `Cache-Control: no-store` y `X-Content-Type-Options: nosniff`.

```json
{ "ok": false, "error": "horario_tomado", "mensaje": "Justo tomaron ese horario. Elegí otro, por favor." }
```

| Código | `error` | Cuándo |
|---|---|---|
| 200 | — | consulta correcta; o pedido repetido con la misma idempotencia (`"repetida": true`) |
| 201 | — | reserva o entrada creada |
| 400 | `json_invalido` · `parametros_invalidos` | cuerpo que no es un objeto JSON; fechas mal escritas |
| 401 | `falta_clave` · `clave_invalida` · `firma_invalida` · `firma_repetida` · `hora_fuera_de_rango` | autenticación |
| 403 | `sin_permiso` | la clave no tiene alcance `escritura` o no tiene secreto |
| 404 | `tipo_inexistente` | no hay un tipo de reunión activo con ese slug |
| 405 | `metodo_no_permitido` | método equivocado (con `Allow`) |
| 409 | `horario_tomado` · `tipo_ambiguo` · `en_proceso` · `idempotencia_reusada` | ver cada endpoint |
| 413 | `cuerpo_demasiado_grande` | más de 16 KB |
| 415 | `tipo_de_contenido` | POST sin `application/json` |
| 422 | `datos_invalidos` · `falta_consentimiento` | con `"campos": {"email": "…"}` |
| 429 | `demasiados_pedidos` · `limite_reservas` | §5.3 (`limite_reservas`: topes por correo o del día, con `mensaje` para la persona) |
| 503 | `modulo_apagado` · `sin_calendario` · `migracion_pendiente` · `configuracion_incompleta` | el CRM no puede atender eso ahora |
| 500 | `error_interno` | con `"codigo": "ERR-XXXXXX"` (el de `log_error()`); nunca la traza |

Cada pedido deja una línea con `log_api()`: acción (`api/v1/reserva`), actor (nombre de la
clave), código de respuesta y, cuando corresponde, `entrada_id`. **Nunca** nombre, correo,
teléfono ni mensaje.

### 5.5 `GET api/v1/disponibilidad`

Parámetros:

| Parámetro | Formato | Por defecto |
|---|---|---|
| `tipo` | slug `^[a-z0-9-]{1,60}$` | obligatorio |
| `vendedor` | parte local del correo del vendedor `^[a-z0-9._-]{1,64}$` | opcional; si hay dos tipos con el mismo slug y no viene → `409 tipo_ambiguo` |
| `desde` | `Y-m-d` | hoy |
| `hasta` | `Y-m-d` | `desde` + 14 días |

El rango máximo es de 31 días y se recorta a la ventana del tipo (`ventana_dias`). Requiere el
módulo Agenda en marcha (`503 modulo_apagado`). Si no se pudo leer el calendario de Google,
`503 sin_calendario`: la web muestra el formulario. Nunca se publican horarios sin haber visto
el calendario (mismo criterio que `agenda_huecos()`).

```json
{
  "ok": true,
  "zona": "America/Argentina/Buenos_Aires",
  "tipo": { "slug": "diagnostico", "nombre": "Reunión de diagnóstico", "duracion_min": 30,
            "con_meet": true, "vendedor": "Carlos Baldelli" },
  "desde": "2026-11-02",
  "hasta": "2026-11-15",
  "dias": [
    { "fecha": "2026-11-03",
      "horarios": [ { "inicio": "2026-11-03T09:30:00-03:00", "ts": 1793709000 },
                    { "inicio": "2026-11-03T10:00:00-03:00", "ts": 1793710800 } ] }
  ],
  "generado": "2026-11-01T10:00:00-03:00"
}
```

Días sin horarios no aparecen. `ts` es el inicio en segundos Unix; `inicio`, el mismo momento
en hora de Argentina.

### 5.6 `POST api/v1/reserva`

```json
{
  "tipo": "diagnostico",
  "vendedor": "cbaldelli",
  "inicio": "2026-11-03T09:30:00-03:00",
  "persona": {
    "nombre": "Juana Prueba",
    "empresa": "Empresa de Prueba SRL",
    "email": "bsierra@intellihelp.tech",
    "telefono": "+54 11 5555-0000",
    "servicio": "Soporte IT, mesa de ayuda u outsourcing IT",
    "mensaje": "Texto libre."
  },
  "consentimiento": {
    "aceptado": true,
    "fecha": "2026-11-01T10:00:00-03:00",
    "version": "web-2026-11",
    "texto": "Acepto que Intellihelp use estos datos para responder mi consulta y coordinar la reunión, según el aviso de privacidad."
  },
  "novedades": false,
  "origen": {
    "pagina": "/diagnostico/",
    "canal": "Google Ads",
    "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "diagnostico-it",
    "utm_term": "", "utm_content": "",
    "gclid": "", "li_fat_id": "",
    "referrer": "https://www.google.com/",
    "landing": "/servicios/soporte-it/",
    "visitas_landing": 2
  }
}
```

- `inicio` acepta ISO 8601 con desplazamiento o un entero (segundos Unix).
- Validación en §6.2. Claves desconocidas → `422 datos_invalidos` (validación estricta).
- Idempotencia: se inserta primero la fila de `entradas_web` con `estado = 'procesando'` y la
  clave única. Si la clave ya existe: con el mismo `cuerpo_hash` y `estado = 'ok'` →
  `200` con la respuesta original y `"repetida": true`; con `estado = 'procesando'` →
  `409 en_proceso`; con otro cuerpo → `409 idempotencia_reusada`.
- `409 horario_tomado` cuando el hueco ya no está libre (otro lo tomó, o Carlos agregó algo en
  su calendario). La web vuelve a pedir los horarios.
- Requiere el módulo Agenda en marcha.

Respuesta `201`:

```json
{
  "ok": true,
  "repetida": false,
  "entrada_id": 128,
  "reserva": { "id": 57, "inicio": "2026-11-03T09:30:00-03:00", "fin": "2026-11-03T10:00:00-03:00",
               "duracion_min": 30, "estado": "confirmada" },
  "mensaje": "Reunión confirmada. Te mandamos un mail con el enlace de la videollamada."
}
```

No se devuelven el token de la reserva, el enlace de Meet ni los enlaces de cancelar o cambiar el
horario: esos llegan sólo al correo de quien reservó, que así demuestra que es suyo.

### 5.7 `POST api/v1/entrada`

Mismo cuerpo que la reserva **sin** `tipo`, `vendedor` ni `inicio`. Siempre es una consulta.
No depende de ningún módulo: contactos y empresas son del núcleo.

Duplicados: si en los últimos 30 minutos ya entró una consulta con el mismo correo, el mismo
servicio y el mismo mensaje, la nueva queda `descartada` y se responde `200` con
`"repetida": true` (doble clic, botón atrás).

Respuesta `201`: `{ "ok": true, "repetida": false, "entrada_id": 129 }`.

---

## 6. Qué datos viajan y dónde quedan

### 6.1 Destino de cada dato

| Dato | `entradas_web` | Contacto | Empresa | Actividad | Reserva | Evento de Google | Mail al invitado |
|---|---|---|---|---|---|---|---|
| nombre | sí | nombre/apellido | — | asunto | `invitado_nombre` | título | saludo |
| empresa | sí | — | `cuentas.nombre` | `cuenta_id` | `invitado_empresa` | título | — |
| email | sí | `email` | — | invitados | `invitado_email` | invitado (§3.4) | destinatario |
| teléfono | sí | `telefono` si estaba vacío | — | — | `invitado_telefono` | **no** | — |
| servicio | sí | — | — | descripción | `comentario` | **no** | — |
| mensaje | sí | nota (consulta) | — | descripción | `comentario` | **no** | sí (es suyo) |
| consentimiento | fecha, versión, hash del texto | — | — | — | fecha y versión | **no** | — |
| página, canal, UTM, gclid, li_fat_id, referrer, landing, visitas | sí | — | — | una línea en la descripción | — | **no** | — |
| hash de IP | sí | — | — | — | — | **no** | — |

La línea de origen en la descripción de la actividad la arma `entrada_web_resumen()`, por
ejemplo: «Llegó desde /diagnostico/ · canal Google Ads · campaña diagnostico-it (google / cpc) ·
segunda visita a la landing». Es interna del CRM.

### 6.2 Validación (igual en la web y en el CRM)

| Campo | Regla |
|---|---|
| `nombre` | 2 a 160 caracteres, una línea, al menos una letra |
| `empresa` | 1 a 200, una línea (obligatoria desde la web) |
| `email` | `FILTER_VALIDATE_EMAIL`, hasta 190, se normaliza a minúsculas |
| `telefono` | opcional, `telefono_valido()`, hasta 60 |
| `servicio` | opcional, una línea, hasta 120 (el CRM no conoce la lista de la web y no la impone) |
| `mensaje` | opcional, hasta 3000, se quitan caracteres de control salvo salto de línea y tabulación |
| `consentimiento.aceptado` | `true` o `422 falta_consentimiento` |
| `consentimiento.version` | `^[a-z0-9._-]{3,40}$` |
| `consentimiento.fecha` | ISO 8601, a no más de 24 h de la hora del CRM |
| `consentimiento.texto` | hasta 2000; se guarda su SHA-256, no el texto |
| `novedades` | booleano, por defecto `false` |
| `origen.pagina`, `origen.landing` | `^/[A-Za-z0-9/_.-]{0,199}$`, sin `..` ni `//` |
| `origen.canal` | una línea, hasta 80 |
| `origen.utm_*` | una línea, hasta 150 cada una |
| `origen.gclid`, `origen.li_fat_id` | `^[A-Za-z0-9._~-]{1,200}$` o vacío |
| `origen.referrer` | URL `http(s)` hasta 300 o vacío |
| `origen.visitas_landing` | entero 0 a 999 |

Los campos de origen nunca hacen fallar el pedido: lo que no cumple el formato se guarda vacío,
igual que hace hoy `contact.php`. Los de la persona y el consentimiento sí.

---

## 7. Migración 008

### 7.1 `app/db/migracion-008-entradas-web.sql`

Idempotente: se puede correr varias veces. Va además incorporada a `schema.sql` para las
instalaciones nuevas.

```sql
-- ============================================================
--  Entradas desde la web: consultas y reservas que llegan de intellihelp.tech.
--
--  Cada formulario que entra deja una fila acá, con la página desde la que se
--  mandó y el canal por el que llegó la persona. Es lo que permite contestar
--  «de dónde salen las reuniones», que hasta ahora sólo se podía adivinar.
--
--  La fila se crea ANTES de procesar el pedido, con la clave de idempotencia
--  única: si la web reintenta porque se le cortó la conexión, el segundo pedido
--  encuentra el primero y no se duplica la reunión.
--
--  Es idempotente: se puede correr muchas veces.
-- ============================================================

CREATE TABLE IF NOT EXISTS `entradas_web` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `idempotencia`    VARCHAR(80)  NOT NULL,
  `cuerpo_hash`     CHAR(64)     DEFAULT NULL,   -- para detectar la misma clave con otro pedido
  `token_id`        BIGINT UNSIGNED DEFAULT NULL, -- NULL = agenda pública /r/
  `tipo`            ENUM('consulta','reserva') NOT NULL,
  `estado`          ENUM('procesando','ok','error','descartada') NOT NULL DEFAULT 'procesando',
  `error`           VARCHAR(200) DEFAULT NULL,
  `respuesta`       TEXT         DEFAULT NULL,   -- la respuesta original, para los reintentos
  -- Persona
  `nombre`          VARCHAR(160) NOT NULL,
  `empresa`         VARCHAR(200) DEFAULT NULL,
  `email`           VARCHAR(190) NOT NULL,
  `telefono`        VARCHAR(60)  DEFAULT NULL,
  `servicio`        VARCHAR(120) DEFAULT NULL,
  `mensaje`         TEXT         DEFAULT NULL,
  `novedades`       TINYINT(1)   NOT NULL DEFAULT 0,
  -- Consentimiento (Ley 25.326): cuándo, qué versión del texto y su huella
  `consentimiento_en`      DATETIME    NOT NULL,
  `consentimiento_version` VARCHAR(40) NOT NULL,
  `consentimiento_hash`    CHAR(64)    DEFAULT NULL,
  -- Origen
  `origen_pagina`   VARCHAR(200) DEFAULT NULL,
  `canal`           VARCHAR(80)  DEFAULT NULL,
  `utm_source`      VARCHAR(150) DEFAULT NULL,
  `utm_medium`      VARCHAR(150) DEFAULT NULL,
  `utm_campaign`    VARCHAR(150) DEFAULT NULL,
  `utm_term`        VARCHAR(150) DEFAULT NULL,
  `utm_content`     VARCHAR(150) DEFAULT NULL,
  `gclid`           VARCHAR(200) DEFAULT NULL,
  `li_fat_id`       VARCHAR(200) DEFAULT NULL,
  `referrer`        VARCHAR(300) DEFAULT NULL,
  `landing`         VARCHAR(200) DEFAULT NULL,
  `visitas_landing` SMALLINT UNSIGNED DEFAULT NULL,
  `ip_hash`         CHAR(64)     DEFAULT NULL,   -- nunca la IP
  -- Reserva pedida
  `tipo_reunion_id` INT UNSIGNED DEFAULT NULL,
  `inicio_pedido`   DATETIME     DEFAULT NULL,
  -- Lo que se creó en el CRM
  `propietario_id`  BIGINT UNSIGNED DEFAULT NULL,
  `contacto_id`     BIGINT UNSIGNED DEFAULT NULL,
  `cuenta_id`       BIGINT UNSIGNED DEFAULT NULL,
  `actividad_id`    BIGINT UNSIGNED DEFAULT NULL,
  `nota_id`         BIGINT UNSIGNED DEFAULT NULL,
  `reserva_id`      BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ew_idem`   (`token_id`, `idempotencia`),
  KEY `ix_ew_creado`        (`creado_en`),
  KEY `ix_ew_canal`         (`canal`, `creado_en`),
  KEY `ix_ew_pagina`        (`origen_pagina`, `creado_en`),
  KEY `ix_ew_email`         (`email`, `creado_en`),
  KEY `ix_ew_ip`            (`ip_hash`, `creado_en`),
  KEY `ix_ew_contacto`      (`contacto_id`),
  KEY `ix_ew_reserva`       (`reserva_id`),
  CONSTRAINT `fk_ew_contacto`  FOREIGN KEY (`contacto_id`)  REFERENCES `contactos` (`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_ew_cuenta`    FOREIGN KEY (`cuenta_id`)    REFERENCES `cuentas` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_ew_actividad` FOREIGN KEY (`actividad_id`) REFERENCES `actividades` (`id`)     ON DELETE SET NULL,
  CONSTRAINT `fk_ew_reserva`   FOREIGN KEY (`reserva_id`)   REFERENCES `agenda_reservas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Límite de pedidos: un contador por clave y por ventana de tiempo.
CREATE TABLE IF NOT EXISTS `api_limites` (
  `clave`    VARCHAR(120) NOT NULL,     -- 'k:<token>:escritura:60', 'ip:<hash>:reserva:3600', …
  `ventana`  INT UNSIGNED NOT NULL,     -- floor(time() / segundos)
  `conteo`   INT UNSIGNED NOT NULL DEFAULT 0,
  `vence_en` DATETIME     NOT NULL,
  PRIMARY KEY (`clave`, `ventana`),
  KEY `ix_al_vence` (`vence_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Claves de la API con permiso de escritura y su secreto para firmar.
ALTER TABLE `api_tokens`
  MODIFY COLUMN `scope` ENUM('lectura','root','escritura') NOT NULL DEFAULT 'lectura',
  ADD COLUMN IF NOT EXISTS `secreto_cifrado` TEXT DEFAULT NULL AFTER `token_hash`;

-- Reservas: una sola invitación que se actualiza, y de dónde vino.
ALTER TABLE `agenda_reservas`
  ADD COLUMN IF NOT EXISTS `ics_uid`         VARCHAR(255)    DEFAULT NULL AFTER `meet_url`,
  ADD COLUMN IF NOT EXISTS `ics_secuencia`   INT UNSIGNED    NOT NULL DEFAULT 0 AFTER `ics_uid`,
  ADD COLUMN IF NOT EXISTS `reprogramada_de` BIGINT UNSIGNED DEFAULT NULL AFTER `ics_secuencia`,
  ADD COLUMN IF NOT EXISTS `origen`          VARCHAR(20)     DEFAULT NULL AFTER `reprogramada_de`,
  ADD COLUMN IF NOT EXISTS `avisada_en`      DATETIME        DEFAULT NULL AFTER `origen`,
  ADD COLUMN IF NOT EXISTS `consentimiento_en`      DATETIME    DEFAULT NULL AFTER `avisada_en`,
  ADD COLUMN IF NOT EXISTS `consentimiento_version` VARCHAR(40) DEFAULT NULL AFTER `consentimiento_en`,
  ADD INDEX IF NOT EXISTS `ix_ar_prog`    (`reprogramada_de`),
  ADD INDEX IF NOT EXISTS `ix_ar_avisada` (`estado`, `avisada_en`);

-- Lo que ya estaba lo hizo el código anterior, que creaba el evento y mandaba
-- el mail en el mismo momento: se da por avisado, para que el cron no lo
-- vuelva a mandar. Las filas nuevas siempre traen `origen`, así que correr
-- esto otra vez no toca nada.
UPDATE `agenda_reservas`
   SET `avisada_en` = COALESCE(`avisada_en`, `creado_en`)
 WHERE `origen` IS NULL;

-- El identificador de la invitación es el mismo que venía usando agenda_ics():
-- así una cancelación de una reunión vieja sigue pisando la invitación original.
UPDATE `agenda_reservas`
   SET `ics_uid` = CONCAT('reserva-', `id`, '-', `token`, '@intellihelp.tech')
 WHERE `ics_uid` IS NULL;

-- Ajustes nuevos. No pisan lo que ya esté cargado.
INSERT INTO `app_settings` (`clave`, `valor`) VALUES
    ('web_dueno_id',               ''),
    ('avisar_web',                 '1'),
    ('agenda_invitado_en_google',  '1')
ON DUPLICATE KEY UPDATE `clave` = `clave`;
```

`UNIQUE (token_id, idempotencia)`: la agenda pública `/r/` guarda `token_id` NULL y una
idempotencia propia (`r-` + `token32()`), así que nunca choca.

### 7.2 Cómo se aplica

Hoy `deploy.py schema` importa `schema.sql` con la contraseña de base en una variable de
entorno, y con `--pruebas` igual apunta a producción (§4 #13). Para las migraciones se agrega:

- `app/migrar.php`: script de línea de comandos (como `reinicio.php`, devuelve 404 por HTTP) que
  arranca con el `bootstrap.php` de **su** instalación, así usa la configuración y la base que le
  corresponden sin pedir credenciales. Recibe la ruta de un `.sql`, lo divide en sentencias,
  las ejecuta en orden y deja `app_settings.migracion_<nnn> = fecha`. Imprime sólo cuántas
  sentencias corrió y cuáles fallaron, nunca la configuración. Con `--simular` sólo lista.
- `python bin/deploy.py migrar app/db/migracion-008-entradas-web.sql [--pruebas]`: sube el
  `.sql` a `/home/uXXXXXXXXX/crm_migracion/<archivo>.sql` (fuera del webroot, con su nombre
  original para que quede la marca `migracion_<nnn>`), corre
  `php <WEBROOT>/migrar.php` y lo borra.
- `cmd_app` sube también `migrar.php` junto a `cron.php` y `reinicio.php`.

Orden (las 002 a 007 ya están aplicadas en las dos bases; se verificó en pruebas):

1. **Pruebas**: `deploy.py app --pruebas` (sube `migrar.php`) → `deploy.py migrar …008… --pruebas`
   → `deploy.py check --pruebas` → pruebas (§11).
2. **Producción** (sólo en la fase Producción, §12): respaldo de la base → `deploy.py app`
   → `deploy.py migrar …008…` → `deploy.py check`.

El código nuevo comprueba que exista `entradas_web` y, si no, la API responde
`503 migracion_pendiente` en vez de un error 500. Así no importa si por un descuido se sube el
código antes de migrar.

---

## 8. CRM: funciones y firmas

Las firmas de esta sección son la interfaz entre la API y la agenda. Si alguna cambia, se
actualizan los dos lados y este documento en el mismo commit.

### 8.1 `crm.php` (sólo funciones nuevas, sección «Entradas desde la web»)

```php
/** Hash de una IP con el secreto de la instalación. Null si no hay IP. */
function ip_hash(?string $ip): ?string;
// hash_hmac('sha256', $ip, crm_secret())

/**
 * ¿Se pasó del límite? Suma uno al contador de esta ventana y devuelve true
 * si con este pedido supera $max. Purga lo vencido de vez en cuando (1 de cada 50).
 */
function limite_superado(string $clave, int $max, int $segundos): bool;

/** Segundos que faltan para que se libere esa ventana (para Retry-After). */
function limite_espera(int $segundos): int;

/** Nombre de empresa reducido para comparar: sin acentos, puntuación ni forma societaria. */
function empresa_clave(string $nombre): string;
// «ACME S.A.», «Acme SA», «acme, s. a.» → 'acme'
// Formas que se quitan al final: sa, s a, sau, srl, s r l, sas, saic, sca, sci, sh,
// sociedad anonima, ltda, inc, llc, ltd, coop, cooperativa

/** Dominio del correo si es corporativo; null para gmail, hotmail, outlook, yahoo, live, icloud, etc. */
function email_dominio_corporativo(string $email): ?string;

/**
 * La empresa de alguien que llegó por la web, sin duplicar ni pisar nada:
 *  1. mismo nombre (la intercalación ya ignora mayúsculas y acentos);
 *  2. misma empresa_clave() entre las que empiezan con la misma palabra;
 *  3. dominio corporativo: la empresa de los contactos con ese dominio, si es una sola,
 *     o la que tiene ese dominio en sitio_web o email;
 *  4. si no, cuenta_asegurar($nombre, ['sitio_web' => $dominio], $propietarioId).
 */
function cuenta_asegurar_web(string $empresa, string $email, int $propietarioId): ?int;

/**
 * El contacto de alguien que llegó por la web.
 * Existente (por email, email2 o email3, sin borrar): sólo completa teléfono y empresa si
 * estaban vacíos; no toca dueño, fuente ni no_email. Nuevo: contacto_guardar() con fuente
 * 'Sitio web', dueño $propietarioId y no_email = $d['novedades'] ? 0 : 1.
 * Deja 'contacto.web' en la línea de tiempo en los dos casos.
 *
 * @param array $d nombre, email, telefono, novedades, pagina
 * @return array{0:int,1:bool} [id, es_nuevo]
 */
function contacto_asegurar_web(array $d, int $propietarioId, ?int $cuentaId): array;

/** Quién atiende las consultas de la web: web_dueno_id → vendedor del tipo 'diagnostico' → primer admin. */
function entrada_web_dueno(): int;

/** Una línea legible del origen, para descripciones internas del CRM. */
function entrada_web_resumen(array $origen): string;

/**
 * Inserta la fila (estado 'procesando'). Si la idempotencia ya existe devuelve la fila
 * existente en $previa y 0.
 */
function entrada_web_guardar(array $d, ?array &$previa = null): int;

function entrada_web_actualizar(int $id, array $campos): void;

/**
 * Consulta sin reserva, todo en una transacción: empresa, contacto, nota, tarea, alerta
 * y actualización de la entrada.
 * @return array{contacto_id:int, cuenta_id:?int, actividad_id:int, nota_id:int}
 */
function entrada_web_consulta(int $entradaId, array $d): array;

/** Cron: borra los datos personales de entradas sin contacto (purgado) o con error, viejas. */
function entradas_web_anonimizar(int $dias = 60): int;
```

`app/web/app/api.php` (archivo nuevo, A; se incluye en `bootstrap.php`):

```php
function api_firma_canonica(string $ts, string $metodo, string $ruta, string $idem,
                            string $ipHash, string $cuerpo): string;
// $ts . "\n" . strtoupper($metodo) . "\n" . $ruta . "\n" . $idem . "\n" . $ipHash . "\n" . $cuerpo

function api_firma(string $secreto, string $ts, string $metodo, string $ruta, string $idem,
                   string $ipHash, string $cuerpo): string;
// hash_hmac('sha256', api_firma_canonica(...), $secreto)

/** Registra la firma de una escritura; true si ya se había usado (401 firma_repetida). */
function api_firma_usada(string $firma): bool;

/** Autentica el pedido. Devuelve la fila de api_tokens o responde 401/403/503 y termina. */
function api_autenticar(string $alcance = 'escritura'): array;

/** Lee y decodifica el cuerpo JSON (máximo $max bytes) o responde 400/413/415 y termina. */
function api_cuerpo_json(int $max = 16384): array;

/** Valida persona + consentimiento + origen. Devuelve [limpio, errores por campo]. */
function api_validar_entrada(array $j, bool $esReserva): array;

/** 429 con Retry-After si se pasa. */
function api_limitar(string $clave, int $max, int $segundos): void;

function api_responder(int $codigo, array $datos): never;
function api_error(int $codigo, string $error, string $mensaje, array $extra = []): never;
```

Controladores: `pages/api/v1/disponibilidad.php`, `pages/api/v1/reserva.php`,
`pages/api/v1/entrada.php`. Cada uno envuelve todo en `try/catch` y responde JSON también en
los errores inesperados (el manejador global de `bootstrap.php` contesta texto plano).

Flujo de `reserva.php`:

```
api_autenticar('escritura') → api_limitar(clave) → api_cuerpo_json() → api_validar_entrada()
→ api_limitar(ip_hash) → modulo_activo('agenda') → agenda_tipo_publico()
→ entrada_web_guardar()                (idempotencia)
→ agenda_reservar($tipo, $d, $o)       (B)  ← AgendaExcepcion → 409/422/503
→ entrada_web_actualizar(ok, ids)
→ api_responder(201) con finish_request() adentro
→ agenda_reserva_completar($reservaId) (B)
```

Otros archivos de A:

- `index.php`: rutas `api/v1/disponibilidad`, `api/v1/reserva`, `api/v1/entrada` (públicas) y
  `entradas-web` (con sesión). No se toca nada más.
- `pages/api-keys.php` + `views/api-keys.php`: selector de permiso con «Sólo lectura» y
  «Escritura (web)»; con escritura se genera y se muestra una vez el secreto; la tarjeta «Cómo
  se usa» explica la firma (es la pantalla de API: ahí sí va el detalle técnico).
- `pages/entradas-web.php` + `views/entradas-web.php`: listado con búsqueda, filtros (tipo,
  canal, página, fechas, estado), orden, paginación y exportación CSV, como Contactos. Arriba,
  tarjetas por canal: consultas, reuniones agendadas, realizadas (actividad completada) y
  canceladas, siguiendo `reprogramada_de` hasta la reserva vigente. Los administradores eligen
  ahí el responsable de las consultas (`web_dueno_id`). Sin jerga: «Llegó por», «Desde la
  página», «Campaña».
- `views/layout.php`: entrada «Entradas desde la web» en el menú de administración. Nada más.
- `notificaciones.php`: tipo `web` («Consultas desde la web», ajuste `avisar_web`).
- `actividad.php`: IP como hash cuando no hay sesión (§4 #12).
- `cron.php`: en el paso de reuniones, `agenda_completar_pendientes()` (B); al final,
  `entradas_web_anonimizar()` y la purga de `api_limites` una vez por día.
- `bootstrap.php`: `require APP . '/api.php';` después de `crm.php`.
- `app/migrar.php`, `bin/deploy.py` (§7.2, §4 #13, y la batería «API» en `cmd_test`, sólo con
  `--pruebas`), `app/db/migracion-008-entradas-web.sql`, `app/db/schema.sql`.

### 8.2 Agenda

`agenda.php`:

```php
/** Error de la agenda con un motivo que la API traduce a código. */
final class AgendaExcepcion extends RuntimeException
{
    public function __construct(
        string $mensaje,                          // texto para el visitante, en castellano
        public readonly string $motivo = 'datos_invalidos',
        // horario_tomado | horario_invalido | datos_invalidos | sin_calendario | ocupado
        public readonly ?string $campo = null,    // nombre, email, telefono, inicio…
    ) { parent::__construct($mensaje); }
}

/** Tipo activo por slug; con $usuario, el de ese vendedor. $motivo: inexistente | ambiguo. */
function agenda_tipo_publico(string $slug, ?string $usuario = null, ?string &$motivo = null): ?array;

/**
 * Ventana de cálculo: [timeMin, timeMax, fechas]. timeMax es el FINAL del último día
 * (corrige §4 #3). Recorta al rango [hoy, hoy + ventana_dias] y a 31 días.
 * @return array{0:int,1:int,2:array<int,string>}
 */
function agenda_ventana(array $tipo, string $desde, string $hasta, int $ahora): array;

/** Pura: inicios libres de un día dadas las franjas, la duración, el margen y lo ocupado. */
function agenda_slots_dia(string $fecha, array $franjas, int $duracionSeg, int $margenSeg,
                          int $minimoTs, array $ocupado): array;

/**
 * Huecos entre dos fechas (Y-m-d, inclusive).
 * $motivo: sin_calendario | sin_horario. $ignorarReservaId: la reserva que se está moviendo;
 * se ignora su fila y la franja ocupada de Google que coincide exactamente con ella.
 * @return array<string,array<int,int>> fecha => inicios
 */
function agenda_huecos_rango(array $tipo, string $desde, string $hasta,
                             ?string &$motivo = null, ?int $ignorarReservaId = null): array;

/** Compatible con lo de hoy: llama a agenda_huecos_rango() desde hoy. */
function agenda_huecos(array $tipo, int $dias = 0, ?string &$motivo = null,
                       ?int $ignorarReservaId = null): array;

/** ¿Ese inicio es un hueco válido y libre AHORA (Google + CRM + margen)? */
function agenda_hueco_libre(array $tipo, int $inicio, ?int $ignorarReservaId = null,
                            ?string &$motivo = null): bool;

/** Bloqueo por vendedor: GET_LOCK(CONCAT(DATABASE(), ':agenda:', id), $espera). */
function agenda_bloquear(int $usuarioId, int $espera = 10): bool;
function agenda_liberar(int $usuarioId): void;

/**
 * Parte de base de datos de la reserva (§3.6). Bloquea, valida, inserta reserva, contacto,
 * empresa y reunión. NO llama a Google ni manda correo.
 *
 * $d: inicio (int), nombre, email, empresa, telefono, comentario, servicio, campana_id
 * $o: origen ('web'|'publica'|'crm', defecto 'publica')
 *     validar_hueco (bool, defecto true)
 *     consentimiento_en (string Y-m-d H:i:s), consentimiento_version (string)
 *     novedades (bool, defecto false)
 *     descripcion_extra (string: la línea de entrada_web_resumen())
 *
 * @return array la fila de agenda_reservas + 'cuenta_id' + 'contacto_nuevo'
 * @throws AgendaExcepcion
 */
function agenda_reservar(array $tipo, array $d, array $o = []): array;

/**
 * Evento de Google con Meet (sendUpdates=none, iCalUID → ics_uid), mail de confirmación con
 * la .ics, alerta al vendedor y avisada_en = NOW(). Idempotente: si ya tiene evento no crea
 * otro; si ya está avisada no manda otro mail. Nunca lanza: los errores van a la auditoría.
 * @return array la reserva actualizada
 */
function agenda_reserva_completar(int $reservaId): array;

/** Cron: completa las confirmadas sin avisar con más de 2 minutos. Devuelve cuántas. */
function agenda_completar_pendientes(): int;

/** La reserva vigente a partir de cualquier token de la cadena de cambios de horario. */
function agenda_reserva_vigente(string $token): ?array;

/** $validarHueco = true para el invitado (/r/mover); el equipo puede mover a cualquier hora. */
function agenda_reprogramar(string $token, int $nuevoInicio, bool $validarHueco = false): array;

function agenda_cancelar(string $token, string $motivo = ''): bool;   // misma firma de hoy

/** Token de formulario firmado: tipo_id + marca de tiempo + HMAC(crm_secret()). */
function agenda_form_token(int $tipoId): string;
/** Válido entre 3 s y 2 h después de generado. $motivo: rapido | vencido | invalido. */
function agenda_form_token_valido(string $token, int $tipoId, ?string &$motivo = null): bool;
```

Además, en `agenda.php`:

- `agenda_ics()`: `UID` = `ics_uid` (con el respaldo de hoy), `SEQUENCE` = `ics_secuencia`, y en
  la descripción también «Para cambiar el horario: …/r/mover?t=…».
- `agenda_avisar()`: agrega el enlace de cambiar el horario junto al de cancelar.
- `agenda_reprogramar()`: la reserva nueva copia `ics_uid`, guarda `reprogramada_de` y
  `ics_secuencia` = máximo entre la anterior + 1 y la `sequence` que devuelve Google al mover.
- `agenda_cancelar()`: `ics_secuencia` + 1 antes de mandar el `CANCEL`.
- La reunión (actividad) lleva `cuenta_id` y en la descripción servicio, comentario y
  `descripcion_extra`. Título del evento de Google: «<tipo> · <nombre> (<empresa>)».
  Descripción: «Reunión agendada desde intellihelp.tech.».
- Las llamadas a `cuenta_asegurar_web()` y `contacto_asegurar_web()` son las de A (§8.1).

`gcal.php`:

```php
/** Devuelve [eventId, meetUrl, iCalUID]. $d gana 'avisar' ('none' por defecto) y 'privado' (array). */
function gcal_crear_evento(int $usuarioId, array $d, string $calendario = 'primary'): array;

function gcal_mover_evento(int $usuarioId, string $eventId, int $inicio, int $fin,
                           string $calendario = 'primary', string $avisar = 'none',
                           ?int &$secuencia = null): bool;

function gcal_borrar_evento(int $usuarioId, string $eventId, string $calendario = 'primary',
                            string $avisar = 'none'): bool;
```

`invitados` del evento: el vendedor siempre; el invitado sólo si `agenda_invitado_en_google = 1`.

Páginas:

- `pages/r.php`: gate por `modulo_activo('agenda')`; `/r/<usuario>/<tipo>` con
  `agenda_form_token()`, honeypot y UTM de la URL en campos ocultos; `/r/cancelar?t=` con token
  de formulario y `agenda_reserva_vigente()`; **nuevo** `/r/mover?t=`: muestra los huecos con
  `agenda_huecos($tipo, 0, $m, $vigente['id'])` y al confirmar llama a
  `agenda_reprogramar($t, $ts, true)`.
- `pages/reservar.php`: gate, honeypot, token de formulario, `limite_superado()` por
  `ip_hash(client_ip())`, consentimiento obligatorio, `entrada_web_guardar()` con
  `tipo = 'reserva'`, `token_id = NULL`, `origen_pagina = 'crm:/r/<usuario>/<slug>'`, canal
  «Campaña de correo» si trae `campana_id` y si no «Enlace directo»; después
  `agenda_reservar()` + `agenda_reserva_completar()` y `entrada_web_actualizar()`.
- `pages/reservas.php` + `views/reservas.php`: columna «Llegó por» (canal y página, desde
  `entradas_web`) y botón «Entradas desde la web».
- `pages/reserva-mover.php`, `pages/reserva-accion.php`: pasan el id de la reserva a
  `agenda_huecos()` para no ocupar su propio horario.
- Vistas públicas: `views/agenda-publica.php` (casilla de consentimiento con enlace al aviso de
  privacidad, honeypot oculto por clase, token), `views/agenda-cancelar.php`, **nueva**
  `views/agenda-mover.php`, `views/agenda-ok.php` (enlaces de cambiar horario y cancelar).
  Sin `style=` en línea, con las clases y variables de siempre (REQUERIMIENTOS → Diseño).
- `modulos.php`: no cambia la lista de rutas (el gate de lo público va en el controlador,
  §4 #10). Sólo se actualiza la ayuda del módulo Agenda: «… y las reservas que llegan desde la
  web».

---

## 9. Web: intermediario, configuración y pantalla

Repositorio `NEW-PAGE-ITH`. **La web no se publica**: todo esto se prueba en local contra
`crm-pruebas`.

### 9.1 Configuración (fuera del webroot)

| Dónde | Ruta |
|---|---|
| Servidor (cuando se publique) | `/home/uXXXXXXXXX/ith_web_config/crm.php` (carpeta 700, archivo 600) |
| Local | `NEW-PAGE-ITH/WEB/config/crm.local.php` (ignorado por git) |
| Variable que pisa las dos | `ITH_CRM_CONFIG` |

Se busca en ese orden: `ITH_CRM_CONFIG` → ruta del servidor → `dirname(__DIR__, 2) . '/config/crm.local.php'`
(desde `public/api/` y desde `dist/api/` las dos llegan a la raíz del repo).

```php
<?php
// config/crm.example.php — se copia a crm.local.php y se completa. No lleva secretos.
return [
    'url'      => 'https://intellihelp.tech/crm-pruebas',  // producción: https://intellihelp.tech/crm
    'clave'    => '',          // crm_… (pantalla API del CRM, permiso «Escritura (web)»)
    'secreto'  => '',          // 64 hex, se muestra junto con la clave
    'ip_sal'   => '',          // bin2hex(random_bytes(32)), propia de la web
    'tipos'    => ['bsierra/diagnostico'],   // vendedor/slug permitidos; producción: Carlos
    'timeout'  => 5,           // segundos para la entrada
    'timeout_reserva' => 10,
    'cola'     => __DIR__ . '/cola-crm',     // servidor: /home/uXXXXXXXXX/ith_web_config/cola-crm
    'cache'    => __DIR__ . '/cache-crm',    // servidor: /home/uXXXXXXXXX/ith_web_config/cache-crm
];
```

`.gitignore` de la web:

```
# Configuración local del CRM: lleva la clave y el secreto
/config/*
!/config/crm.example.php
```

La clave y el secreto se generan en la pantalla API del CRM y se copian a mano al archivo.
No se pegan en mails, chats ni commits.

### 9.2 `public/api/_crm.php` (biblioteca, nueva)

No responde por HTTP: si se lo pide directo devuelve 404, y además `.htaccess` niega `^_.*\.php$`.

```php
function crm_config(): ?array;                                   // null si falta o está incompleta
function crm_ip_hash(string $ip, array $cfg): string;            // hash_hmac('sha256', crm_ip_red($ip), ip_sal)
function crm_firmar(string $secreto, string $ts, string $metodo, string $ruta, string $idem,
                    string $ipHash, string $cuerpo): string;      // idem e ipHash vacíos en GET
function crm_form_token(array $cfg): string;                     // viaja con los horarios
function crm_form_token_valido(array $cfg, string $token, string $idem, ?string &$motivo = null): bool;
/** @return array{status:int, json:?array, error:?string} — status 0 si no hubo respuesta */
function crm_pedir(array $cfg, string $metodo, string $rutaApi, ?array $cuerpo,
                   ?string $idem, ?string $ipHash, int $timeout): array;
function crm_encolar(array $cfg, string $rutaApi, array $cuerpo, string $idem, string $ipHash): void;
function crm_reenviar_pendientes(array $cfg, int $max = 3): int;
function crm_origen(array $post): array;                         // limpia canal, UTM, gclid, …
function crm_consentimiento(string $version): ?array;            // versión → texto vigente
```

- `crm_pedir()` arma la ruta firmada como `parse_url($cfg['url'], PHP_URL_PATH) . '/api/v1/' . $rutaApi`
  (con `?` + `http_build_query(..., PHP_QUERY_RFC3986)` en los GET) y manda exactamente esa.
  Usa cURL; si no está, `stream_context_create()` con el mismo tiempo máximo.
- Cola: un archivo `<idem>.json` por entrada, permisos 600, con el cuerpo y la clave de
  idempotencia original. Al reenviar se firma de nuevo con hora nueva; la idempotencia es la
  misma, así que el CRM no duplica. Se reintenta al final de cada envío correcto
  (`crm_reenviar_pendientes()`, hasta 3) y por línea de comandos
  (`php public/api/_crm.php reenviar`, que sirve para un cron de hPanel cuando se publique).
  Lo que lleva más de 30 días en la cola se borra: son datos personales.
- `crm_consentimiento('web-2026-11')` devuelve el texto exacto que muestra el formulario. La
  fecha la pone el servidor de la web, no el navegador.

### 9.3 `public/api/agenda.php` (nuevo)

- `GET ?desde=Y-m-d&hasta=Y-m-d&tipo=<slug>&vendedor=<usuario>`: el par `vendedor/tipo` tiene que
  estar en `$cfg['tipos']` (lo que manda el navegador no se usa sin comprobarlo). Caché en
  `$cfg['cache']` por 60 s, clave `sha256(vendedor|tipo|desde|hasta)`. Límite: 60 por IP cada
  10 minutos. Responde:
  `{ok, zona, duracion_min, dias:[{fecha, horarios:[{ts, hora:"09:30"}]}]}`.
  Si el CRM falla o tarda: `503 {ok:false, error:"sin_agenda"}` y la landing deja el formulario.
- `POST` (reserva): mismos campos que el formulario de siempre (`nombre`, `empresa`, `mail`,
  `telefono`, `necesidad`, `mensaje`), más `inicio` (ts), `tipo`, `vendedor`, `acepto` (casilla
  de consentimiento), `consentimiento_version`, `idem` (UUID generado una vez por formulario en el
  navegador), `form_token` (el que viajó con los horarios: firmado con el secreto de la web, de
  3 s a 2 h, para una sola reserva con hasta tres claves de idempotencia; si no sirve,
  `409 recargar_horarios`), `website` (honeypot) y los ocultos de origen + `visitas_landing`.
  Validación como `contact.php`, límite 3 por IP (red /64 en IPv6) cada 10 minutos, idempotencia final
  `'web-' . substr(hash('sha256', $idem . $ipHash), 0, 40)`. Llama a `api/v1/reserva` con
  `timeout_reserva`. Además manda el mail a `info@` («Reunión agendada — <empresa>», mismo formato
  que hoy, con día y hora). Respuestas:
  `201 {ok:true, inicio, cuando:"martes 3 de noviembre a las 09:30 h"}` ·
  `409 {ok:false, error:"horario_tomado", mensaje}` · `409 recargar_horarios` · `422` · `429` ·
  `429 limite` (tope del CRM: la pantalla ofrece el formulario) · `503 sin_agenda`.
  Si el CRM no responde, se reintenta una vez con la misma idempotencia; si sigue sin respuesta,
  `202 {ok:false, error:"confirmando", mensaje}`: la reserva pudo haber entrado, así que la
  pantalla pide revisar el correo en lugar de ofrecer el formulario. **No se encola**: un horario
  no se puede confirmar a ciegas.
- `GET`: al CRM se le pide siempre la ventana fija (hoy + 20 días) y el rango del navegador se
  recorta en la web: una sola entrada de caché (60 s) por vendedor y tipo. Si el CRM no responde
  o corta por límite, se sirve la última respuesta buena de menos de 5 minutos. La respuesta trae
  el `form_token`.
- `public/api/.htaccess` deja pasar `contact.php` y `agenda.php`; todo lo demás (incluido
  `_crm.php`) sigue en 403.
- Los contadores del límite por IP guardan un HMAC con sal (la `ip_sal` del CRM o, sin
  configuración, una propia) en una carpeta privada, y se borran solos al día.

### 9.4 `public/api/contact.php` (cambios)

- Casilla de consentimiento obligatoria (`acepto`), si no `422`.
- Después del mail a `info@` (que sigue igual y es lo que decide la respuesta), responde al
  navegador, cierra la conexión (`fastcgi_finish_request()` / `litespeed_finish_request()`) y
  manda `api/v1/entrada` con 5 s de tiempo máximo. Si falla, `crm_encolar()` y una línea en el
  log de errores **sin datos personales** (sólo el `idem` y el código).
- La limpieza de los campos de origen pasa a `crm_origen()` y la usan los dos.

### 9.5 Pantalla

- `src/data/site.json`: `"diagnostico": { "calendario": "crm", "tipo": "diagnostico", "vendedor": "<usuario de Carlos en el CRM>", "video": "" }`.
  En local, contra pruebas: `"vendedor": "bsierra"`.
- `diagnostico/index.html`: `data-tipo` y `data-vendedor` en `<main>`; comentario actualizado.
- `src/partials/pagina/diagnostico/calendario.html`: template nuevo `[data-diagnostico-crm]` con
  dos pasos en el panel blanco: (1) días y horarios (botones, navegables con teclado,
  `aria-pressed`), (2) datos + consentimiento. La nota «Para Clau» sobre la página de citas de
  Google se reemplaza.
- `src/scripts/diagnostico.js`: con `calendario === "crm"` pide `/api/agenda.php`, arma el
  selector y manda la reserva. Si la primera consulta falla, deja el formulario de siempre. En
  `409` vuelve a pedir horarios y avisa «Justo tomaron ese horario». Al confirmar: mensaje con
  día y hora, y «Te mandamos un mail con el enlace de Meet y la invitación para tu calendario».
- `src/scripts/modules/analytics.js`: `click_calendar` con `calendar_type: "crm"` al elegir un
  horario (`click_target: "horario"`) y `schedule_meeting` con `calendar_type: "crm"`,
  `landing_visit` y `servicio` al confirmar. La reserva cuenta como `schedule_meeting`, no como
  `generate_lead`, para no contarla dos veces.
- `src/scripts/modules/contact-form.js`: valida la casilla de consentimiento.
- `src/partials/agenda.html`: casilla de consentimiento con el texto versionado y enlace a
  `/privacidad/`; el texto legal deja de decir «solo para responder» sin más (§10).
- `src/styles/sections/diagnostico.css`: estilos del selector con las variables del sitio.
- `public/.htaccess`: niega `^_.*\.php$`. La CSP no cambia (`connect-src 'self'` alcanza).
- `plugins/vite-plugin-contact-mock.js`: con `ITH_PHP_URL` (por ejemplo
  `http://127.0.0.1:8099`) reenvía `/api/*.php` a `php -S 127.0.0.1:8099 -t public`
  (con `-d extension=curl -d extension=openssl`: el PHP local de desarrollo no las tiene
  cargadas); sin la variable, simula también `/api/agenda.php` con horarios inventados.
- `privacidad/index.html` y `docs/marketing/medicion-embudo.md` (§10 y eventos).

---

## 10. Datos personales (Ley 25.326)

- **Consentimiento expreso** (art. 5): casilla obligatoria, sin marcar por defecto, en el
  formulario de la web, en la reserva de la landing y en `/r/`. Se guarda fecha, versión y la
  huella del texto. Texto `web-2026-11`:
  «Acepto que Intellihelp use estos datos para responder mi consulta y coordinar la reunión,
  según el aviso de privacidad.»
- **Finalidad**: responder y coordinar la reunión. Los contactos nuevos que entran por la web
  quedan con **«No incluir en campañas»** (`no_email = 1`) salvo que acepten novedades
  (`novedades = true`; la casilla para eso no se agrega por ahora). A un contacto que ya existía
  no se le cambia nada.
- **IP**: nunca en claro. La web manda un hash con su sal; el CRM guarda ese hash y, en la
  auditoría de las páginas públicas, su propio hash (§4 #12).
- **Google**: los datos de la reunión pasan por Google Calendar y Meet (nombre, empresa, correo
  del invitado, día y hora). Nada de mensaje, teléfono, servicio ni datos de marketing.
- **Aviso de privacidad** (`/privacidad/`): hay que corregir «El sitio no almacena tus datos en
  una base propia: se envían directamente por mail», que deja de ser cierto. Tiene que decir que
  los datos quedan en el sistema de gestión comercial propio de Intellihelp (alojado en
  Hostinger), que las reuniones se coordinan con Google Calendar y Google Meet, cuánto se
  conservan y cómo pedir acceso, rectificación o supresión.
- **Supresión**: al purgar un contacto de la papelera, la clave foránea deja `entradas_web`
  sin contacto; el cron anonimiza esas filas (nombre «(borrado)», sin correo, teléfono, empresa
  ni mensaje) y las que quedaron con error, a los 60 días. Las métricas por canal se conservan.
- **Cola de la web**: archivos 600 fuera del webroot, se borran al entrar al CRM o a los 30 días.
- **Registro en la AAIP**: sigue pendiente la consulta a Carlos (nota en `/privacidad/`).

---

## 11. Pruebas

Siempre contra **`crm-pruebas`**, nunca con la web conectada a pruebas usando datos reales:
`reinicio.php` borra todo. Datos inventados («Juana Prueba», «Empresa de Prueba SRL») y **sólo**
el correo de trabajo `bsierra@intellihelp.tech` (usuario 1 de la base de pruebas, con Google
conectado). Nunca correos de clientes.

Antes de empezar: SMTP configurado en pruebas (§4 #14) y tipo `diagnostico` del usuario 1
(lo siembra `reinicio.php`, ver abajo).

### 11.1 Archivos de prueba

| Archivo | Qué prueba |
|---|---|
| `test/run_tests.php` (sin base ni red) | `api_firma_canonica()` y `api_firma()` con vectores fijos; `empresa_clave()` («ACME S.A.» = «Acme SA» = «acme, s. a.»; «Sasa SRL» ≠ «Sa»); `email_dominio_corporativo()`; `agenda_ventana()` cubre el final del último día (§4 #3); `agenda_slots_dia()` con margen y antelación; `agenda_ics()` con `ics_uid` y `SEQUENCE`; `agenda_form_token_valido()` (rápido, vencido, alterado); `api_validar_entrada()` (cada regla de §6.2, claves desconocidas, consentimiento). Hay que sumar `require $SRC . '/api.php';`. |
| `test/ui_test.php` (estático) | casilla de consentimiento y honeypot en `agenda-publica.php`; `sendUpdates=none` por defecto en `gcal.php`; `GET_LOCK` en `agenda.php`; la descripción del evento no incluye `comentario`; rutas `api/v1/*` y `entradas-web` registradas; permiso «Escritura» en `api-keys`; `r.php` comprueba `modulo_activo('agenda')`; vistas nuevas sin `style=`. Los controles que ya existen siguen pasando. El de `notificar('reunion'` (línea 984) seguiría pasando de casualidad, porque la expresión busca hasta el final del archivo: se ajusta para que mire adentro de `agenda_reserva_completar()`, que es donde queda el aviso. |
| `test/smoke.sh` (HTTP) | `api/v1/disponibilidad` sin clave → 401; `GET api/v1/reserva` → 405; `/r/mover` sin token → 200 con «El enlace no es válido»; `migrar.php` y `api.php` no responden por HTTP. |
| `test/api_test.php` (nuevo, HTTP + base, **sólo si `es_testing()`**) | Crea su propia clave `escritura` directo en la base y la borra al final. Firma inválida, hora vieja, clave de lectura → 401/403. Disponibilidad. Reserva con `bsierra@intellihelp.tech` → 201; repetida con la misma idempotencia → 200 `repetida`; misma clave con otro cuerpo → 409; mismo horario con otra clave → 409 `horario_tomado`; horario fuera del laboral → 409. Dos reservas en paralelo al mismo horario (`curl_multi`): una sola gana. Entrada → 201 con contacto, nota, tarea a nombre del responsable y fila con canal y página. Límite por IP → 429. Comprueba que el evento de Google **no** tiene UTM ni mensaje en la descripción. Al final cancela la reserva y borra todo lo que creó. |
| `test/web_contrato_test.php` (nuevo, local) | Con `WEB_SRC=…/NEW-PAGE-ITH/WEB/public/api`: misma firma de los dos lados para los mismos vectores (con idempotencia y hash de IP); misma red /64 y hash con sal; la web rechaza lo mismo que el CRM (direcciones web en nombre o empresa, nombre de más de 120); token del formulario de la web (rápido, una reserva, alterado); el límite por IP no guarda el sha256 pelado; el `.htaccess` de `/api` deja pasar `agenda.php` y nada más. |
| `app/reinicio.php` | `entradas_web` y `api_limites` en lo que se borra (si no, el control de tablas olvidadas avisa); semilla del tipo «Reunión de diagnóstico» (`diagnostico`, 30 min, L a V 9 a 18, margen 15, aviso 2 h, ventana 21 días) para el dueño de los datos de prueba. |
| `md/DOCUMENTACION-MAESTRA.md`, `README.md` (sección API), `CHANGELOG.md`, `md/REQUERIMIENTOS.md` (Agenda: consentimiento, una sola invitación, entradas web) | Documentación. |

### 11.2 Recorrido completo (a mano, en local)

1. `reinicio.php --hacerlo` en pruebas → generar clave «Web local» con escritura → copiarla a
   `NEW-PAGE-ITH/WEB/config/crm.local.php`.
2. `php -d extension=curl -d extension=openssl -S 127.0.0.1:8099 -t public` + `ITH_PHP_URL=http://127.0.0.1:8099 npm run dev`.
   En la máquina de desarrollo el PHP de winget no trae `extension_dir` ni certificados: hace falta
   además `-d extension_dir=<carpeta ext de PHP>` y `-d curl.cainfo="C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt"`;
   sin eso cURL falla con «unable to get local issuer certificate» y la landing cae siempre al
   formulario.
3. Entrar a `/diagnostico/?utm_source=google&utm_medium=cpc&utm_campaign=prueba-integracion`,
   elegir un horario, reservar con datos inventados y `bsierra@intellihelp.tech`.
4. Comprobar: **un solo mail** con la invitación `.ics` y el botón de Meet; en el calendario de
   `bsierra@` **un solo evento**, con Meet, título con nombre y empresa, descripción sin UTM ni
   mensaje; en el CRM de pruebas el contacto («Sitio web», «No incluir en campañas»), la
   empresa, la reunión con la empresa asociada, la alerta, y en «Entradas desde la web» la fila
   con canal Google Ads, página `/diagnostico/` y campaña `prueba-integracion`.
5. Desde el mail: cambiar el horario (`/r/mover`) → el evento se mueve, llega una invitación
   que **reemplaza** a la anterior (no queda la vieja en el calendario).
6. Cancelar desde el mail nuevo y desde el viejo (debe encontrar la vigente) → `CANCEL`, el
   evento desaparece.
7. Formulario común en otra página → mail a `info@` + tarea «Responder consulta web» +
   nota + entrada.
8. Apagar el CRM para la web (URL errónea en la config) → la landing muestra el formulario; la
   consulta queda en la cola; corregir la URL, mandar otra → entran las dos, sin duplicar.
9. `cron.php` → recordatorio de una reunión dentro de las próximas 24 h.
10. `reinicio.php --hacerlo` al terminar (REQUERIMIENTOS: vaciar la base de testing).

---

## 12. Paso a producción

Sólo el CRM. **La web todavía no se despliega**: su configuración en el servidor se prepara
cuando se decida publicarla.

Requisitos que hay que confirmar en producción (no se pueden verificar desde pruebas):

- [ ] SMTP configurado y probado (Ajustes).
- [ ] `app.secret` de producción con 32 caracteres o más (lo verifica `migrar.php --simular`
      sin mostrarlo).
- [ ] Carlos tiene usuario en el CRM, entró con Google y aceptó el permiso de Calendar.

Pasos:

1. Revisión final y commit en los dos repositorios.
2. Respaldo de la base de producción.
3. `python bin/deploy.py app` → `python bin/deploy.py migrar app/db/migracion-008-entradas-web.sql`
   → `python bin/deploy.py check`.
4. En el CRM de producción, con el usuario de Carlos: Agenda → tipo «Reunión de diagnóstico»,
   slug `diagnostico`, con su horario real.
5. Ajustes → Qué está en marcha: **Agenda** y **Actividades** encendidos.
6. Entradas desde la web → responsable de las consultas (si no es Carlos).
7. API → clave «Web intellihelp.tech» con permiso Escritura. Queda guardada para cuando se
   publique la web, que la usa desde `/home/uXXXXXXXXX/ith_web_config/crm.php`.
8. `bash test/smoke.sh https://intellihelp.tech/crm` (sin `api_test.php`, que no corre en producción).
9. Una reserva real de punta a punta con el correo de trabajo (`bsierra@`), y después se cancela.

---

## 13. Orden de implementación

Archivos por área, en el orden en que se implementan:

| Área | Archivos |
|---|---|
| **1 · API, migración y claves** | `app/db/migracion-008-entradas-web.sql` (nuevo) · `app/db/schema.sql` · `app/migrar.php` (nuevo) · `bin/deploy.py` · `app/web/index.php` (sólo rutas) · `app/web/app/bootstrap.php` (sólo el `require`) · `app/web/app/api.php` (nuevo) · `app/web/app/crm.php` (sólo funciones nuevas, §8.1) · `app/web/app/notificaciones.php` · `app/web/app/actividad.php` · `app/cron.php` · `app/web/app/pages/api/v1/disponibilidad.php`, `reserva.php`, `entrada.php` (nuevos) · `app/web/app/pages/api-keys.php` · `app/web/app/views/api-keys.php` · `app/web/app/pages/entradas-web.php` + `views/entradas-web.php` (nuevos) · `app/web/app/views/layout.php` (sólo el menú) |
| **2 · Agenda** | `app/web/app/agenda.php` · `app/web/app/gcal.php` · `app/web/app/modulos.php` (sólo la ayuda) · `app/web/app/pages/r.php` · `reservar.php` · `reservas.php` · `reserva-accion.php` · `reserva-mover.php` · `agenda.php` · `agenda-form.php` · `app/web/app/views/agenda-publica.php` · `agenda-cancelar.php` · `agenda-mover.php` (nueva) · `agenda-ok.php` · `agenda-error.php` · `agenda.php` · `agenda-form.php` · `reservas.php` · `reserva-mover.php` |
| **3 · Pruebas y documentación** | `test/*` (incluidos `api_test.php` y `web_contrato_test.php`, nuevos) · `app/reinicio.php` · `md/DOCUMENTACION-MAESTRA.md` · `md/REQUERIMIENTOS.md` · `README.md` · `CHANGELOG.md` |
| **4 · Web** (`NEW-PAGE-ITH/WEB`) | `public/api/_crm.php` (nuevo) · `public/api/agenda.php` (nuevo) · `public/api/contact.php` · `public/.htaccess` · `config/crm.example.php` (nuevo) · `.gitignore` · `src/data/site.json` · `diagnostico/index.html` · `src/partials/pagina/diagnostico/calendario.html` · `src/partials/agenda.html` · `src/scripts/diagnostico.js` · `src/scripts/modules/contact-form.js` · `src/scripts/modules/analytics.js` · `src/styles/sections/diagnostico.css` · `plugins/vite-plugin-contact-mock.js` · `privacidad/index.html` · `../DOCS/marketing/medicion-embudo.md` |

Dependencias:

- La agenda llama a `cuenta_asegurar_web()`, `contacto_asegurar_web()`, `ip_hash()`,
  `limite_superado()`, `entrada_web_guardar()`, `entrada_web_actualizar()` (API).
- La API llama a `agenda_tipo_publico()`, `agenda_huecos_rango()`, `agenda_reservar()`,
  `agenda_reserva_completar()`, `agenda_completar_pendientes()` y captura `AgendaExcepcion` (agenda).
- Las pruebas cubren las firmas de §8 y el contrato de §5; la web implementa el otro lado de §5 y §9.
- Orden: API y agenda en `crm-pruebas` con la migración aplicada → pruebas → web local contra
  pruebas → producción (§12), sólo del CRM.

Reglas:

- Ningún secreto en archivos versionados, en la auditoría, en los mails ni en los mensajes de
  commit. De `bin/deploy.local.json`, `app/server-config/config.php` y `crm_config/*` no se copia
  nada a otro lado.
- Cada defecto de §4 lleva su prueba de regresión (REQUERIMIENTOS → Proceso de trabajo).

---

## 14. Riesgos y pendientes

- **Duplicado en Gmail** (§3.4): se confía en que la invitación del mail con el mismo `UID` que
  el evento de Google se una a él. Se comprueba en el paso 4 de §11.2 con la cuenta de Workspace
  `bsierra@`. Si duplica: `agenda_invitado_en_google = 0`. Outlook no se puede probar sin una
  casilla externa de prueba propia.
- **Respuestas a la invitación desde Outlook**: van al remitente del SMTP (organizador de la
  `.ics`), no a Carlos. Es el comportamiento actual y no cambia.
- **Hosting**: `REQUEST_URI` tiene que llegar sin reescribir detrás del `RewriteRule` del CRM
  (`api_test.php` lo comprueba; si no, la firma falla con 401 siempre).
- **Carlos sin cuenta en pruebas**: en pruebas el vendedor es `bsierra`. El usuario real de Carlos
  (`diagnostico.vendedor` en `site.json`) se completa en la fase Producción.
- **Reinicio de pruebas**: borra las claves de API; después de cada reinicio hay que generar
  otra para la web local.
- **IP detrás del CDN** (ya anotado en `contact.php`): si hay CDN delante de la web, `REMOTE_ADDR`
  es la del nodo y el límite por IP se comparte; antes de publicar hay que tomar la IP de la
  cabecera del CDN sólo cuando `REMOTE_ADDR` sea de sus rangos.

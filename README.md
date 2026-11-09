<p align="center">
  <img src="docs/banner.svg" alt="CRM INTELLIHELP" width="100%">
</p>

<p align="center">
  <b>CRM comercial propio: base de contactos, embudo de ventas, campañas de correo en frío y agenda de reuniones con Google Meet.</b><br>
  Reemplaza a Zoho CRM y absorbe el motor de envío de Pulsemail, sin servicios externos ni licencias por usuario.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/MySQL-MariaDB-4479A1?logo=mysql&logoColor=white" alt="MySQL / MariaDB">
  <img src="https://img.shields.io/badge/auth-Google%20Workspace-4285F4?logo=google&logoColor=white" alt="Google Workspace">
  <img src="https://img.shields.io/badge/Calendar%20%2B%20Meet-integrado-00897B?logo=googlemeet&logoColor=white" alt="Google Calendar + Meet">
  <img src="https://img.shields.io/badge/tests-552%20comprobaciones-663399" alt="Tests">
  <img src="https://img.shields.io/badge/licencia-propietaria-663399" alt="Licencia propietaria">
</p>

---

## Índice

- [Descripción](#descripción)
- [La aplicación por dentro](#la-aplicación-por-dentro)
- [Características](#características)
- [Arquitectura del sistema](#arquitectura-del-sistema)
- [Motor de campañas](#motor-de-campañas)
- [Editor de campañas](#editor-de-campañas)
- [Agenda de reuniones](#agenda-de-reuniones)
- [Migración desde Zoho CRM](#migración-desde-zoho-crm)
- [Auditoría](#auditoría)
- [API](#api)
- [Stack técnico](#stack-técnico)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Puesta en marcha](#puesta-en-marcha)
- [Tests](#tests)
- [Seguridad](#seguridad)
- [Licencia](#licencia)
- [Autor](#autor)

## Descripción

**CRM INTELLIHELP** reemplaza dos herramientas por una sola: **Zoho CRM**, donde vivía la base
comercial (13.573 contactos y 12.249 empresas), y **Pulsemail**, la aplicación de escritorio
con la que se mandaban las campañas de correo en frío.

La diferencia de fondo con la app de escritorio es que acá **el trabajo pesado corre en el
servidor**. Una campaña no depende de que una computadora quede prendida: se arma una cola y
un cron la despacha de a un correo por vez, con la misma pausa aleatoria anti-spam de
Pulsemail. Si se corta la luz o se cierra el navegador, la campaña sigue por donde quedó y no
repite un solo envío.

Además, el CRM **deja de depender de Calendly**: la agenda de reuniones es propia, lee la
ocupación real del Google Calendar del vendedor y crea el evento con su **Google Meet**
automáticamente.

> En producción: **`https://intellihelp.tech/crm`** — una sola dirección. El subdominio
> `crm.intellihelp.tech` existe porque el hosting lo necesita para servir la carpeta, pero
> redirige acá con un 301.
> Herramienta interna: el acceso se restringe a las cuentas del dominio de la organización.

## La aplicación por dentro

Identidad visual compartida con el [Portal de RRHH](https://github.com/bsierra-ith): tipografía
Saira y paleta `#663399` · `#9f88b1` · `#fff6ff` · `#fffade`, con colores planos y sin
degradados. Las dos aplicaciones de la casa se usan el mismo día y tienen que verse como una
sola.

> Las capturas salen de la instalación de **pruebas**, que corre exactamente el mismo código
> que producción pero con datos ficticios y todos los módulos encendidos. En producción cada
> sección se habilita por separado, y los contactos son reales: no van a una captura.

<p align="center"><img src="docs/screenshots/login.png" alt="Pantalla de ingreso" width="100%"></p>

### Panel

<p align="center"><img src="docs/screenshots/panel.png" alt="Panel del CRM" width="100%"></p>

### Base comercial

<table>
  <tr>
    <td width="50%"><b>Contactos</b><br><img src="docs/screenshots/contactos.png" alt="Listado de contactos"></td>
    <td width="50%"><b>Pendientes de aprobar, de a varios</b><br><img src="docs/screenshots/contacto-pendiente.png" alt="Contactos pendientes de aprobar"></td>
  </tr>
  <tr>
    <td width="50%"><b>Ficha de contacto</b><br><img src="docs/screenshots/contacto-ficha.png" alt="Ficha de contacto"></td>
    <td width="50%"><b>Alta de contacto</b><br><img src="docs/screenshots/contacto-nuevo.png" alt="Nuevo contacto"></td>
  </tr>
  <tr>
    <td width="50%"><b>Empresas</b><br><img src="docs/screenshots/empresas.png" alt="Listado de empresas"></td>
    <td width="50%"><b>Ficha de empresa</b><br><img src="docs/screenshots/empresa-ficha.png" alt="Ficha de empresa"></td>
  </tr>
  <tr>
    <td width="50%"><b>Posibles clientes</b><br><img src="docs/screenshots/posibles.png" alt="Posibles clientes"></td>
    <td width="50%"><b>Buscador global</b><br><img src="docs/screenshots/buscador.png" alt="Buscador global"></td>
  </tr>
</table>

### Embudo y actividades

<table>
  <tr>
    <td width="50%"><b>Embudo en Kanban</b><br><img src="docs/screenshots/embudo.png" alt="Embudo de ventas"></td>
    <td width="50%"><b>Etapas configurables</b><br><img src="docs/screenshots/embudos-etapas.png" alt="Embudos y etapas"></td>
  </tr>
  <tr>
    <td width="50%"><b>Actividades</b><br><img src="docs/screenshots/actividades.png" alt="Actividades"></td>
    <td width="50%"><b>Reportes y pronóstico</b><br><img src="docs/screenshots/reportes.png" alt="Reportes"></td>
  </tr>
</table>

### Campañas de correo

<p align="center"><img src="docs/screenshots/plantilla-editor.png" alt="Editor de plantillas por bloques" width="100%"></p>

<table>
  <tr>
    <td width="50%"><b>Campañas</b><br><img src="docs/screenshots/campanas.png" alt="Campañas"></td>
    <td width="50%"><b>Plantillas por servicio</b><br><img src="docs/screenshots/plantillas.png" alt="Plantillas"></td>
  </tr>
  <tr>
    <td width="50%"><b>Listas de distribución</b><br><img src="docs/screenshots/listas.png" alt="Listas"></td>
    <td width="50%"><b>Detalle de una lista</b><br><img src="docs/screenshots/lista-ficha.png" alt="Detalle de lista"></td>
  </tr>
  <tr>
    <td width="50%"><b>Alertas: respuestas, rebotes y reuniones</b><br><img src="docs/screenshots/alertas.png" alt="Alertas"></td>
    <td width="50%"><b>Importación desde CSV</b><br><img src="docs/screenshots/importar.png" alt="Importar datos"></td>
  </tr>
</table>

### Agenda de reuniones

<table>
  <tr>
    <td width="50%"><b>Agendas públicas</b><br><img src="docs/screenshots/agenda.png" alt="Agendas"></td>
    <td width="50%"><b>Reuniones reservadas</b><br><img src="docs/screenshots/reservas.png" alt="Reservas"></td>
  </tr>
</table>

<p align="center"><b>Feriados del año</b> (calendario oficial, nacionales y regionales: esos días la agenda no ofrece horarios)<br><img src="docs/screenshots/feriados.png" alt="Feriados" width="100%"></p>

### Administración

<table>
  <tr>
    <td width="50%"><b>Ajustes</b><br><img src="docs/screenshots/ajustes.png" alt="Ajustes"></td>
    <td width="50%"><b>Usuarios</b><br><img src="docs/screenshots/usuarios.png" alt="Usuarios"></td>
  </tr>
  <tr>
    <td width="50%"><b>CRON y registro de errores</b><br><img src="docs/screenshots/cron.png" alt="CRON"></td>
    <td width="50%"><b>Claves de API</b><br><img src="docs/screenshots/api.png" alt="API"></td>
  </tr>
  <tr>
    <td width="50%"><b>Papelera con restauración</b><br><img src="docs/screenshots/papelera.png" alt="Papelera"></td>
    <td width="50%"></td>
  </tr>
</table>

### En el celular

La barra lateral se guarda y sale como cajón; las tablas pasan a tarjetas, una por fila.

<p align="center">
  <img src="docs/screenshots/movil-login.png" alt="Ingreso en celular" width="30%">
  &nbsp;
  <img src="docs/screenshots/movil-panel.png" alt="Panel en celular" width="30%">
  &nbsp;
  <img src="docs/screenshots/movil-contactos.png" alt="Contactos en celular" width="30%">
</p>

## Características

- **Base comercial completa.** Empresas, contactos y posibles clientes con búsqueda global,
  filtros, orden, paginación, exportación a CSV y ficha con listas relacionadas, notas,
  actividades e historial.
- **Conversión de posible cliente.** Un clic convierte el contacto crudo en Empresa +
  Contacto + Negocio. Si la empresa o la persona ya existen, **las reutiliza en vez de
  duplicarlas**, y arrastra notas, adjuntos y actividades.
- **Embudo de ventas.** Etapas configurables con probabilidad, vista **Kanban**, ingreso
  ponderado calculado en la base, historial de cuánto tardó cada negocio en cada etapa y
  motivo de pérdida.
- **Pronóstico.** Objetivo del período contra lo ganado y contra el embudo ponderado, por
  vendedor.
- **Actividades unificadas.** Tareas, llamadas y reuniones en una sola línea de tiempo, con
  vistas rápidas (hoy, esta semana, vencidas) y completado en un clic desde cualquier ficha.
- **Campañas de correo en frío, con editor visual.** El correo se arma con bloques y la vista
  previa la renderiza el mismo motor que hace el envío, así que lo que se ve es lo que llega.
  Listas cargables desde el propio filtro del CRM, segmentos por rubro, **varias cuentas de
  correo para correr campañas en paralelo**, cola persistente y estadísticas reales.
  Ver [Motor de campañas](#motor-de-campañas) y [Editor de campañas](#editor-de-campañas).
- **Rebotes y respuestas automáticos.** El sistema lee la casilla por IMAP, reconoce los
  rebotes, marca qué contactos respondieron y **limpia la base sola**: cuando una dirección
  rebota de forma definitiva —la casilla ya no existe o el dominio se dio de baja— el contacto
  se archiva sin que nadie tenga que hacerlo a mano.
- **Agenda propia con Google Meet.** Sin Calendly ni Cal.com. Ver
  [Agenda de reuniones](#agenda-de-reuniones).
- **Migración desde Zoho sin duplicar.** Ver [Migración](#migración-desde-zoho-crm).
- **Auditoría total.** Cada acción, acceso y error —del servidor y del navegador— queda
  registrado y es consultable.
- **Nada se borra de golpe.** Todo borrado es lógico: el registro va a la papelera, se puede
  restaurar durante **60 días** y recién después el sistema lo elimina de verdad.
- **Diseño profesional y responsive.** Sin scroll horizontal en ningún ancho, iconos SVG en
  línea y ventanas emergentes propias.

## Arquitectura del sistema

Front controller PHP sin framework, con módulos que comparten auditoría, correo y la API.

```mermaid
flowchart LR
    U([Equipo comercial]) --> APP{{CRM · PHP + MariaDB}}
    APP --> PC[Posibles clientes]
    APP --> CT[Contactos y Empresas]
    APP --> NG[Negocios · Embudo]
    APP --> AC[Actividades]
    PC -- conversión --> CT
    CT --> NG
    APP --> CA[Campañas]
    APP --> AG[Agenda]
    CA --> COLA[(Cola de envíos)]
    CRON[[Cron cada 5 min]] --> COLA
    COLA -- SMTP --> MAIL([Gmail / Workspace])
    CRON -- IMAP --> MAIL
    MAIL -- rebotes y respuestas --> CT
    AG --> GC([Google Calendar + Meet])
    AG -- reserva --> CT
    APP --> LOG[(CRON · auditoría)]
    API[/API de lectura/] <--> ITHOS([ITHOS / Jarvis])
    APP --> API
```

## Motor de campañas

El envío es lo que más se rompe en una herramienta de outreach, así que cada decisión está
tomada por un motivo concreto.

```mermaid
flowchart TD
    L[Lista de destinatarios] --> P{Preparar cola}
    P -->|correo inválido| OM[Omitido]
    P -->|dado de baja| OM
    P -->|marcado «no enviar»| OM
    P -->|ya rebotó antes| OM
    P -->|repetido en la campaña| OM
    P -->|todo OK| Q[(Cola · pendiente)]
    CRON[[Cron cada 5 min]] --> V{¿Dentro de la ventana horaria<br/>y del límite diario?}
    V -->|no| ESP[Esperar]
    V -->|sí| B{¿Se dio de baja<br/>desde que se armó la cola?}
    B -->|sí| OM
    B -->|no| S[Enviar por SMTP]
    S -->|enviado| OK[Enviado + Message-ID guardado]
    S -->|5xx del servidor| RE[Rechazado · se marca el rebote]
    S -->|error temporal| RT[Reintento con espera creciente]
    OK --> PA[Pausa aleatoria 25–45 s]
    PA --> CRON
    IMAP[[Lectura IMAP cada 15 min]] --> RB[Rebotes y respuestas]
    RB --> CT[(Contactos actualizados)]
```

**Por qué así:**

- **Pausa aleatoria de 25 a 45 segundos.** Por debajo, el ritmo se vuelve robótico y el
  proveedor empieza a marcar spam; por encima, la campaña se hace eterna. Ese rango da unos
  80–100 correos por hora, dentro de lo que un humano podría escribir.
- **Un correo por campaña y por corrida del cron.** El ritmo lo marca el reloj del servidor,
  no un bucle que podría cortarse a la mitad.
- **`Message-ID` propio y guardado.** Es lo único que después permite cruzar un rebote o una
  respuesta con el envío exacto que lo originó.
- **Texto plano + HTML siempre.** Un correo sólo-HTML es una señal clásica de spam.
- **Baja obligatoria.** Enlace propio en el cuerpo y cabecera `List-Unsubscribe` con soporte
  de un clic. Si la plantilla se olvida de incluirlo, el sistema lo agrega igual.
- **Modo prueba real.** Mientras el sistema está en pruebas, ningún correo puede salir a un
  destinatario que no esté en la lista blanca interna.

La detección de rebotes reusa la lógica ya probada de Pulsemail: remitentes `mailer-daemon`,
`postmaster` y `mail-daemon`, más una heurística por asunto para los servidores que no firman
como daemon pero sí ponen «Delivery Status Notification» o «Correo no entregado». La dirección
que falló se extrae del DSN (`Final-Recipient`) y **sólo cuenta si pertenece a una de nuestras
campañas**: así los rebotes personales de la misma casilla no ensucian el CRM.

### Limpieza automática de la base

No es lo mismo un rebote pasajero que uno definitivo, y el sistema los distingue antes de
tocar nada:

| Tipo | Cómo se reconoce | Qué hace |
| --- | --- | --- |
| **Definitivo** | estado DSN `5.x.x`, código SMTP `5xx`, o frases como «user unknown», «does not exist», «domain not found» | marca el rebote, da de baja la dirección y **archiva el contacto** |
| **Pasajero** | estado DSN `4.x.x`, buzón lleno, greylisting, «try again later» | sólo marca el rebote; el contacto queda intacto |
| **Dudoso** | no se puede clasificar | se trata como pasajero |

Ante la duda nunca se archiva: dejar un contacto marcado y revisarlo después es barato;
archivar a alguien que sí existía, no.

Archivar **no es borrar**. El contacto va a la papelera, se ve por qué llegó ahí y se puede
restaurar con un clic durante **60 días** (configurable). Recién pasado ese plazo el cron lo
elimina definitivamente — es el único lugar del sistema que borra de verdad.

### Varias cuentas en paralelo

Cada campaña elige desde qué casilla sale. No es un lujo: **el tope diario y la reputación son
por remitente**. Repartir el envío entre cuentas multiplica el volumen sin forzar ninguna, y si
una campaña sale mal se quema esa casilla y no todas. El despachador abre una sesión SMTP por
cuenta y la reusa entre las campañas que la comparten; cuando una llega a su tope, esa campaña
espera al día siguiente y las demás siguen.

## Editor de campañas

El correo se arma con bloques —encabezado, portada de color, título, párrafo, lista, imagen,
botón, recuadro destacado, datos de contacto, firma, separador, espacio— que se reordenan
arrastrando y tienen sus propios colores, alineación y tamaños.

**La vista previa la renderiza el servidor**, con exactamente el mismo motor que usa el envío
real. No es una aproximación que después no coincide: es el correo. Se puede ver en escritorio
y en celular.

### Por qué el editor es más limitado que uno de páginas web

Porque el HTML de un correo no es el de una página, y lo que el editor no deja hacer es
justamente lo que rompería el mensaje:

| Regla | Motivo |
| --- | --- |
| Todo maquetado con **tablas** | Outlook usa el motor de Word: ignora flexbox, grid y `border-radius` |
| **CSS en línea**, nada de hojas externas | Gmail sanea el correo y descarta buena parte de las reglas |
| Botones con **respaldo VML** | Es la única forma de que Outlook los dibuje como botón y no como un enlace suelto |
| **Fuentes con respaldo a Arial** | Las fuentes web no cargan en la mayoría de los clientes |
| Sin imágenes de fondo, JavaScript, formularios ni video | No funcionan en el correo y además suben el puntaje de spam |
| Ancho fijo de **600 px** | Es el que entra bien en todos los clientes |
| Se genera **texto plano además del HTML** | Un correo sólo-HTML es una señal clásica de spam |

### Control de entregabilidad

Antes de mandar, el editor revisa el correo y explica **por qué** cada cosa importa:

- **Peso** — Gmail recorta arriba de 102 KB y muestra «mensaje recortado»: el destinatario no
  llega a ver el botón final.
- **Proporción texto/imagen** — pasado el 40 % de imagen los filtros lo miran con lupa.
- **Cantidad de enlaces** — la referencia es cerca de uno cada 100 palabras.
- **Asunto** — largo, mayúsculas, signos repetidos.
- **Palabras que disparan los filtros** — con tres o más, la llegada a la bandeja principal
  cae por debajo del 25 %.
- **Cosas que no funcionan en un correo** — JavaScript, formularios, video, flexbox, grid.
- **Baja, personalización y textos alternativos.**

### Plantillas prearmadas

| Plantilla | Para qué |
| --- | --- |
| **Outsourcing (la de Pulsemail)** | La campaña que ya venía funcionando, reproducida bloque por bloque |
| **Presentación sobria** | Casi todo texto, un botón, sin imágenes: la que mejor llega a la bandeja principal |
| **Novedad con imagen** | Anuncio de un servicio o caso de éxito, con suficiente texto alrededor |
| **Invitación a reunión** | Corta y directa al botón de la agenda; ideal como segundo contacto |

## Agenda de reuniones

Reemplazo propio de Calendly, sin costo ni dependencia externa.

```mermaid
flowchart LR
    V([Prospecto]) --> PUB[Página pública<br/>/r/usuario/tipo]
    HOR[Horario laboral del vendedor] --> CALC{Huecos disponibles}
    GC([Google Calendar · freeBusy]) --> CALC
    RES[(Reservas ya tomadas)] --> CALC
    CALC --> PUB
    PUB --> R[Reserva]
    R --> EV[Evento en Google Calendar<br/>+ Google Meet automático]
    R --> CT[Contacto creado o enlazado]
    R --> AC[Reunión en Actividades]
    R --> MAIL([Confirmación + recordatorio 24 h antes])
    R -. si vino de una campaña .-> CAM[Se le atribuye la reunión]
```

- **El cliente no necesita cuenta de nada.** Entra por el enlace que viene en el correo de la
  campaña, elige día y horario, y carga nombre, correo, empresa y teléfono. Nada de registrarse
  ni de iniciar sesión con Google: es el mismo flujo de Calendly, sin Calendly.
- **La reunión queda atribuida a la campaña.** El enlace que se inserta en cada correo lleva el
  identificador de la campaña, así se puede medir cuántas reuniones trajo cada envío.
- La disponibilidad no se inventa: se resta la **ocupación real** del calendario del vendedor,
  más un colchón configurable entre reuniones y una antelación mínima.
- Al confirmar se crea el evento con `conferenceData` y el enlace de **Google Meet** queda en
  el correo, en el calendario y en la ficha del contacto.
- Antes de guardar se vuelve a verificar el horario: si alguien tomó ese hueco mientras el
  visitante completaba el formulario, se avisa en vez de generar una reunión pisada.
- Si Google Calendar falla, **la reserva no se pierde**: queda en el CRM y el vendedor la ve
  con el aviso de que el evento no se creó.
- **Una sola invitación**, la del CRM, con el mismo identificador que el evento de Google: el
  cliente no recibe dos correos ni queda con dos eventos. Cambiar el horario o cancelar
  reemplaza esa misma invitación.
- **El cliente puede cambiar el horario** desde el correo (`/r/mover`), además de cancelar. Los
  dos enlaces sirven aunque sean de un correo viejo.
- **Protegida contra bots**: campo trampa, token de formulario firmado, límite de envíos por
  visitante y consentimiento obligatorio para el uso de los datos.
- **Feriados automáticos.** Los nacionales se sincronizan solos todos los días con el
  calendario oficial (decretados, puentes y trasladables reubicados), más los regionales de la
  jurisdicción configurada (CABA por defecto). Esos días no hay turnos ni se puede reservar, y
  si un feriado nuevo cae sobre una reunión ya agendada, quien la atiende recibe una alerta. Si
  la fuente no responde, la agenda sigue con lo que tenía. Se administran en Más → Feriados.
- La landing de la web reserva **en la misma agenda** por la API v1 (ver [API](#api)).

## Migración desde Zoho CRM

El importador está escrito contra la exportación real de Zoho, no contra un CSV ideal:

| Problema encontrado en la base real | Qué hace el importador |
| --- | --- |
| 12.249 nombres de empresa para 13.573 contactos | Crea las empresas **únicas** y les engancha los contactos |
| La misma provincia escrita de tres formas (`CABA`, `CIUDAD DE BUENOS AIRES`, `Ciudad de Buenos Aires`) | Normaliza contra las 24 jurisdicciones argentinas |
| País en mayúsculas y minúsculas, y URLs cargadas en el campo país | Normaliza y descarta lo que no es un país |
| Localidad con listas de aptitudes de LinkedIn o direcciones completas | Descarta lo que no parece una ciudad |
| `Fax` y `Conmutador` con el **mismo** número | Los unifica en un solo teléfono |
| 26 columnas 100 % vacías (tracking web, Twitter, enriquecimiento…) | Se ignoran |

Se puede **simular** la importación antes de escribir nada, y reimportar el mismo archivo
**actualiza** en vez de duplicar, porque se conserva el identificador original de Zoho.

### Unificación de las listas de campaña

Además de Zoho, el material incluye 15 listas sueltas (exportaciones de LinkedIn y de
plataformas de outreach, en tres formatos y dos codificaciones distintas). `bin/unificar_listas.py`
las funde con la base de Zoho en un solo archivo limpio:

- **Deduplica todo junto, Zoho incluido.** La base actual también tenía la misma persona
  cargada dos veces; limpiar sólo lo nuevo habría dejado el problema adentro.
- **La identidad es el correo, y la fusión es transitiva**: si A comparte una dirección con B
  y B con C, los tres son la misma persona. El perfil de LinkedIn y el par nombre + empresa
  funcionan como claves de respaldo.
- **Distingue una persona de una casilla compartida.** `info@`, `ventas@` o cualquier
  dirección que aparezca con dos apellidos distintos se detecta sola y **no** se usa para
  fusionar: dos personas que comparten la casilla de la empresa siguen siendo dos contactos.
- **Ordena los correos por calidad** (verificado antes que catch-all sin verificar) y arregla
  la geografía: una provincia extranjera ya no convive con «país: Argentina».

Resultado sobre el material real: **32.221 filas leídas → 29.338 contactos únicos**, con
2.883 duplicados fusionados y cero direcciones repetidas.
`test/verificar_unificacion.py` lo comprueba con 14 aserciones.

El mapa completo de módulos, campos y funcionalidades de Zoho —con marcas de qué usa hoy el
equipo comercial, verificado contra la base exportada— está en
[`md/ZOHO-CRM-REFERENCIA.md`](md/ZOHO-CRM-REFERENCIA.md).

## Auditoría

Cada movimiento del sistema queda registrado en la sección **CRON**, en dos canales:

- **Aplicación** — navegación, altas, ediciones, borrados, conversiones, importaciones,
  campañas, accesos y **todos los errores**, tanto del servidor como del navegador. Cada error
  lleva un código estable `ERR-XXXXXX` por archivo y línea, para poder buscarlo y arreglarlo.
- **API** — cada pedido de un sistema externo, con el token que lo hizo. Nunca los datos de
  la persona que llenó un formulario.

La IP de los visitantes de las páginas públicas se guarda como hash, no en claro.

El histórico **nunca se borra**: en pantalla se muestran los últimos cuatro meses salvo que se
pida una fecha anterior.

## API

API de lectura para que otros sistemas (por ejemplo **ITHOS/Jarvis**) consulten el estado del
CRM en tiempo real. Autenticación por `X-API-Key` o `Authorization: Bearer`; se guarda sólo el
SHA-256 del token y la clave completa se muestra una única vez.

```bash
curl -H "X-API-Key: crm_..." https://intellihelp.tech/crm/api/estado
```

Devuelve contactos, empresas, posibles clientes, negocios abiertos y su importe, actividades
pendientes y vencidas, campañas activas y correos en cola, reuniones próximas y la última
corrida del cron.

### API v1: lo que entra desde la web

La web (`intellihelp.tech/diagnostico/` y el formulario de contacto) le habla al CRM servidor a
servidor: el navegador nunca ve la clave. Contrato completo en
[md/INTEGRACION-WEB.md](md/INTEGRACION-WEB.md).

| Recurso | Para qué |
| --- | --- |
| `GET api/v1/disponibilidad?tipo=&vendedor=&desde=&hasta=` | Horarios libres del vendedor (hasta 31 días), sobre su Google Calendar real, y los feriados del rango (`feriados`: fecha y nombre) |
| `POST api/v1/reserva` | Reserva un horario: contacto, empresa, reunión con Meet y confirmación por correo. En un feriado, `409 feriado` |
| `POST api/v1/entrada` | Consulta sin reunión: contacto, empresa, nota y tarea de contestar para el responsable |

```mermaid
flowchart LR
    NAV([Navegador]) --> WEB[PHP de la web<br/>mismo origen]
    WEB -- pedido firmado --> API[CRM · API v1]
    API --> AG[Agenda<br/>bloqueo + hueco libre]
    API --> EW[(entradas_web<br/>página, canal, UTM)]
    AG --> RES[(Reserva + contacto<br/>+ empresa + reunión)]
    RES -. después de responder .-> GC([Google Calendar + Meet])
    RES -. después de responder .-> MAIL([Correo con la invitación])
```

- **Clave con permiso «Escritura (web)»** y un secreto que se muestra una sola vez y se guarda
  cifrado. Una clave de lectura no escribe.
- **Cada pedido va firmado**: `X-ITH-Signature = hex(HMAC-SHA256(secreto, ts + "\n" + MÉTODO +
  "\n" + ruta + "\n" + cuerpo))`, con `X-ITH-Key`, `X-ITH-Timestamp` (±5 minutos) y, en las
  escrituras, `X-ITH-Idempotency` y `X-ITH-Client-IP-Hash`. La ruta incluye la carpeta de la
  instalación: una firma de pruebas no sirve en producción.
- **Idempotente**: si la web reintenta, la reserva no se duplica; el mismo pedido recibe la
  respuesta original y la misma clave con otro pedido, `409`.
- **Límites** por clave y por visitante (hash de su IP, nunca la IP), con `429` y `Retry-After`.
- **Siempre JSON**, también en los errores: `{ "ok": false, "error": "horario_tomado", "mensaje": "…" }`.
  Los errores inesperados devuelven un código `ERR-XXXXXX`, nunca la traza.
- La pantalla **Entradas desde la web** muestra cada consulta y reserva con la página y el canal
  por el que llegó, y las métricas por canal: consultas, reuniones agendadas, realizadas y
  canceladas.

## Stack técnico

| Capa | Tecnología |
| --- | --- |
| Backend | PHP 8.1+ (front controller propio, sin framework ni Composer) |
| Base de datos | MySQL 8 / MariaDB 10.4+ (`utf8mb4`, columnas generadas) |
| Front-end | JavaScript sin build, CSS con variables de tema, fuente Saira |
| Autenticación | OAuth 2.0 (Google Workspace) · API por token con permisos |
| Correo | SMTP propio (sin dependencias) · IMAP para rebotes y respuestas |
| Calendario | Google Calendar API (`freeBusy` + `events.insert` con `conferenceData`) |
| Tareas periódicas | Cron del hosting (CLI o URL con clave) |
| Despliegue | SFTP/SSH con `bin/deploy.py` |

## Estructura del proyecto

```
app/
├─ web/                 # document root: index.php (router), vistas, assets
│  ├─ app/              # dominio: crm, negocios, actividades, campanas, envio,
│  │  │                 #   imap, agenda, gcal, google, auth, actividad, importador
│  │  ├─ pages/         # un controlador por ruta (incluye api/ y api/v1/)
│  │  └─ views/         # plantillas (layout + una por pantalla)
│  ├─ assets/           # css, js, imágenes
│  └─ .htaccess         # front controller + aislamiento del WordPress raíz
├─ db/                  # schema.sql y migraciones
├─ cron.php             # tareas periódicas (campañas, IMAP, reservas, recordatorios)
├─ migrar.php           # aplica una migración con la config de su instalación (CLI)
└─ server-config/       # config.example.php (el real no se versiona)
bin/                    # deploy.py (subida, migraciones, pruebas, verificación de sintaxis)
test/                   # run_tests.php · ui_test.php · smoke.sh · render.sh · api_test.php
                        #   + verificar_unificacion.py (238 comprobaciones en total)
md/                     # documento maestro, referencia de Zoho, requerimientos
docs/                   # banner y capturas de pantalla
```

## Puesta en marcha

Resumen; el detalle está en [INSTALL.md](INSTALL.md).

```bash
mysql -u USUARIO -p BASE < app/db/schema.sql        # esquema
cp app/server-config/config.example.php app/server-config/config.php
# completar credenciales de base y de Google

export CRM_SSH_HOST=... CRM_SSH_USER=... CRM_SSH_PASS=...
python bin/deploy.py config     # config.php fuera del web root (600)
python bin/deploy.py app        # la aplicación al web root
python bin/deploy.py check      # sintaxis PHP de lo desplegado
```

Y agendar la tarea automática cada 5 minutos:

```
*/5 * * * * /usr/bin/php /ruta/al/webroot/crm/cron.php >/dev/null 2>&1
```

## Tests

Regla del proyecto: **cada defecto detectado incorpora un test** para impedir su reaparición.

```bash
php test/run_tests.php               # 138 casos de lógica pura, sin base ni red
bash test/smoke.sh [url] [usr/tipo]  # comprobaciones HTTP contra el sitio desplegado
php  test/api_test.php               # la API v1 de punta a punta (sólo contra pruebas)
bash test/render.sh                  # 35 pantallas ejecutadas de verdad, con sesión
php  test/ui_test.php                # 57 reglas de interfaz, responsive y sesión
python test/verificar_unificacion.py # 14 aserciones sobre la base unificada
```

- **`run_tests.php`** cubre la normalización de la base de Zoho (con los casos reales que trae
  el CSV exportado), la validación de CUIT, el formato de importes, las URLs de la aplicación
  y de Google, la paginación, el renderizado de plantillas, la detección de rebotes, los slugs
  de la agenda, el vencimiento de actividades y el cifrado de los tokens de Google. Para la API
  v1: la firma (válida, vencida, alterada, de otra instalación), la validación de cada campo,
  la idempotencia, las consultas duplicadas, los límites de envíos, la ventana y los huecos de
  la agenda, el token del formulario y la invitación `.ics`. Para los feriados: la lectura del
  calendario oficial, el feriado nacional y el regional, el trasladable que se mueve, el puente,
  la fuente caída o incompleta, la reserva rechazada y el aviso al responsable. Lo que necesita base corre contra
  una SQLite en memoria; en local hace falta cargar `mbstring`, `openssl` y `pdo_sqlite`.
- **`smoke.sh`** verifica que **toda ruta privada exija sesión**, que las públicas respondan,
  que la API rechace sin clave, que la API v1 **rechace lo que no viene firmado**, que la agenda
  pública **descarte lo que completa el campo trampa** y que el código de la aplicación **no se
  pueda pedir por HTTP**.
- **`api_test.php`** prueba la API v1 por HTTP contra la instalación de pruebas: firma,
  horarios, reserva con su evento de Google y su correo, dos reservas simultáneas al mismo
  horario (gana una sola), consulta con su tarea, duplicados y límites. Se niega a correr fuera
  de pruebas, y al terminar cancela la reserva y borra todo lo que creó.
- **`render.sh`** arma una sesión de administrador y **ejecuta cada pantalla de verdad**. Es lo
  que atrapa una función inexistente o una variable que la vista espera y nadie pasó: cosas que
  `php -l` no puede ver. Ya encontró dos defectos antes de que llegaran a producción.
- **`verificar_unificacion.py`** comprueba la base unificada: que ningún correo quede repetido
  en dos fichas de la misma persona, que no se haya perdido ni un contacto de Zoho y que la
  geografía haya quedado normalizada. Encontró tres defectos del unificador, entre ellos que
  la primera versión **no deduplicaba Zoho contra sí mismo**.

## Seguridad

- Los secretos (base de datos, `client_secret` de Google, contraseña de aplicación del correo)
  viven en `config.php` —fuera del web root, con permisos `600`— o en la tabla de ajustes.
  Nunca se versionan.
- El `refresh_token` de Google de cada usuario se guarda **cifrado** (AES-256-GCM).
- El código de la aplicación no se sirve por HTTP: `app/` está bloqueado por `.htaccess`.
- Acceso restringido al dominio de la organización, con cierre de sesión por inactividad
  (10 min) y sesión máxima (8 h) **impuestos por el servidor**, no por el navegador.
- CSRF en todo formulario; cookies `HttpOnly`, `SameSite=Lax` y `Secure`.
- La base comercial de la empresa no está en el repositorio.
- Política completa en [SECURITY.md](SECURITY.md).

## Licencia

Software **propietario**, desarrollado **íntegramente por Brian Sierra**. Todos los derechos
reservados. Uso interno de la organización; se prohíbe su redistribución o uso fuera de ese
marco sin autorización expresa y por escrito del autor. El autor conserva su derecho moral de
reconocimiento de autoría (Ley 11.723 art. 52; Convenio de Berna art. 6 bis). Ver
[LICENSE](LICENSE).

## Autor

**Brian Sierra** — Administrador de sistemas (SysAdmin) en Intellihelp · [LinkedIn](https://www.linkedin.com/in/brian-gabriel-sierra/) · [GitHub](https://github.com/bsierra4262)

Diseño, desarrollo e implementación completos: modelo de datos, backend, motor de envío SMTP/IMAP, integración con Google Calendar y Meet, migración desde Zoho, front-end y despliegue.

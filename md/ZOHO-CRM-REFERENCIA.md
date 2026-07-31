# Zoho CRM — Referencia completa y mapa 1:1

> **Para qué sirve este documento.** Antes de escribir una línea del CRM propio, acá está *todo*
> lo que hace Zoho CRM: cada módulo, cada campo estándar, cada funcionalidad y cada opción de
> administración. La idea es construir la copia 1:1 adaptada a INTELLIHELP y que vos marques,
> sobre este mismo documento, **qué sobra**.
>
> Cada ítem lleva una marca:
>
> | Marca | Significado |
> |---|---|
> | 🟢 | **Lo usan hoy** — verificado contra la exportación real de Zoho (`Documentacion a consultar/CSV/DB Zoho.csv`, 13.573 contactos) |
> | 🟡 | **Probable** — hace falta para que el CRM funcione aunque no aparezca en el CSV |
> | ⚪ | **No lo usan hoy** — está en Zoho, pero en la base exportada está vacío o no aplica |
> | 🔵 | **Nuestro** — no existe en Zoho o lo hacemos distinto/mejor (agenda propia, campañas Pulsemail) |
>
> Fuentes oficiales consultadas al final ([§16](#16-fuentes)). Nada de lo que dice este documento
> está inventado: lo que no pude verificar contra documentación oficial está marcado como
> **«a definir con el cliente»**.
>
> Documentos relacionados: [DOCUMENTACION-MAESTRA.md](DOCUMENTACION-MAESTRA.md) · [REQUERIMIENTOS.md](REQUERIMIENTOS.md)

---

## Índice

1. [Cómo está organizado Zoho CRM](#1-cómo-está-organizado-zoho-crm)
2. [Módulos estándar](#2-módulos-estándar)
3. [Campos estándar módulo por módulo](#3-campos-estándar-módulo-por-módulo)
4. [Valores de lista (picklists)](#4-valores-de-lista-picklists)
5. [Qué se puede hacer con un registro](#5-qué-se-puede-hacer-con-un-registro)
6. [Vistas, filtros, búsqueda y acciones masivas](#6-vistas-filtros-búsqueda-y-acciones-masivas)
7. [Conversión de posibles clientes](#7-conversión-de-posibles-clientes)
8. [Automatización](#8-automatización)
9. [Email y comunicación](#9-email-y-comunicación)
10. [Reuniones y agenda](#10-reuniones-y-agenda)
11. [Reportes, tableros y pronóstico](#11-reportes-tableros-y-pronóstico)
12. [Administración y seguridad](#12-administración-y-seguridad)
13. [Zia (la IA de Zoho)](#13-zia-la-ia-de-zoho)
14. [Qué dice la base real del cliente](#14-qué-dice-la-base-real-del-cliente)
15. [Checklist para marcar qué sobra](#15-checklist-para-marcar-qué-sobra)
16. [Fuentes](#16-fuentes)

---

## 1. Cómo está organizado Zoho CRM

La jerarquía conceptual, de afuera hacia adentro:

```
Organización (la empresa)
└── Módulos            → Posibles clientes, Contactos, Cuentas, Negocios, Actividades…
    └── Diseños        → cada módulo puede tener varios "layouts" (formularios distintos)
        └── Secciones  → agrupaciones de campos dentro del formulario
            └── Campos → texto, lista, moneda, fecha, búsqueda (lookup), casilla…
                └── Registros → las filas concretas
                    ├── Listas relacionadas → notas, adjuntos, actividades, correos, negocios…
                    └── Línea de tiempo     → historial de todo lo que le pasó al registro
```

Encima de eso viven las **vistas** (listas guardadas con filtros), la **automatización**
(reglas que disparan acciones), los **reportes** y el **modelo de permisos** (roles + perfiles).

Dos conceptos de Zoho que conviene tener claros porque los vamos a replicar:

- **Módulo estándar vs. personalizado.** Zoho trae ~20 módulos de fábrica y deja crear otros.
  Nosotros arrancamos con los estándar que sirven y dejamos la puerta abierta a campos propios.
- **Campo de búsqueda (lookup).** Es la relación entre módulos: un Contacto *apunta* a una Cuenta.
  En nuestra base eso es una foreign key. Es lo que permite las "listas relacionadas".

---

## 2. Módulos estándar

| Módulo (Zoho ES) | Módulo (API) | Qué es | Marca |
|---|---|---|---|
| Posibles clientes | `Leads` | Contacto crudo sin calificar: entró por la web, una feria, una lista comprada | 🟡 |
| Contactos | `Contacts` | La persona con nombre, mail y teléfono | 🟢 |
| Cuentas | `Accounts` | La empresa a la que pertenece el contacto | 🟢 |
| Negocios | `Deals` | La oportunidad de venta con monto, etapa y fecha de cierre | 🟡 |
| Actividades | `Activities` | Paraguas de Tareas + Llamadas + Reuniones | 🟡 |
| · Tareas | `Tasks` | Pendiente con vencimiento y responsable | 🟡 |
| · Llamadas | `Calls` | Llamada hecha o programada, con duración y resultado | 🟡 |
| · Reuniones | `Events` | Evento con inicio/fin, lugar y participantes | 🟡 |
| Campañas | `Campaigns` | La campaña de marketing y su resultado | 🟢 |
| Productos | `Products` | Catálogo de lo que se vende | ⚪ |
| Listas de precios | `Price_Books` | Precios por cliente/volumen | ⚪ |
| Presupuestos | `Quotes` | Cotización enviada al cliente | ⚪ |
| Pedidos de venta | `Sales_Orders` | Orden de venta confirmada | ⚪ |
| Órdenes de compra | `Purchase_Orders` | Compra a proveedor | ⚪ |
| Facturas | `Invoices` | Factura emitida | ⚪ |
| Proveedores | `Vendors` | Proveedores | ⚪ |
| Casos | `Cases` | Ticket de soporte | ⚪ |
| Soluciones | `Solutions` | Base de conocimiento de soporte | ⚪ |
| Pronósticos | `Forecasts` | Cuota vs. cerrado vs. embudo por período | 🟡 |
| Documentos | `Documents` | Biblioteca de archivos | 🟡 |
| Visitantes | `Visits` | Tracking web (SalesIQ) | ⚪ |
| Redes sociales | `Social` | Menciones de Twitter/Facebook | ⚪ |
| Notas | `Notes` | Notas sueltas asociadas a cualquier registro | 🟡 |
| Adjuntos | `Attachments` | Archivos asociados a cualquier registro | 🟡 |

> **Comentario honesto.** Los módulos de inventario (Productos → Facturas) son un ERP chico
> metido adentro del CRM. INTELLIHELP **ya tiene** [APP Remitos](../../APP%20Remitos) para
> documentos comerciales y [APP Tickets](../../APP%20Tickets) para rendición de comprobantes.
> Replicarlos acá sería duplicar trabajo. Mi recomendación es dejarlos afuera y, si hace falta,
> **enlazar** el CRM con esas apps por API. Igual quedan documentados por si los querés.
>
> Lo mismo con **Casos/Soluciones**: eso es una mesa de ayuda, y esa función ya la cubre
> **GLPI** (con ITHOS encima). El **inventario** también está resuelto con un desarrollo
> propio del equipo. Si en algún momento conviene, el CRM se enlaza con ellos por su API en
> vez de duplicar los módulos.

**Módulos que agregamos nosotros (no existen en Zoho o los hacemos distinto):**

| Módulo | Qué es | Marca |
|---|---|---|
| Agenda de reuniones | Nuestro reemplazo de Calendly: página pública de reserva, disponibilidad real leída de Google Calendar, alta del evento y **Google Meet automático** | 🔵 |
| Campañas de correo | El motor de Pulsemail llevado a web: plantillas, listas, cola, envío por cron, rebotes, respuestas, desuscripción y estadísticas | 🔵 |
| CRON / Auditoría | La bitácora completa de la app (patrón de APP Tickets) | 🔵 |

---

## 3. Campos estándar módulo por módulo

> Fuente: documentación oficial de Zoho (*Standard Modules & Fields*). Los nombres van en
> castellano como los muestra Zoho en español, con el nombre técnico entre paréntesis.

### 3.1 Posibles clientes (Leads)

| Campo | Tipo | Marca | Nota |
|---|---|---|---|
| Propietario del posible cliente | Búsqueda (usuario) | 🟡 | Quién lo trabaja |
| Saludo | Lista | ⚪ | Vacío en la base real |
| Nombre | Texto (40) | 🟢 | |
| Apellidos | Texto (80) | 🟢 | **Obligatorio en Zoho** |
| Título | Texto (100) | 🟢 | Cargo |
| Empresa | Texto (100) | 🟢 | **Obligatorio en Zoho** |
| Fuente del posible cliente | Lista | ⚪ | Vacío en los 13.573 registros |
| Sector / Industria | Lista | 🟡 | |
| Ingresos anuales | Moneda (16 dec) | ⚪ | |
| Teléfono | Texto (30) | 🟢 | |
| Móvil | Texto (30) | 🟢 | |
| Fax | Texto (30) | 🟢 | *Ojo: en la base real lo usan como teléfono* |
| Correo electrónico | Email (100) | 🟢 | |
| Correo electrónico secundario | Email (100) | 🟢 | |
| ID de Skype | Texto (50) | ⚪ | 1 registro |
| Sitio web | URL (120) | 🟢 | |
| Estado del posible cliente | Lista | 🟡 | |
| Calificación | Lista | ⚪ | |
| N.º de empleados | Numérico (16) | ⚪ | |
| No participación del correo | Casilla | 🔵 | Lo necesitamos sí o sí para campañas (desuscripción) |
| Creado por / Modificado por | Fecha-hora + usuario | 🟢 | |
| Calle | Texto (250) | 🟢 | |
| Ciudad | Texto (30) | 🟢 | |
| Provincia / Estado | Texto (30) | 🟢 | |
| Código postal | Texto (30) | 🟢 | |
| País | Texto (30) | 🟢 | |
| Descripción | Área de texto (32.000) | 🟢 | |

### 3.2 Contactos

| Campo | Tipo | Marca |
|---|---|---|
| Propietario del contacto | Búsqueda (usuario) | 🟢 |
| Saludo | Lista | ⚪ |
| Nombre | Texto | 🟢 |
| Apellidos | Texto | 🟢 |
| Nombre de la cuenta | Búsqueda (Cuenta) | 🟢 |
| Nombre del proveedor | Búsqueda (Proveedor) | ⚪ |
| Fuente del posible cliente | Lista | ⚪ |
| Título | Texto | 🟢 |
| Departamento | Texto | 🟢 |
| Fecha de nacimiento | Fecha | ⚪ |
| Subordinado de | Búsqueda (Contacto) | ⚪ (7 registros) |
| Email opt-out | Casilla | 🔵 |
| ID de Skype | Texto | ⚪ |
| Teléfono / Móvil / Teléfono particular / Otro teléfono / Fax | Texto | 🟢 (Teléfono, Móvil, Fax) |
| Correo electrónico / secundario | Email | 🟢 |
| Asistente / Teléfono del asistente | Texto | ⚪ |
| Dirección de correspondencia (calle, ciudad, provincia, CP, país) | Texto | 🟢 |
| Otra dirección (calle, ciudad, provincia, CP, país) | Texto | ⚪ |
| Descripción | Área de texto | 🟢 |
| Creado por / Modificado por | Fecha-hora + usuario | 🟢 |

**Campos personalizados que ya crearon en Zoho** (aparecen en la exportación y hay que traerlos):

| Campo | Uso real | Marca |
|---|---|---|
| Correo electrónico terciario | 11 registros | 🟢 |
| Origen | 67 registros (mal cargado: tiene nombres de personas) | ⚪ |
| Teléfono Directo | 83 registros | 🟢 |
| **Conmutador** | **10.477 registros (77 %)** — es el teléfono principal de la empresa | 🟢 |
| Localidad | 9.179 (68 %) | 🟢 |
| Página Web | 9.457 (70 %) | 🟢 |
| Provincia | 9.694 (71 %) | 🟢 |
| LinkedIn Personal | 1.401 (10 %) | 🟢 |

### 3.3 Cuentas (empresas)

| Campo | Tipo | Marca |
|---|---|---|
| Nombre de la cuenta | Texto | 🟢 |
| Propietario de la cuenta | Búsqueda (usuario) | 🟢 |
| Sitio web | URL | 🟢 |
| Símbolo bursátil (ticker) | Texto | ⚪ |
| Cuenta principal | Búsqueda (Cuenta) | 🟡 |
| Empleados | Numérico | ⚪ |
| Propiedad | Lista | ⚪ |
| Sector / Industria | Lista | 🟡 |
| Tipo de cuenta | Lista | 🟡 |
| Número de cuenta | Numérico | ⚪ |
| Sitio de la cuenta | Texto | ⚪ |
| Teléfono / Fax / Email | Texto / Email | 🟢 |
| Calificación | Lista | ⚪ |
| Código SIC | Texto | ⚪ |
| Ingresos anuales | Numérico | ⚪ |
| Dirección de facturación (calle, ciudad, provincia, CP, país) | Texto | 🟡 |
| Dirección de envío (calle, ciudad, provincia, CP, país) | Texto | ⚪ |
| Descripción | Área de texto | 🟡 |

> 🔵 **Sugerencia nuestra para Argentina:** agregar **CUIT** (con validación módulo 11, ya la
> tenemos escrita en APP Tickets) y **Condición frente al IVA**. Zoho no los trae.

### 3.4 Negocios (oportunidades)

| Campo | Tipo | Marca |
|---|---|---|
| Propietario del negocio | Búsqueda (usuario) | 🟡 |
| Nombre del negocio | Texto | 🟡 |
| Nombre de la cuenta | Búsqueda (Cuenta) | 🟡 |
| Tipo | Lista | 🟡 |
| Fuente del posible cliente | Lista | 🟡 |
| Origen de campaña | Búsqueda (Campaña) | 🔵 (clave para medir el ROI de las campañas) |
| Nombre del contacto | Búsqueda (Contacto) | 🟡 |
| Importe | Moneda | 🟡 |
| Fecha de cierre | Fecha | 🟡 |
| Siguiente paso | Texto | 🟡 |
| Etapa | Lista | 🟡 |
| Probabilidad | Número (0-100) | 🟡 |
| Ingresos esperados | Moneda (calculado: importe × probabilidad) | 🟡 |
| Descripción | Área de texto | 🟡 |
| Creado / Modificado por | Fecha-hora + usuario | 🟡 |

### 3.5 Tareas

Propietario · Asunto · Fecha de vencimiento · Contacto/Posible cliente · Cuenta · Estado ·
Prioridad · Enviar correo de notificación · Recordatorio · Actividad recurrente · Descripción.

### 3.6 Reuniones (Events)

Propietario · Asunto · Fecha-hora de inicio · Fecha-hora de fin · Lugar · Contacto/Posible cliente ·
Cuenta · Enviar correo de notificación · Recordatorio · Actividad recurrente · Descripción ·
**participantes**.

> 🔵 Le agregamos: **enlace de Google Meet**, **ID del evento en Google Calendar** y
> **estado de confirmación del invitado** ([§10](#10-reuniones-y-agenda)).

### 3.7 Llamadas

Asunto · Tipo de llamada (entrante/saliente) · Propósito de la llamada · De/Para ·
Relacionado con · Detalles de la llamada · Hora de inicio · Duración · Resultado · Descripción.

### 3.8 Campañas

| Campo | Tipo | Marca |
|---|---|---|
| Propietario de la campaña | Búsqueda (usuario) | 🟢 |
| Nombre de la campaña | Texto | 🟢 |
| Tipo | Lista | 🟢 |
| Estado | Lista | 🟢 |
| Fecha de inicio / de fin | Fecha | 🟢 |
| Ingresos esperados | Moneda | 🟡 |
| Costo real / Costo presupuestado | Moneda | ⚪ |
| Respuesta esperada | Número | ⚪ |
| N.º enviados | Texto | 🟢 |
| Descripción | Área de texto | 🟢 |

> 🔵 Nuestra campaña además lleva: plantilla HTML, lista de destinatarios, remitente, CC,
> asunto, pausa entre envíos, límite diario, y las métricas reales de Pulsemail
> (enviados / rebotados / respondidos / reuniones agendadas / desuscriptos).

### 3.9 Módulos de inventario (⚪ recomendados para dejar afuera)

- **Productos:** nombre, código, activo, proveedor, categoría, fechas de venta, comisión,
  fabricante, precio unitario, gravable, soporte desde/hasta, unidad de uso, cantidades
  (pedida, en stock, en demanda), nivel de reposición, encargado, descripción.
- **Listas de precios:** nombre, activo, modelo de precios, rangos desde/hasta y descuento.
- **Presupuestos / Pedidos / Órdenes de compra / Facturas:** todos comparten el mismo esqueleto:
  propietario, número, asunto, cuenta, contacto, fechas, estado, transportista, direcciones de
  facturación y envío, **detalle de ítems** (producto, cantidad, precio unitario, precio de lista,
  total), impuestos, ajustes, total, términos y condiciones, descripción.
- **Proveedores:** nombre, teléfono, email, sitio web, cuenta contable, categoría, dirección.

### 3.10 Casos y Soluciones (⚪ recomendados para dejar afuera)

- **Casos:** número, producto, asunto, prioridad, estado, informado por, relacionado con, tipo,
  origen, email, cuenta, negocio, teléfono, motivo, descripción, comentarios internos, solución.
- **Soluciones:** número, título, producto, pregunta, respuesta, estado, comentarios.

---

## 4. Valores de lista (picklists)

Zoho trae valores de fábrica en cada lista y deja renombrarlos o agregar los propios.
**La documentación pública de Zoho no publica la lista literal de valores por defecto**, así que
acá van los valores que yo propongo — **ninguno está copiado a ciegas**: los de Provincia salen
de la base real del cliente, y el resto son los del estándar de la industria, para que vos los
ajustes.

| Lista | Valores propuestos |
|---|---|
| **Estado del posible cliente** | Sin contactar · Contactado · Intentamos contactar · Contactar más adelante · Precalificado · No calificado · Basura |
| **Fuente** | Campaña de correo · Sitio web · Referido de empleado · Referido externo · Llamada en frío · Feria/Evento · LinkedIn · Socio · Publicidad · Base comprada · Otro |
| **Calificación** | Caliente · Tibio · Frío |
| **Sector** | *(a definir con el cliente — el CSV no trae industria cargada)* |
| **Tipo de cuenta** | Cliente · Prospecto · Socio · Competidor · Distribuidor · Proveedor · Otro |
| **Etapa del negocio** | Calificación (10 %) · Relevamiento (20 %) · Propuesta enviada (40 %) · Negociación (60 %) · Verbal / A firmar (80 %) · **Ganado (100 %)** · **Perdido (0 %)** |
| **Tipo de negocio** | Cliente nuevo · Cliente existente · Renovación |
| **Estado de tarea** | No iniciada · En curso · Esperando a alguien · Aplazada · Completada |
| **Prioridad** | Muy alta · Alta · Normal · Baja · Muy baja |
| **Tipo de campaña** | Correo masivo · LinkedIn · Evento · Webinar · Publicidad · Referidos · Telemarketing · Otro |
| **Estado de campaña** | Planificada · Activa · Pausada · Completada · Cancelada |
| **Provincia** | Las 24 jurisdicciones argentinas (**tomadas de la base real**: CABA, Buenos Aires, Santa Fe, Córdoba, Mendoza, Río Negro, Entre Ríos, Tucumán, San Juan, Salta, Chubut, Neuquén, La Pampa, San Luis, Santa Cruz, Chaco, Corrientes, Formosa, La Rioja, Misiones, Santiago del Estero, Jujuy, Tierra del Fuego, Catamarca) + países extranjeros vistos: México, Chile, Colombia, Brasil, Perú, Uruguay, España |

> ⚠️ **Dato del análisis:** en la base real la provincia viene escrita de 3 formas distintas
> (`CIUDAD DE BUENOS AIRES`, `Ciudad de Buenos Aires`, `CABA`) y el país de 2
> (`ARGENTINA` / `Argentina`). La importación las **normaliza** a una sola forma.

---

## 5. Qué se puede hacer con un registro

Todo esto existe en Zoho para *cualquier* módulo. Es lo que hace que un CRM se sienta un CRM
y no una planilla:

| Función | Qué hace | Marca |
|---|---|---|
| Vista de detalle | Ficha completa del registro con todas sus secciones | 🟡 |
| **Listas relacionadas** | En la ficha del Contacto se ven sus negocios, actividades, correos, notas y adjuntos | 🟡 |
| **Línea de tiempo** | Historial cronológico: qué cambió, quién y cuándo | 🟡 (lo cubre nuestro CRON) |
| Notas | Notas con autor y fecha, editables | 🟡 |
| Adjuntos | Archivos y enlaces asociados | 🟡 |
| **Etiquetas (tags)** | Etiquetas libres, con color, filtrables y usables en automatizaciones | 🟡 |
| Seguir / dejar de seguir | Suscribirse a los cambios de un registro | ⚪ |
| Compartir registro | Dar acceso puntual a otro usuario | ⚪ |
| Bloquear registro | Impedir ediciones | ⚪ |
| Clonar | Duplicar el registro | 🟡 |
| Imprimir vista | Ficha imprimible | 🟡 |
| Eliminar → **Papelera** | Borrado lógico con recuperación (Zoho la guarda 60 días) | 🟢 (regla de oro: nada se borra físico) |
| Combinar duplicados | Fusionar 2 registros eligiendo qué campo gana | 🟡 |
| Convertir | Solo en Posibles clientes ([§7](#7-conversión-de-posibles-clientes)) | 🟡 |

---

## 6. Vistas, filtros, búsqueda y acciones masivas

| Función | Detalle | Marca |
|---|---|---|
| **Vistas de lista** | Listas guardadas con criterios, columnas y orden propios. Zoho trae «Todos», «Mis…», «Creados hoy», «Sin actividad» y deja crear las tuyas, públicas o privadas | 🟡 |
| Vista Kanban | Tarjetas agrupadas por una lista (típicamente etapa del negocio) | 🟡 |
| Vista Canvas | Diseño visual libre de la ficha | ⚪ |
| Filtros avanzados | Filtrar por campos, por actividad («sin tocar hace 30 días»), por correos («no abrió el último mail»), por etiquetas | 🟡 |
| Búsqueda global | Buscar en todos los módulos a la vez | 🟡 |
| Ordenar y paginar | | 🟡 |
| Selección múltiple | Casillas + «seleccionar todos los N» | 🟡 |
| **Actualización masiva** | Cambiar un campo en muchos registros de una (Zoho: hasta 500 por vista) | 🟡 |
| **Transferencia masiva** | Cambiar el propietario en lote | 🟡 |
| Borrado masivo | | 🟡 |
| **Correo masivo** | Mandar un mail a la selección | 🔵 (nuestro motor de campañas) |
| Exportar a CSV/Excel | | 🟢 |
| Importar desde CSV/Excel | Con mapeo de columnas, detección de duplicados y deshacer la importación | 🟢 |

---

## 7. Conversión de posibles clientes

Es *la* operación central del embudo de Zoho. Al convertir un posible cliente:

1. Se crea una **Cuenta** con la empresa (o se enlaza a una existente si el nombre coincide).
2. Se crea un **Contacto** con la persona.
3. Opcionalmente se crea un **Negocio** (nombre, monto, fecha de cierre, etapa).
4. Notas, adjuntos, actividades y correos del posible cliente **se llevan** al Contacto (y se
   elige a qué módulo van los adjuntos: Contacto, Cuenta o Negocio).
5. El posible cliente queda marcado como convertido y sale de la lista de trabajo.
6. El **mapeo de conversión** es configurable: define a qué campo de Cuenta/Contacto/Negocio va
   cada campo del posible cliente. Solo mapea entre tipos compatibles.

🟡 **Lo replicamos completo.** Es barato de implementar y es lo que le da sentido al embudo.

---

## 8. Automatización

| Función | Qué es | Marca |
|---|---|---|
| **Reglas de flujo de trabajo** | «Cuando pasa X, hacé Y»: al crear/editar un registro, al cambiar un campo, en una fecha. Acciones: mandar mail, crear tarea, actualizar campo, avisar, llamar un webhook | 🟡 |
| **Reglas de asignación** | Repartir posibles clientes entre vendedores (round-robin o por criterio) | 🟡 |
| **Reglas de puntuación (scoring)** | Sumar/restar puntos según campos y comportamiento (abrió el mail, respondió) | 🔵 muy útil con nuestras campañas |
| **Blueprint** | Proceso de venta obligatorio: en cada etapa, qué campos hay que completar y a qué etapa se puede pasar | ⚪ (mucha maquinaria; ver si hace falta) |
| **Aprobaciones** | Registros que necesitan visto bueno antes de entrar | ⚪ |
| **Reglas de validación** | Impedir guardar si un dato no cumple una condición | 🟡 |
| **Reglas de diseño (layout rules)** | Mostrar/ocultar campos según lo que se elige | 🟡 |
| **Macros** | Una serie de acciones aplicadas de a muchos registros con un clic | 🟡 |
| **Cadencias** | Secuencia automática de toques (mail día 1, llamada día 3, mail día 7) que se corta si el cliente responde | 🔵 encaja perfecto con el motor de campañas |
| **CommandCenter** | Orquestación de recorridos completos del cliente | ⚪ |
| Funciones personalizadas | Código propio (Deluge) disparado por reglas | ⚪ |
| **Programador / cron** | Tareas periódicas | 🟢 (nuestro cron de Hostinger) |

---

## 9. Email y comunicación

| Función | Qué es | Marca |
|---|---|---|
| Enviar correo desde el registro | Con historial en la ficha | 🟡 |
| **Plantillas de correo** | HTML con variables (`${Contacts.Nombre}`), organizadas por carpetas | 🟢 |
| Combinación de correspondencia (mail merge) | Documentos personalizados por registro | ⚪ |
| **Correo masivo** | Envío a una lista con límite diario | 🟢 → 🔵 lo hacemos con el motor de Pulsemail |
| **Análisis de plantillas** | Enviados / abiertos / clics / rebotes por plantilla | 🔵 |
| SalesInbox | Bandeja de entrada priorizada por etapa del negocio | ⚪ |
| Relay / autenticación de correo | SPF, DKIM, dominio propio | 🟡 |
| **Desuscripción** | Link de baja + campo «no participación del correo» respetado en todo envío | 🔵 **obligatorio** |
| **Rebotes** | Detección de rebotes y limpieza de la base | 🔵 (Pulsemail ya lo hace por IMAP) |
| **Detección de respuestas** | Marcar el contacto como «respondió» y avisar al vendedor | 🔵 |
| Notificaciones (SalesSignals) | Aviso en vivo cuando el cliente abre, responde o visita | 🟡 |
| Telefonía | Click-to-call, registro automático de llamadas | ⚪ |
| Redes sociales | Monitoreo de menciones | ⚪ |
| **Formularios web** | Formulario público que crea posibles clientes | 🟡 |
| Portales | Acceso limitado para clientes/socios | ⚪ |
| Chat en vivo | SalesIQ | ⚪ |

---

## 10. Reuniones y agenda

Zoho resuelve esto con **Zoho Bookings** / *Meeting Scheduler* (producto aparte) o integrando
Calendly. **Nosotros lo hacemos propio**, que es lo que pediste. Diseño:

| Pieza | Cómo funciona | Marca |
|---|---|---|
| Tipos de reunión | «Reunión de presentación 30 min», «Demo 45 min»: duración, margen entre reuniones, aviso mínimo, ventana de días | 🔵 |
| Disponibilidad | Horario laboral por usuario + **lectura de ocupación real de Google Calendar** (`freeBusy`) para no ofrecer horarios pisados | 🔵 |
| Página pública de reserva | `intellihelp.tech/crm/r/<usuario>/<tipo>` — **sin login y sin cuenta de Google**: el cliente entra desde el enlace del correo, elige día y hora y carga nombre, correo, empresa y teléfono | 🔵 |
| Alta del evento | Se crea el evento en **Google Calendar** del vendedor con el invitado, y se genera el **Google Meet automáticamente** (`conferenceData.createRequest` con `conferenceSolutionKey.type = "hangoutsMeet"` y `conferenceDataVersion=1`) | 🔵 |
| Vuelta al CRM | La reserva crea/enlaza el Contacto, deja la Reunión en Actividades y suma el evento a la línea de tiempo | 🔵 |
| Confirmaciones y recordatorios | Mail de confirmación con el link de Meet + recordatorio 24 h antes | 🔵 |
| Reprogramar / cancelar | Link propio en el mail; actualiza Google Calendar y el CRM | 🔵 |
| Atribución | Si la reserva vino de una campaña, queda asociada a esa campaña | 🔵 |

**Permisos de Google ya habilitados** en el cliente OAuth que pasaste: `calendar`,
`calendar.events`, `calendar.events.freebusy`, `calendar.app.created`, `calendar.calendarlist`,
`gmail.readonly`, `drive.file`, `userinfo.email`, `userinfo.profile`, `openid`.
Alcanzan para todo lo de arriba.

> ⚠️ **Pendiente a verificar en la implementación:** la creación de Meet requiere que el evento
> se cree en el calendario de una cuenta de Workspace con Meet habilitado, y la respuesta de la
> API puede volver con `status: pending` — hay que releer el evento hasta que quede `success`.
> Está documentado por Google; lo dejo anotado para no dar por sentado que sale a la primera.

---

## 11. Reportes, tableros y pronóstico

| Función | Qué es | Marca |
|---|---|---|
| Reportes estándar | Zoho trae ~40: embudo, negocios por etapa, actividad por vendedor, posibles clientes por fuente… | 🟡 |
| Reportes personalizados | Elegir módulo, columnas, filtros, agrupaciones y totales | 🟡 |
| Programar envío de reportes | Que llegue por mail todos los lunes | 🟡 |
| **Tableros (dashboards)** | Componentes: indicador, embudo, gráfico de barras/torta/línea, medidor, comparador, cohortes, KPI | 🟡 |
| **Pronóstico** | Cuota por vendedor/período vs. cerrado vs. embudo ponderado | 🟡 |
| Cuotas (quotas) | Objetivo mensual/trimestral por vendedor | 🟡 |
| Análisis de correo | Rendimiento de plantillas y campañas | 🔵 |
| Analítica móvil | | ⚪ |

---

## 12. Administración y seguridad

| Función | Qué es | Marca |
|---|---|---|
| **Usuarios** | Alta, baja, activo/inactivo, foto, zona horaria | 🟢 |
| **Roles** | Jerarquía (Director → Gerente → Vendedor). Define **qué registros ve** cada uno | 🟡 |
| **Perfiles** | Permisos por módulo y por acción (ver/crear/editar/borrar/exportar) | 🟡 |
| Grupos | Conjuntos de usuarios para compartir y asignar | ⚪ |
| **Reglas de compartición** | Abrir el acceso entre roles que no son jerárquicos | ⚪ |
| Seguridad a nivel de campo | Ocultar un campo a ciertos perfiles | 🟡 |
| Territorios | Segmentar la cartera por zona/industria | ⚪ |
| **Registro de auditoría** | Quién hizo qué y cuándo | 🟢 **el CRON, ya lo tenemos resuelto** |
| Importar / Exportar | CSV y Excel, con historial de importaciones y deshacer | 🟢 |
| **Copia de seguridad** | Backup completo descargable | 🟡 |
| Desduplicación | Buscar y combinar duplicados por email/teléfono/nombre | 🟡 |
| **Papelera de reciclaje** | Recuperar lo borrado | 🟢 |
| Sandbox | Copia de prueba de la configuración | ⚪ |
| Personalización de módulos y campos | Crear campos, secciones, diseños | 🟡 |
| Traducción / multi-idioma | | ⚪ (todo en castellano) |
| Multi-moneda | | 🟡 (ARS + USD, como en APP Tickets) |
| GDPR / privacidad | Consentimiento y derecho al olvido | 🟡 (relevante para campañas) |
| API REST + webhooks | | 🟡 (para enlazar con ITHOS/Jarvis, igual que Remitos y Tickets) |

---

## 13. Zia (la IA de Zoho)

Predicción de posibles clientes y negocios, mejor momento para contactar, detección de anomalías,
enriquecimiento de datos, asistente por voz, sugerencia de macros, análisis de sentimiento de
correos, detección de duplicados.

⚪ **Recomendación:** afuera de la v1. Si más adelante lo querés, lo natural es enchufarlo a
**ITHOS** (que ya tiene la cadena multi-IA armada) en vez de rehacerlo acá.

---

## 14. Qué dice la base real del cliente

Análisis de `Documentacion a consultar/CSV/DB Zoho.csv` — **13.573 contactos, 71 columnas**.

**Los 9 usuarios que trabajan la base:**

| Usuario | Contactos |
|---|---|
| Carlos Alberto Baldelli | 12.853 |
| Ailen Gimenez | 284 |
| Tamara Menedez | 139 |
| Guadalupe Lopez | 112 |
| Eliana Anahi Jerez | 72 |
| Ariana Bernachea | 60 |
| Raul Nasif | 20 |
| Mariana Mujica | 19 |
| Camila Germanetti | 14 |

**Cobertura de datos (lo que de verdad está cargado):**

| Dato | Cobertura |
|---|---|
| Apellidos · Propietario · Fechas | 100 % |
| Nombre | 99,8 % |
| Correo electrónico | 96,5 % |
| Nombre de Cuenta (empresa) | 90,2 % |
| Título (cargo) | 84,2 % |
| País | 82,5 % |
| Conmutador | 77,2 % |
| Domicilio | 73,1 % |
| Provincia | 71,4 % |
| Página Web | 69,7 % |
| Código postal | 70,3 % |
| Localidad | 67,6 % |
| Correo secundario | 58,1 % |
| Fax | 53,8 % |
| Teléfono | 13,2 % |
| LinkedIn Personal | 10,3 % |
| Móvil | 3,0 % |
| Departamento | 1,1 % |

**26 columnas están 100 % vacías** y se descartan en la migración: Fuente de posible cliente,
Proveedor, Fecha de nacimiento, Teléfono del asistente, Otro estado, Otro código postal,
No participación del correo, Saludo, Twitter, Etiqueta, todo el bloque de tracking web
(Primera visita, Puntuación visitante, Remitente, Permanencia, Visita más reciente, Primera
página visitada, N.º de chats, Días visitado), Cancelación de suscripción (modalidad y hora),
Locked, Enriquecimiento de datos (hora y estado), Connected To.

**Hallazgos que hay que resolver en la importación:**

1. **`Fax` y `Conmutador` traen el mismo número** en muchos registros — es el teléfono de la
   empresa cargado dos veces. Se unifica en un solo campo «Teléfono» y se deduplica.
2. **Provincia y País vienen sin normalizar** (`ARGENTINA`/`Argentina`, `CABA`/`CIUDAD DE BUENOS
   AIRES`/`Ciudad de Buenos Aires`). Se normaliza contra la lista de 24 jurisdicciones.
3. **`Origen` está mal usado**: tiene nombres de personas (`Eliana Jerez`), no fuentes. Se
   importa como nota, no como lista.
4. **`Ciudad para correspondencia` tiene basura**: algunas filas traen listas de aptitudes de
   LinkedIn (`Microsoft Word, Microsoft Excel, English…`) o direcciones completas. Se limpia.
5. **`País` tiene URLs** en 4 registros. Se limpia.
6. Hay **12.249 nombres de cuenta** para 13.573 contactos → al importar se crean las **Cuentas
   únicas** y se enlazan; no se duplican empresas.
7. El segundo CSV (`csv descargados.csv`, 16.243 filas) es la lista de campaña: Nombre,
   Apellidos, Cuenta y **hasta 4 correos**. Se cruza con la base por email para no duplicar.

---

## 15. Checklist para marcar qué sobra

> **Marcá con una X lo que NO usamos** y me lo devolvés; ajusto el alcance antes de construir.

### Módulos

- [ ] Posibles clientes (Leads)
- [ ] Contactos
- [ ] Cuentas (empresas)
- [ ] Negocios (embudo de ventas)
- [ ] Tareas
- [ ] Llamadas
- [ ] Reuniones
- [ ] Campañas
- [ ] Productos / Listas de precios
- [ ] Presupuestos / Pedidos / Órdenes de compra / Facturas
- [ ] Proveedores
- [ ] Casos / Soluciones (mesa de ayuda)
- [ ] Pronóstico y cuotas
- [ ] Documentos

### Funcionalidades

- [ ] Etiquetas
- [ ] Notas y adjuntos
- [ ] Vistas de lista personalizadas
- [ ] Vista Kanban del embudo
- [ ] Actualización y transferencia masiva
- [ ] Conversión de posible cliente
- [ ] Combinar duplicados
- [ ] Reglas de flujo de trabajo
- [ ] Reglas de asignación
- [ ] Puntuación de posibles clientes
- [ ] Blueprint (proceso obligatorio)
- [ ] Aprobaciones
- [ ] Cadencias (secuencias de contacto)
- [ ] Formularios web
- [ ] Reportes personalizados
- [ ] Tableros
- [ ] Roles y perfiles (permisos finos)
- [ ] Territorios
- [ ] API pública para ITHOS/Jarvis
- [ ] Zia / IA

### Lo nuestro

- [ ] Agenda propia de reuniones con Google Calendar + Meet
- [ ] Campañas de correo (motor Pulsemail en web)
- [ ] Detección de rebotes y respuestas por IMAP
- [ ] Desuscripción
- [ ] CRON / auditoría completa

---

## 16. Fuentes

- [Standard Modules & Fields — Zoho CRM](https://help.zoho.com/portal/en/kb/crm/customize-crm-account/customizing-fields/articles/standard-modules-fields)
- [Standard Fields in Leads — Zoho CRM](https://help.zoho.com/portal/en/kb/crm/customize-crm-account/customizing-fields/articles/standard-fields-leads)
- [Get Modules API — Zoho CRM API v8](https://www.zoho.com/crm/developer/docs/api/v8/modules-api.html)
- [Convert Lead — Zoho CRM API v8](https://www.zoho.com/crm/developer/docs/api/v8/convert-lead.html)
- [Lead Conversion Mapping — Zenatta](https://zenatta.com/lead-conversion-mapping-in-zoho-crm/)
- [Get Timeline of a Record — Zoho CRM API v8](https://www.zoho.com/crm/developer/docs/api/v8/timeline-of-a-record.html)
- [Working with Tags — Zoho CRM](https://help.zoho.com/portal/kb/articles/working-with-tags)
- [Zoho CRM Features](https://www.zoho.com/crm/features.html)
- [Zoho CRM Plus — Complete feature list](https://www.zoho.com/crm/crmplus/complete-feature-list.html)
- [Create events — Google Calendar API](https://developers.google.com/workspace/calendar/api/guides/create-events)
- Exportación real del cliente: `Documentacion a consultar/CSV/DB Zoho.csv` y `csv descargados.csv`

---

*Última actualización: 2026-07-30.*

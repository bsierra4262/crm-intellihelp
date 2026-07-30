# REQUERIMIENTOS PERMANENTES — INTELLIHELP · CRM

> Checklist de reglas fijas del cliente. **Se revisa en CADA iteración** para no saltear
> ninguna. Si el cliente pide algo nuevo permanente, se agrega acá.
> Última actualización: 2026-07-30.

---

## 🔴 Antes y después de CADA prueba (modo testing) — OBLIGATORIO

- [ ] **Vaciar la base de testing SIEMPRE** antes de entregar para probar:
  - Contactos / Cuentas / Posibles clientes / Negocios → borrar los de prueba.
  - **CRON vacío** (tabla `actividad` truncada) para registrar las pruebas nuevas.
  - **Campañas y envíos** de prueba borrados; ningún correo real encolado.
  - Adjuntos subidos borrados del disco, aparte de la base.
- [ ] **Ninguna campaña de prueba puede salir a un destinatario real.** En modo testing el
      motor sólo despacha a una lista blanca de correos internos.
- [ ] Esto se hace **hasta que el cliente diga que pasamos a producción 100 %**.

## 🟢 Base / plataforma

- [ ] **100 % gratis:** sin APIs pagas, sin registro, sin límites de consulta. Nada de Calendly,
      nada de servicios de envío pagos.
- [ ] Login **100 % Google Workspace** (dominio `intellihelp.tech`).
- [ ] Diseño **profesional**, **totalmente responsive**, **iconos SVG**.
- [ ] **Ventanas emergentes propias** (modal de marca, nunca `confirm()` del navegador).
- [ ] Solo se sube al servidor el contenido de **`app/`** (webroot `public_html/crm`).
- [ ] La app tiene que responder igual en `crm.intellihelp.tech` y en `intellihelp.tech/crm`.

## 🎨 Diseño / UI — profesional y CONSISTENTE (auditar, no parchar)

> Se audita **de raíz**, no sólo lo que el cliente señala. El módulo **Contactos** es el patrón
> de referencia; todo lo nuevo lo copia. Guardado por tests (sección «diseño/UI»).

- [ ] **REPLICAR la sección ya bien diseñada.** Misma estructura: `page-head` con `<h1>` +
      `.head-actions`, filtros `.filters/.filter-grid` con Filtrar + Limpiar, tabla
      `card table-card`, formulario con `.sec`. Sólo cambian campos y lógica.
- [ ] **Responsive sin scroll horizontal en NINGÚN ancho.** El menú colapsa a drawer.
- [ ] **Sin controles nativos crudos:** on/off con toggle `.switch`, selects con combobox,
      fechas con datepicker. Nunca `style=` en línea.
- [ ] **Badges** siempre con variante (`badge-ok` / `badge-info` / …), nunca `.badge` pelado.
- [ ] **Nada de código ni jerga de desarrollador en la UI** fuera de API y CRON. Ni rutas de
      archivos, ni `config.php`, ni endpoints. Lenguaje simple en pantallas de negocio.
- [ ] **Colores y espaciados por variables CSS** (`var(--…)`), nunca hex crudo ni px a ojo.
- [ ] Tablas en `.table-scroll` con `data-label` + `prio-*` (modo tarjeta en ≤768 px).

## 📇 CRM

- [ ] **Copia 1:1 de Zoho CRM adaptada a INTELLIHELP.** El mapa está en
      [ZOHO-CRM-REFERENCIA.md](ZOHO-CRM-REFERENCIA.md); el cliente marca ahí qué sobra.
- [ ] La migración desde el CSV de Zoho **no duplica** cuentas ni contactos y **normaliza**
      provincia, país y localidad.
- [ ] **Nada se borra físico:** borrado lógico + papelera.
- [ ] Toda pantalla de listado tiene búsqueda, filtros, orden, paginación y exportación.

## ✉️ Campañas

- [ ] El motor **corre en el servidor** (cola + cron): la campaña sigue con el navegador cerrado.
- [ ] **Pausa aleatoria 25–45 s** entre correos (fuera de ese rango Gmail marca spam).
- [ ] **Retomar sin duplicar:** si se corta, sigue por donde quedó.
- [ ] **Rebotes y respuestas por IMAP**, con la misma lógica de Pulsemail
      (`mailer-daemon` / `postmaster` / heurística de asunto).
- [ ] **Desuscripción obligatoria** en todo correo, y respetada en todo envío posterior.
- [ ] Nunca se le escribe dos veces al mismo contacto en la misma campaña.

## 📅 Agenda

- [ ] **Herramienta propia**, sin Calendly ni Cal.com.
- [ ] La disponibilidad se calcula contra la **ocupación real de Google Calendar**.
- [ ] Al reservar se crea el evento en Google Calendar **con Google Meet automático**.
- [ ] Confirmación y recordatorio por correo, con enlaces propios para reprogramar y cancelar.

## ✅ Proceso de trabajo (dev)

- [ ] Mantener **[DOCUMENTACION-MAESTRA.md](DOCUMENTACION-MAESTRA.md)** actualizada en cada cambio.
- [ ] **Un test de regresión por bug** (batería en `test/`).
- [ ] Validar PHP (`php -l`) y JS antes de desplegar.
- [ ] Primero **investigar a fondo**, después aplicar. Ser honesto con lo que se puede y lo que no.
- [ ] **Sin secretos en el repositorio.** `config.php` fuera del webroot y fuera de git.

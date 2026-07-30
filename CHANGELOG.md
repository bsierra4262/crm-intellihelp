# Changelog

Todos los cambios notables de este proyecto se documentan acá.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/)
y el versionado, [SemVer](https://semver.org/lang/es/).

## [Unreleased]

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
- **Tests**: 85 pruebas unitarias sin base ni red + 48 comprobaciones HTTP de rutas, permisos
  y assets contra el sitio desplegado.

### Security

- El código de la aplicación no se sirve por HTTP (`app/` bloqueado por `.htaccess`).
- El `refresh_token` de Google se guarda cifrado con AES-256-GCM.
- CSRF en todo formulario; cookies `HttpOnly` + `SameSite=Lax` + `Secure`.
- **Modo prueba**: ninguna campaña puede alcanzar a un destinatario real.

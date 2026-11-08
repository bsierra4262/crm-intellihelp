# Política de seguridad

## Reporte de vulnerabilidades

Ante una vulnerabilidad de seguridad, **no debe abrirse un issue público**. El reporte se
realiza de forma privada a **bsierra@intellihelp.tech**.

Se solicita incluir, en la medida de lo posible: descripción, pasos de reproducción, impacto
y una posible mitigación. El reporte recibirá respuesta a la brevedad.

## Manejo de secretos y datos

- Las credenciales reales (base de datos, `client_secret` de Google, contraseña de aplicación
  del correo) residen en `config.php` o en la tabla de ajustes; **no se versionan** y, en
  producción, `config.php` se ubica **fuera del web root** con permisos `600`.
- El `refresh_token` de Google de cada usuario se guarda **cifrado** (AES-256-GCM) con el
  secreto de la aplicación.
- El repositorio **no contiene la base comercial** de la empresa: las exportaciones de Zoho y
  cualquier `.csv` están excluidos por `.gitignore`.
- El código de la aplicación no se sirve por HTTP: `app/` está bloqueado por `.htaccess`, y el
  front controller es el único punto de entrada.
- El acceso se restringe al dominio de la organización mediante Google Workspace (`hd`), con
  cierre de sesión por inactividad (10 min) y sesión máxima (8 h) impuestos por el servidor.
- Todo formulario lleva token **CSRF**; las cookies de sesión son `HttpOnly`, `SameSite=Lax`
  y `Secure` bajo HTTPS.
- **Nada se borra de forma permanente**: el borrado es lógico y queda recuperable desde la
  papelera, con la operación registrada en la auditoría.

## Envío de correo

- Toda campaña incluye enlace de baja y cabecera `List-Unsubscribe`; las bajas se respetan
  antes de encolar y también en el momento del envío.
- En **modo prueba** el motor sólo despacha a una lista blanca interna: ningún destinatario
  real puede recibir un correo de prueba.

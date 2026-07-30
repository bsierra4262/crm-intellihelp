# Puesta en marcha

Guía completa de instalación sobre hosting web (Apache/LiteSpeed + PHP 8.1+ + MySQL/MariaDB).
El sistema no usa Composer ni ningún paso de compilación: se sube y funciona.

---

## 1. Requisitos

| Componente | Mínimo | Por qué |
| --- | --- | --- |
| PHP | 8.1 | tipos, `str_contains`, `match` |
| Extensiones | `pdo_mysql`, `curl`, `openssl`, `mbstring`, `imap` | base, Google API, cifrado de tokens, lectura de rebotes |
| Base de datos | MySQL 8 / MariaDB 10.4 | `utf8mb4`, columnas generadas |
| Servidor web | Apache o LiteSpeed con `mod_rewrite` | front controller |

`imap` sólo hace falta para detectar rebotes y respuestas: sin ella el resto del sistema
funciona igual.

---

## 2. Base de datos

```bash
mysql -h localhost -u USUARIO -p BASE < app/db/schema.sql
```

El esquema crea las 32 tablas, el embudo por defecto («Ventas», siete etapas) y los ajustes
iniciales. **No inserta datos de negocio**: la base arranca vacía.

---

## 3. Configuración

Copiar la plantilla y completarla:

```bash
cp app/server-config/config.example.php app/server-config/config.php
```

En producción, `config.php` va **fuera del web root**, con permisos `600`:

```
/home/USUARIO/crm_config/config.php
```

El `.htaccess` de la aplicación ya apunta ahí con `SetEnv CRM_CONFIG`. Ajustar la ruta si el
usuario del hosting es otro.

Generar el secreto de la aplicación (cifra los tokens de Google):

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

---

## 4. Credenciales de Google

En [Google Cloud Console](https://console.cloud.google.com/) → **API y servicios** →
**Credenciales**, sobre un cliente OAuth de tipo *aplicación web*:

1. **URI de redireccionamiento autorizado** (exacto, sin barra final):

   ```
   https://crm.intellihelp.tech/google-callback
   ```

2. **Permisos** habilitados en la pantalla de consentimiento:

   | Permiso | Para qué |
   | --- | --- |
   | `openid`, `userinfo.email`, `userinfo.profile` | login |
   | `calendar.events` | crear la reunión y el Google Meet |
   | `calendar.events.freebusy` | ver qué horarios están ocupados |

3. Tipo de aplicación **Interna** y dominio de la organización, para que sólo entren las
   cuentas del dominio.

El `client_id` y el `client_secret` van en `config.php`.

---

## 5. Subir la aplicación

Sólo se publica el contenido de **`app/web/`**, más `app/cron.php` al lado:

```
public_html/crm/          ← contenido de app/web/
public_html/crm/cron.php  ← app/cron.php
```

Con el script incluido:

```bash
export CRM_SSH_HOST=... CRM_SSH_PORT=65002 CRM_SSH_USER=... CRM_SSH_PASS=...
python bin/deploy.py config    # config.php fuera del web root (600)
python bin/deploy.py app       # la aplicación al web root
python bin/deploy.py check     # verifica la sintaxis PHP de lo desplegado
```

Las credenciales también pueden ir en `bin/deploy.local.json` (ignorado por git).

### Convivencia con WordPress en el mismo hosting

Si el dominio raíz corre WordPress con Wordfence, el `.htaccess` de la aplicación ya:

- desactiva el WAF para esta carpeta (`php_value auto_prepend_file none`);
- define su propio `RewriteEngine`, que **no hereda** las reglas del directorio padre.

Con eso, `crm.intellihelp.tech` y `intellihelp.tech/crm` funcionan sin tocar el WordPress.
La ruta base se deduce en tiempo de ejecución, así que el mismo código responde bien por
las dos direcciones.

---

## 6. Tarea automática (cron)

Agendar en el panel del hosting, **cada 5 minutos**:

```
*/5 * * * * /usr/bin/php /home/USUARIO/domains/DOMINIO/public_html/crm/cron.php >/dev/null 2>&1
```

La tarea despacha los correos de las campañas, lee los rebotes y las respuestas (cada 15
minutos) y manda los recordatorios de las reuniones.

Si el hosting sólo permite cron por URL, cargar una clave en **Ajustes → Clave del cron** y
llamar a:

```
https://crm.intellihelp.tech/cron.php?clave=LA_CLAVE
```

---

## 7. Primer ingreso

1. Entrar a `https://crm.intellihelp.tech`.
2. Ingresar con la cuenta de Google del dominio. **El primer usuario queda como
   administrador** automáticamente; el resto entra como Comercial.
3. Ir a **Ajustes** y cargar:
   - la cuenta de correo de salida (con **contraseña de aplicación** de Google, no la normal);
   - los destinatarios permitidos mientras el sistema está en **modo prueba**.
4. Ir a **Agenda** y conectar Google Calendar si el navegador no lo pidió en el login.
5. Ir a **Importar** y subir el CSV de Zoho, primero con **Simular** activado.

---

## 8. Verificación

```bash
php test/run_tests.php     # lógica pura: 85 pruebas, sin base ni red
bash test/smoke.sh         # 48 comprobaciones HTTP contra el sitio desplegado
```

Ambas salen con código `0` si todo está bien.

# Puesta en marcha

Guía completa de instalación sobre hosting web (Apache/LiteSpeed + PHP 8.1+ + MariaDB).
El sistema no usa Composer ni ningún paso de compilación: se sube y funciona.

En el hosting conviven dos instalaciones, cada una con su carpeta, su configuración y su base:
`public_html/crm` (producción) y `public_html/crm-pruebas` (pruebas). Todo lo que sigue vale
para las dos; donde difieren, se indica.

---

## 1. Requisitos

| Componente | Mínimo | Por qué |
| --- | --- | --- |
| PHP | 8.1 (el hosting usa 8.3) | tipos, `str_contains`, `match` |
| Extensiones | `pdo_mysql`, `mbstring`, `openssl`, `curl`, `dom` | base, textos UTF-8, cifrado de tokens, Google Calendar y feriados, saneado del HTML de los correos entrantes |
| Extensión para las pruebas | `pdo_sqlite` | el bloque de pruebas con base de `run_tests.php` (ver §8) |
| Base de datos | MariaDB 10.5 (el hosting usa 11.8) | `utf8mb4`, columnas generadas; las migraciones usan sintaxis de MariaDB |
| Servidor web | Apache o LiteSpeed con `mod_rewrite` | front controller |

Sin `dom`, los correos entrantes se muestran como texto plano. La lectura de rebotes y
respuestas usa un cliente IMAP propio: no hace falta la extensión `imap`.

`schema.sql` también se puede importar en MySQL 8, pero las migraciones 005–009, 013, 016,
018 y 019 usan `ADD COLUMN/INDEX IF NOT EXISTS`, que sólo existe en MariaDB.

---

## 2. Base de datos

### Instalación nueva

Alcanza con **`schema.sql`**, sobre una base vacía:

```bash
mysql -h localhost -u USUARIO -p BASE < app/db/schema.sql
```

o, desde la máquina local, con el script (ver §5):

```bash
export CRM_DB_PASS_PRUEBAS=...            # o CRM_DB_PASS_PRODUCCION
python bin/deploy.py schema --pruebas     # o --produccion
```

El esquema crea las **42 tablas**, el embudo por defecto («Ventas», siete etapas) y los ajustes
iniciales, e incluye todas las migraciones hasta la 019: **no hay que correr ninguna después**
(si se corren, no cambian nada). **No inserta datos de negocio**: la base arranca vacía.

`deploy.py schema` se niega si la base ya tiene tablas, toma la base y el usuario sólo de la
configuración del destino y pasa la contraseña (`CRM_DB_PASS_PRUEBAS` /
`CRM_DB_PASS_PRODUCCION`, o `CRM_DB_PASS`) en un archivo temporal con permisos `600`, nunca en
la línea de comandos.

### Instalación existente: migraciones

Una base armada antes se actualiza con las migraciones que le falten, **en orden** y siempre con
un respaldo previo en producción. Todas son idempotentes.

| N.º | Qué hace |
| --- | --- |
| 002–007 | Campañas con varias casillas, alertas, conversaciones, firma, normalización y aprobación de fichas |
| 008 | `entradas_web` y `api_limites` (API v1 de la web) |
| 009 | `feriados` |
| 010 | `notificacion_lecturas`: alertas del equipo leídas por persona |
| 013 | `agenda_reservas.gcal_pendiente`: reintentos de Google al mover o cancelar |
| 015 | `bajas_tokens`; IP como hash (`VARCHAR(64)`) en `envio_eventos` y `desuscripciones`; ajuste `testing_lista_blanca` |
| 016 | `imap_estado.uidvalidity`; índices de `mensajes`; `smtp_pass` con valor por defecto |
| 017 | Vuelve a encender los avisos de sistema (`avisar_sistema`) y marca como avisadas las alertas viejas sin mandar |
| 018 | Índices de `envios`, `listas` y `campanas`; `remitente_vivo` (una casilla dada de baja se puede volver a cargar). Requiere 002–007 |
| 019 | Claves foráneas de `envios`, `lista_miembros` y `mensajes`; idempotencia de la agenda pública (`token_clave`); saca `imap_ultimo_uid`; borra las contraseñas viejas en texto plano si hay casilla. Requiere 002–018 |

Los números 011, 012 y 014 no existen. Orden para una instalación que ya tenía hasta la 009:

```bash
python bin/deploy.py app --pruebas        # primero el código (sube también migrar.php)
for n in 010-lecturas-alertas 013-agenda-google-pendiente 015-campanas-bajas \
         016-correo-entrante 017-avisar-sistema 018-indices-casillas 019-integridad 020-ithub; do
  python bin/deploy.py migrar app/db/migracion-$n.sql --pruebas
done
python bin/deploy.py check --pruebas
```

`deploy.py migrar` sube el `.sql` fuera del webroot y lo aplica `migrar.php` con la
configuración de esa instalación: no pide credenciales y no puede equivocarse de base. Con
`--simular` sólo lista las sentencias. El código anda sin la 010, la 013 y la 015 (con lo que
agregan apagado) y responde `503 migracion_pendiente` en la API si falta una tabla, así que
subir el código antes de migrar no rompe nada.

---

## 3. Configuración

Copiar la plantilla y completarla:

```bash
cp app/server-config/config.example.php app/server-config/config.php        # producción
cp app/server-config/config.example.php app/server-config/crm-pruebas.php   # pruebas
```

En el servidor, cada archivo va **fuera del web root**, con permisos `600`:

```
/home/USUARIO/crm_config/config.php        ← producción (carpeta crm/)
/home/USUARIO/crm_config/crm-pruebas.php   ← pruebas (carpeta crm-pruebas/)
```

`bootstrap.php` elige el archivo por el nombre de la carpeta de la instalación; el `.htaccess`
ya no fija `CRM_CONFIG`. La carpeta `crm_config/` se deduce de la ruta de la instalación
(`/home/USUARIO/domains/…` → `/home/USUARIO/crm_config`) o, si no, de `HOME`. Si el hosting la
tiene en otro lado, se indica con la variable de entorno **`CRM_CONFIG_DIR`**.

Cada instalación tiene su propia base, su propio `storage` y su propio `secret`; no se copian de
una a otra.

- **`storage` es obligatorio** en la práctica y tiene que quedar fuera del web root (por ejemplo
  `/home/USUARIO/crm_storage`): sin él, los adjuntos irían al lado de la instalación, que en
  Hostinger queda dentro de `public_html`.
- **`secret`** cifra los tokens de Google y firma el hash de las IP; la API v1 exige 32
  caracteres o más. Si falta, el sistema genera uno al azar en `STORAGE/.secreto-app`, pero
  conviene definirlo. Para generarlo:

  ```bash
  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
  ```

- **Sección `'pruebas'`** (sólo en `crm-pruebas.php`): los datos de personas reales que
  `reinicio.php` usa para sembrar los contactos de prueba (responsable, correo externo,
  teléfonos). No están en el código porque el repositorio es público; también pueden cargarse
  como ajustes `pruebas_<clave>`. Lo que falte no se siembra.
- **`'mail' => ['desviar_a' => '…']`** (sólo en `crm-pruebas.php`, nunca en producción): todo
  correo que salga de esa instalación —campañas, respuestas, agenda, avisos— le llega únicamente
  a esa casilla, sin copias y con el destinatario original en el asunto (`[PRUEBAS → …]`) y en la
  cabecera `X-CRM-Desviado-De`; los invitados de Google Calendar se reemplazan por ella. Está en
  el archivo y no en la base para que copiar o reiniciar la base de pruebas no lo apague. La
  pantalla de Campañas avisa cuando está activo.

---

## 4. Credenciales de Google

En [Google Cloud Console](https://console.cloud.google.com/) → **API y servicios** →
**Credenciales**, sobre un cliente OAuth de tipo *aplicación web*:

1. **URI de redireccionamiento autorizado** (exacto, sin barra final):

   ```
   https://intellihelp.tech/crm/google-callback
   ```

2. **Permisos** habilitados en la pantalla de consentimiento:

   | Permiso | Para qué |
   | --- | --- |
   | `openid`, `userinfo.email`, `userinfo.profile` | login |
   | `calendar.events` | crear la reunión y el Google Meet |
   | `calendar.events.freebusy` | ver qué horarios están ocupados |

3. Tipo de aplicación **Interna** y dominio de la organización, para que sólo entren las
   cuentas del dominio.

El `client_id` y el `client_secret` van en la configuración de cada instalación.

### Ingreso con ITHub (sesión única)

Con ITHub, el CRM no tiene login propio: `/login` manda a ITHub y, si la persona ya entró ahí,
pasa sin ver ninguna pantalla. El acceso y el rol (`admin` o `comercial`) se administran en
**ITHub → Permisos**. Google queda sólo para **Conectar Google Calendar**, que cada persona hace
una vez desde la agenda (ya no se ve la pantalla de permisos de Google en cada ingreso).

1. En **ITHub → Aplicaciones → CRM**:

   | Campo | Valor |
   | --- | --- |
   | URIs de vuelta | `https://intellihelp.tech/crm/ithub-callback` (y la de `crm-pruebas`) |
   | URIs después de salir | `https://intellihelp.tech/crm/login` (y la de `crm-pruebas`) |
   | Aviso de cierre | `https://intellihelp.tech/crm/ithub-logout` |

   y **Generar secreto** (se muestra una sola vez).

2. En la configuración de la instalación, la sección `ithub` (ver `config.example.php`) con
   `client_id` `crm` y ese secreto.

3. Migración **020** (`ithub_sesiones_cerradas`): ahí anota `ithub-logout` las sesiones que
   ITHub avisa cerradas, y el CRM corta las suyas en el pedido siguiente.

Sin la sección `ithub`, el CRM sigue entrando con Google como siempre.

---

## 5. Subir la aplicación

Se publica el contenido de **`app/web/`** y, en su raíz, `cron.php`, `reinicio.php` y
`migrar.php` (los dos últimos sólo responden por línea de comandos; por HTTP dan 404):

```
public_html/crm/              ← contenido de app/web/
public_html/crm/cron.php      ← app/cron.php
public_html/crm/migrar.php    ← app/migrar.php
public_html/crm/reinicio.php  ← app/reinicio.php
```

Con el script incluido. **Todo comando lleva el destino escrito a propósito**: `--pruebas` o
`--produccion`. Sin destino, con los dos o con uno desconocido (`--prueba`), termina con error
(código 2) sin tocar nada.

```bash
export CRM_SSH_HOST=... CRM_SSH_PORT=65002 CRM_SSH_USER=... CRM_SSH_PASS=...
python bin/deploy.py config --pruebas     # app/server-config/crm-pruebas.php → crm_config/crm-pruebas.php
python bin/deploy.py config --produccion  # app/server-config/config.php → crm_config/config.php
python bin/deploy.py app --pruebas        # la aplicación al web root de pruebas
python bin/deploy.py app --pruebas --volver   # si algo salió mal: vuelve a la versión anterior
python bin/deploy.py check --pruebas      # verifica la sintaxis PHP de lo desplegado
```

- `config` verifica que el nombre de la base del archivo coincida con el destino.
- `app` excluye los secretos por patrón (`config.php`, `config.*.php`, `*.local.php`,
  `crm-pruebas.php`, `.env`, claves, etc.). Con **`--limpiar`**
  además lista lo que hay en el servidor y ya no está en el repositorio y, con confirmación, lo
  mueve a `~/crm_retirados` (no borra). Mientras dura la subida conviven archivos viejos y
  nuevos.
- **Clave del servidor SSH:** se compara con `~/.ssh/known_hosts` o `bin/deploy.known_hosts`. La
  primera vez, o si el servidor cambió de clave de verdad, se acepta con
  **`--aceptar-clave-nueva`**: muestra la huella, pide confirmarla y la guarda.
- Las credenciales también pueden ir en `bin/deploy.local.json` (ignorado por git).
- `python bin/deploy.py --help` lista todos los comandos (`app`, `config`, `schema`, `migrar`,
  `run`, `test`, `check`).

### Convivencia con WordPress en el mismo hosting

Si el dominio raíz corre WordPress con Wordfence, el `.htaccess` de la aplicación ya:

- desactiva el WAF para esta carpeta (`php_value auto_prepend_file none`);
- define su propio `RewriteEngine`, que **no hereda** las reglas del directorio padre;
- niega por HTTP respaldos y archivos sueltos (`.sh`, `.bak`, `.old`, `.orig`, `.swp`, `.dist`,
  `.env`, `.txt`, `.gz`, `.zip`, `.tar`, `.yaml`, archivos ocultos, `config.local.php`).

Queda una cosa que **no** se arregla desde la aplicación: el WordPress del raíz atiende una parte
de los pedidos a `/crm` (baja de suscripción, píxel, imágenes). Hay que excluir `/crm` (y
`/crm-pruebas`) en el `.htaccess` **raíz** del servidor, antes de las reglas de WordPress.

El mismo `.htaccess` de la aplicación redirige `crm.intellihelp.tech` a `intellihelp.tech/crm`
con un 301: el subdominio hace falta para que el hosting mapee la carpeta, pero la dirección
oficial es una sola. La ruta base se deduce en tiempo de ejecución, así que si mañana cambia la
carpeta el código sigue funcionando.

---

## 6. Tarea automática (cron)

Agendar en el panel del hosting, **cada 5 minutos** (cada minuto también sirve: una corrida que
encuentra otra en curso se va sin hacer nada), una línea por instalación:

```
*/5 * * * * /usr/bin/php /home/USUARIO/domains/DOMINIO/public_html/crm/cron.php >/dev/null 2>&1
*/5 * * * * /usr/bin/php /home/USUARIO/domains/DOMINIO/public_html/crm-pruebas/cron.php >/dev/null 2>&1
```

Cada corrida trabaja unos 280 s: lee las casillas (cada minuto con campañas en curso, cada 15
sin nada), despacha los correos de las campañas, completa las reservas pendientes y reintenta
los cambios de Google, manda recordatorios y alertas y, una vez por día, sincroniza los feriados,
purga la papelera y anonimiza las entradas de la web. El bloqueo lleva el nombre de la base:
producción y pruebas pueden correr a la vez. Si una tarea diaria falla, se reintenta cada hora.

Si el hosting sólo permite cron por URL, cargar una clave de **24 caracteres o más** en
**Ajustes → Clave del cron** y llamar mandándola en la cabecera `X-Cron-Clave` (no queda en los
registros del servidor):

```bash
curl -s -H "X-Cron-Clave: LA_CLAVE" https://intellihelp.tech/crm/cron.php
```

Si el panel sólo acepta una dirección, `https://intellihelp.tech/crm/cron.php?clave=LA_CLAVE`
sigue funcionando (esa sí queda registrada). Sin clave cargada, la vía por URL está cerrada.

---

## 7. Primer ingreso

1. Entrar a `https://intellihelp.tech/crm`.
2. Ingresar con la cuenta de Google del dominio. **El primer usuario queda como
   administrador** automáticamente; el resto entra como Comercial.
3. Ir a **Ajustes** y cargar la cuenta de correo de salida (con **contraseña de aplicación** de
   Google, no la normal). Ajustes valida lo que se carga y, si algo está mal, no guarda nada.
4. **Modo prueba:** mientras el sistema está en «Prueba», las campañas sólo mandan a la lista
   blanca: los usuarios activos del CRM más las direcciones del ajuste `testing_lista_blanca`
   (migración 015). Ese ajuste no tiene pantalla: se carga directo en `app_settings`.
5. Ir a **Agenda** y conectar Google Calendar si el navegador no lo pidió en el login.
6. Ir a **Importar** y subir el CSV de Zoho, primero con **Simular** activado.

---

## 8. Verificación

```bash
php test/run_tests.php                # lógica: 1187 pruebas (test/casos/ y test/casos_db/)
php test/ui_test.php                  # 334 reglas de interfaz, sesión y seguridad
bash test/smoke.sh                    # 71 comprobaciones HTTP contra el sitio desplegado
python test/herramientas_test.py      # 21 pruebas de las herramientas Python
python test/verificar_unificacion.py  # aserciones sobre la base de contactos unificada
```

En el servidor (contra **pruebas** por defecto; contra producción sólo con `--produccion`):

```bash
python bin/deploy.py test --pruebas   # run_tests, ui_test, smoke y api_test
bash test/render.sh                   # 61 pantallas ejecutadas con sesión de administrador
php test/vivo_test.php                # refresco «vivo» de las pantallas
```

- `run_tests.php` necesita `mbstring`, `openssl` y **`pdo_sqlite`**. Sin `pdo_sqlite` falla; para
  saltear a sabiendas el bloque con base, `CRM_TEST_SIN_BASE=1`. Cada archivo de
  `test/casos_db/` arranca con las tablas base y borra lo que crea.
- `api_test.php` corre sólo contra pruebas y borra lo que crea. Necesita una casilla de correo
  cargada en esa instalación (si no, falla la comprobación «SMTP configurado»).
- `render.sh`, `render_test.php` y `vivo_test.php` rechazan el webroot de producción (código 9)
  salvo con `--produccion`, y limpian siempre su usuario de prueba al terminar.

Todas salen con código `0` si está bien.

### Reiniciar la instalación de pruebas

```bash
php reinicio.php                          # simulacro: cuenta lo que borraría
php reinicio.php --hacerlo                # pide escribir el nombre de la base
php reinicio.php --hacerlo --confirmar=BASE   # sin preguntar (scripts)
php reinicio.php --sembrar                # sólo carga los datos de prueba
```

Se corre en la carpeta de la instalación. No hace nada, ni siquiera el simulacro, salvo que se
cumplan las tres barreras: es la carpeta `crm-pruebas` con su propia configuración (no la de
producción) o la base se llama `*_pruebas`; el ajuste `app_entorno` dice `testing` (sin la fila,
se toma como producción); y `--hacerlo` recibe el nombre de la base. Vacía las tablas en una
transacción (si algo falla no borra nada), después borra los eventos de Google y los adjuntos del
disco, y siembra los datos de prueba con la sección `'pruebas'` de la configuración. Conserva
los feriados.

---

## 9. Migración de la base de contactos

Para fundir la base de Zoho con las listas sueltas de campaña en un solo archivo limpio:

```bash
python bin/unificar_listas.py
python test/verificar_unificacion.py
```

Deja en `Documentacion a consultar/CSV/Normalizada completa/`:

| Archivo | Qué tiene |
| --- | --- |
| `contactos-unificados.csv` | la base final completa, lista para importar |
| `contactos-nuevos.csv` | sólo lo que no estaba en Zoho |
| `contactos-en-zoho.csv` | los que ya estaban, con los datos que aportan las listas |
| `duplicados.csv` | auditoría de qué se fusionó y por qué |
| `RESUMEN.md` | el informe de la corrida |

El archivo unificado ya usa los nombres de columna que entiende el importador del CRM. Cuando
se fusionan dos filas de Zoho, los `zoho_id` de las que no ganaron quedan en una columna aparte
(`zoho_ids_fusionados`), para no perder el enlace al reimportar.

-- ============================================================
--  CRM INTELLIHELP — esquema de base de datos
--  MariaDB 10.5+ (el hosting usa MariaDB 11.8)  ·  utf8mb4
--
--  Uso:  mysql -u USUARIO -p BASE < app/db/schema.sql
--        (o `python bin/deploy.py schema --pruebas|--produccion`, que sube
--        este archivo y lo importa con el cliente mysql del servidor; se
--        niega si la base ya tiene tablas)
--
--  Convenciones (valen para TODAS las tablas de negocio):
--    · id            BIGINT UNSIGNED autoincremental
--    · creado_por    usuario que dio el alta
--    · creado_en / actualizado_en
--    · eliminado_en  borrado LOGICO: lo «eliminado» va a la papelera y se puede
--                    recuperar. El unico borrado fisico lo hace la purga diaria
--                    del cron (purgar_papelera() en campanas.php), y solo sobre
--                    lo que lleva mas de `papelera_dias` (60) en la papelera.
--    · zoho_id       id original de Zoho, para reimportar sin duplicar
--
--  Que tiene y que NO tiene este archivo:
--    · Es idempotente en la estructura (CREATE TABLE IF NOT EXISTS): sobre una
--      base existente no modifica tablas que ya estan, no agrega columnas ni
--      indices nuevos. Para cambiar una tabla existente se escribe una
--      migracion (app/db/migracion-NNN-….sql) y se aplica con
--      `python bin/deploy.py migrar …`, que usa app/migrar.php.
--    · Incluye todo lo de las migraciones 002 a 009 y 018: una instalacion
--      nueva hecha solo con este archivo queda igual que una que se armo con
--      el esquema viejo y despues se migro (mismas tablas, columnas en el
--      mismo orden, indices y datos iniciales). Esas migraciones NO hay que
--      correrlas despues; si se corren igual, no cambian nada (son
--      idempotentes). Una migracion nueva se suma aca tambien.
--      Lo que vino de cada migracion esta marcado con «(migracion NNN)».
--    · Los datos iniciales del final no pisan nada que ya exista: reimportar
--      sobre una base en uso no cambia los ajustes, el embudo ni sus etapas.
--      Solo los colores de las categorias vuelven a los de aca (no hay
--      pantalla para cambiarlos).
--    · La sintaxis de este archivo sirve en MariaDB y en MySQL 8; las
--      migraciones 005 a 009 y 018 usan ADD COLUMN/ADD INDEX IF NOT EXISTS,
--      que es solo de MariaDB (el hosting usa MariaDB).
-- ============================================================

SET NAMES utf8mb4;
-- Las claves foraneas se apagan durante la importacion para poder crear las
-- tablas en cualquier orden (hay referencias cruzadas y autorreferencias). Se
-- vuelven a prender en la ultima linea del archivo.
SET FOREIGN_KEY_CHECKS = 0;


-- ============================================================
--  PLATAFORMA
-- ============================================================

-- Usuarios de la aplicacion. No hay contrasenas: el acceso es 100% Google
-- Workspace, asi que solo guardamos la identidad que devuelve Google.
-- La fila la crea el login la primera vez que entra una cuenta del dominio
-- (rol 'comercial'); el admin cambia rol y `activo` desde Usuarios.
-- `activo` = 0 bloquea el ingreso sin borrar al usuario (sus registros siguen
-- apuntandole). `email` es la identidad: unico y es lo que se compara con
-- Google. `ultimo_acceso` lo actualiza cada ingreso.
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`         VARCHAR(120)    NOT NULL,
  `email`          VARCHAR(190)    NOT NULL,
  `rol`            ENUM('admin','comercial') NOT NULL DEFAULT 'comercial',
  `activo`         TINYINT(1)      NOT NULL DEFAULT 1,
  `avatar_url`     VARCHAR(400)    DEFAULT NULL,
  `telefono`       VARCHAR(40)     DEFAULT NULL,
  `cargo`          VARCHAR(120)    DEFAULT NULL,
  `firma_html`     MEDIUMTEXT      DEFAULT NULL,   -- firma para los correos que manda
  `zoho_id`        VARCHAR(40)     DEFAULT NULL,   -- id del propietario en Zoho
  `ultimo_acceso`  DATETIME        DEFAULT NULL,
  `creado_en`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_email` (`email`),
  KEY `ix_usuarios_zoho` (`zoho_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes de la aplicacion (clave/valor). Todo lo configurable por el admin
-- vive aca: credenciales SMTP/IMAP, entorno, limites de envio, etc.
-- Se lee y escribe con setting_get()/setting_set() (db.php). Ademas de lo que
-- carga el admin, el sistema guarda aca su propio estado: marcas del cron
-- (`cron_turno`, `cron_ultima_corrida`, `papelera_ultima_purga`…), las marcas
-- `migracion_NNN` que deja migrar.php y el estado de los feriados.
-- `app_entorno` ('testing' | 'produccion') es una de las barreras de
-- app/reinicio.php: ver la nota en los datos iniciales.
CREATE TABLE IF NOT EXISTS `app_settings` (
  `clave`          VARCHAR(60) NOT NULL,
  `valor`          TEXT        DEFAULT NULL,
  `actualizado_en` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria. Alimenta la seccion CRON. Dos canales: 'app' (lo que hace la
-- gente + errores) y 'api' (cada pedido a la API de lectura).
-- La escribe log_app() desde toda la aplicacion, el cron y migrar.php. No es
-- la tabla de tareas/llamadas/reuniones: esa es `actividades` (en plural).
-- `actor` y `detalle` son una foto del momento: si el usuario cambia de nombre
-- o se borra la entidad, la linea de auditoria sigue diciendo lo que paso.
-- Los indices por (canal|tipo, creado_en) son los filtros de la pantalla CRON.
CREATE TABLE IF NOT EXISTS `actividad` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creado_en`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `canal`      ENUM('app','api')                             NOT NULL DEFAULT 'app',
  `tipo`       ENUM('accion','nav','auth','error','sistema') NOT NULL DEFAULT 'accion',
  `usuario_id` BIGINT UNSIGNED DEFAULT NULL,
  `actor`      VARCHAR(160)    DEFAULT NULL,   -- nombre del usuario o token (snapshot)
  `accion`     VARCHAR(120)    NOT NULL,       -- ej. 'contacto.crear', 'campana.enviar'
  `entidad`    VARCHAR(60)     DEFAULT NULL,   -- ej. 'contacto', 'negocio'
  `entidad_id` BIGINT UNSIGNED DEFAULT NULL,
  `detalle`    VARCHAR(500)    DEFAULT NULL,
  `ip`         VARCHAR(45)     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_act_creado` (`creado_en`),
  KEY `ix_act_canal`  (`canal`, `creado_en`),
  KEY `ix_act_tipo`   (`tipo`, `creado_en`),
  KEY `ix_act_user`   (`usuario_id`),
  KEY `ix_act_entidad`(`entidad`, `entidad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens de la API (lectura para ITHOS/Jarvis, y escritura para la web
-- intellihelp.tech), mismo modelo que APP Tickets: se guarda solo el SHA-256;
-- la clave completa se muestra una unica vez. `prefijo` es el comienzo visible
-- de la clave, para reconocerla en la pantalla sin guardarla entera.
-- `usos`/`ultimo_uso` los actualiza cada pedido autenticado.
-- app/reinicio.php vacia esta tabla en pruebas: despues hay que generar otra.
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(120) NOT NULL,
  `prefijo`    VARCHAR(16)  NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  -- Secreto para firmar los pedidos (claves de escritura), cifrado con el
  -- secreto de la app. La clave sola no alcanza para escribir: hace falta la firma.
  `secreto_cifrado` TEXT     DEFAULT NULL,
  `scope`      ENUM('lectura','root','escritura') NOT NULL DEFAULT 'lectura',
  `activo`     TINYINT(1)   NOT NULL DEFAULT 1,
  `usos`       INT UNSIGNED NOT NULL DEFAULT 0,
  `ultimo_uso` DATETIME     DEFAULT NULL,
  `creado_por` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `ix_token_prefijo` (`prefijo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  NUCLEO CRM
-- ============================================================

-- Cuentas = empresas. En la base de Zoho hay 12.249 nombres de cuenta para
-- 13.573 contactos: al importar se crean unicas y se enlazan.
-- La escriben la pantalla de Empresas, el importador, la conversion de posibles
-- clientes y las entradas desde la web (cuenta_asegurar() en crm.php).
-- `nombre` no es unico a proposito: dos empresas pueden llamarse igual; la
-- deduplicacion se hace en la aplicacion. `cuit` se guarda solo con digitos.
-- `pendiente_norm` (migracion 006): 1 = a la ficha le falta CUIT, tipo o
-- sector; lo recalcula cuenta_guardar() en cada guardado. `aprobado_en` /
-- `aprobado_por` (migracion 007): alguien la dio por buena igual (id de
-- `usuarios`, sin clave foranea). Pendiente = pendiente_norm 1 Y sin aprobar.
CREATE TABLE IF NOT EXISTS `cuentas` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`          VARCHAR(200) NOT NULL,
  `propietario_id`  BIGINT UNSIGNED DEFAULT NULL,
  `cuenta_padre_id` BIGINT UNSIGNED DEFAULT NULL,
  `tipo`            VARCHAR(40)  DEFAULT NULL,   -- Cliente / Prospecto / Socio / ...
  `sector`          VARCHAR(80)  DEFAULT NULL,
  `pendiente_norm`  TINYINT(1)   NOT NULL DEFAULT 0,          -- (migracion 006)
  `aprobado_en`     DATETIME     DEFAULT NULL,                -- (migracion 007)
  `aprobado_por`    BIGINT UNSIGNED DEFAULT NULL,             -- (migracion 007)
  `sitio_web`       VARCHAR(255) DEFAULT NULL,
  `telefono`        VARCHAR(60)  DEFAULT NULL,
  `email`           VARCHAR(190) DEFAULT NULL,
  `cuit`          VARCHAR(11)  DEFAULT NULL,   -- Argentina (modulo 11)
  `condicion_iva`   VARCHAR(40)  DEFAULT NULL,
  `empleados`       INT UNSIGNED DEFAULT NULL,
  `ingresos_anuales` DECIMAL(16,2) DEFAULT NULL,
  `moneda`          CHAR(3)      NOT NULL DEFAULT 'ARS',
  `calle`           VARCHAR(250) DEFAULT NULL,
  `localidad`       VARCHAR(120) DEFAULT NULL,
  `provincia`       VARCHAR(80)  DEFAULT NULL,
  `codigo_postal`   VARCHAR(30)  DEFAULT NULL,
  `pais`            VARCHAR(60)  DEFAULT NULL,
  `linkedin`        VARCHAR(255) DEFAULT NULL,
  `descripcion`     TEXT         DEFAULT NULL,
  `zoho_id`         VARCHAR(40)  DEFAULT NULL,
  `creado_por`      BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- UNIQUE con NULL: las cuentas creadas en el CRM (sin zoho_id) no chocan
  -- entre si; solo impide importar dos veces la misma cuenta de Zoho.
  UNIQUE KEY `uq_cuentas_zoho` (`zoho_id`),
  KEY `ix_cuentas_nombre` (`nombre`),
  KEY `ix_cuentas_prop`   (`propietario_id`),
  KEY `ix_cuentas_elim`   (`eliminado_en`),
  KEY `ix_cuentas_cuit`   (`cuit`),
  KEY `fk_cuentas_padre`  (`cuenta_padre_id`),
  -- Filtro «pendientes de normalizar» (migracion 007; reemplaza al
  -- `ix_cta_pend` de la 006, que la 018 saca por repetido).
  KEY `ix_cta_aprob`      (`pendiente_norm`, `aprobado_en`, `eliminado_en`),
  -- ON DELETE SET NULL: si la purga borra una cuenta, sus hijas y lo que
  -- apuntaba a ella quedan sueltas, no se borran en cascada. Por eso
  -- purgar_papelera() no borra una cuenta que todavia tiene contactos vivos.
  CONSTRAINT `fk_cuentas_prop`  FOREIGN KEY (`propietario_id`)  REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cuentas_padre` FOREIGN KEY (`cuenta_padre_id`) REFERENCES `cuentas` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contactos = personas. Los campos "extra" (conmutador, telefono directo,
-- linkedin, correo terciario) existen porque el equipo ya los usa en Zoho.
-- La escriben la ficha de Contactos, el importador, la conversion de posibles
-- clientes, las entradas desde la web y el lector IMAP (marca rebotes y
-- archiva por rebote definitivo). `apellido` es obligatorio (NOT NULL);
-- `nombre_completo` es una columna generada (se calcula sola, no se escribe).
-- `email` NO es unico: la misma direccion puede estar en dos fichas, y el
-- control de no mandar dos veces lo hace `envios` por campana.
-- `pendiente_norm` (migracion 006): 1 = le falta nombre, apellido, correo o
-- empresa; lo recalcula contacto_guardar(). `aprobado_en`/`aprobado_por`
-- (migracion 007): igual que en `cuentas`.
CREATE TABLE IF NOT EXISTS `contactos` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`         VARCHAR(120) DEFAULT NULL,
  `apellido`       VARCHAR(120) NOT NULL,
  `nombre_completo` VARCHAR(241) GENERATED ALWAYS AS
      (TRIM(CONCAT(COALESCE(`nombre`,''), ' ', `apellido`))) STORED,
  `cuenta_id`      BIGINT UNSIGNED DEFAULT NULL,
  `propietario_id` BIGINT UNSIGNED DEFAULT NULL,
  `cargo`          VARCHAR(150) DEFAULT NULL,
  `departamento`   VARCHAR(120) DEFAULT NULL,
  `email`          VARCHAR(190) DEFAULT NULL,
  `email2`         VARCHAR(190) DEFAULT NULL,
  `email3`         VARCHAR(190) DEFAULT NULL,
  `telefono`       VARCHAR(60)  DEFAULT NULL,   -- linea directa de la persona
  `movil`          VARCHAR(60)  DEFAULT NULL,
  `conmutador`     VARCHAR(60)  DEFAULT NULL,   -- telefono general de la empresa
  `fuente`         VARCHAR(60)  DEFAULT NULL,
  `linkedin`       VARCHAR(255) DEFAULT NULL,
  `sitio_web`      VARCHAR(255) DEFAULT NULL,
  `calle`          VARCHAR(250) DEFAULT NULL,
  `localidad`      VARCHAR(120) DEFAULT NULL,
  `provincia`      VARCHAR(80)  DEFAULT NULL,
  `codigo_postal`  VARCHAR(30)  DEFAULT NULL,
  `pais`           VARCHAR(60)  DEFAULT NULL,
  `reporta_a_id`   BIGINT UNSIGNED DEFAULT NULL,
  `descripcion`    TEXT         DEFAULT NULL,
  -- Estado comercial derivado del trabajo de campanas
  `no_email`       TINYINT(1)   NOT NULL DEFAULT 0,  -- desuscripto / opt-out
  -- 1 = el correo rebota (lo marca el lector IMAP). Un rebote definitivo
  -- ademas da de baja la direccion en `desuscripciones` y archiva la ficha.
  `email_rebotado` TINYINT(1)   NOT NULL DEFAULT 0,
  `pendiente_norm` TINYINT(1)   NOT NULL DEFAULT 0,      -- (migracion 006)
  `aprobado_en`    DATETIME     DEFAULT NULL,            -- (migracion 007)
  `aprobado_por`   BIGINT UNSIGNED DEFAULT NULL,         -- (migracion 007)
  `ultima_actividad_en` DATETIME DEFAULT NULL,
  `zoho_id`        VARCHAR(40)  DEFAULT NULL,
  `creado_por`     BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en`   DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contactos_zoho` (`zoho_id`),
  -- Solo el correo principal tiene indice: buscar por email2/email3 recorre
  -- la tabla entera.
  KEY `ix_contactos_email`  (`email`),
  KEY `ix_contactos_cuenta` (`cuenta_id`),
  KEY `ix_contactos_prop`   (`propietario_id`),
  KEY `ix_contactos_elim`   (`eliminado_en`),
  KEY `ix_contactos_nombre` (`apellido`, `nombre`),
  KEY `fk_contactos_reporta`(`reporta_a_id`),
  -- (migracion 007; reemplaza al `ix_cont_pend` de la 006, que saca la 018)
  KEY `ix_cont_aprob`       (`pendiente_norm`, `aprobado_en`, `eliminado_en`),
  CONSTRAINT `fk_contactos_cuenta`  FOREIGN KEY (`cuenta_id`)      REFERENCES `cuentas` (`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_contactos_prop`    FOREIGN KEY (`propietario_id`) REFERENCES `usuarios` (`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_contactos_reporta` FOREIGN KEY (`reporta_a_id`)   REFERENCES `contactos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Posibles clientes (leads): contacto crudo, todavia sin calificar. Al
-- convertirlo se crean Cuenta + Contacto (+ Negocio opcional) y queda marcado.
-- La escriben la pantalla de Posibles clientes y el importador. «Marcado» es
-- `convertido_en` con fecha y los ids de lo que se creo en `cuenta_id`,
-- `contacto_id` y `negocio_id` (sin clave foranea: si se purga lo creado, el
-- id queda apuntando a una fila que ya no existe). `empresa` es texto libre:
-- recien al convertir se vuelve una fila de `cuentas`.
CREATE TABLE IF NOT EXISTS `posibles_clientes` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`         VARCHAR(120) DEFAULT NULL,
  `apellido`       VARCHAR(120) NOT NULL,
  `empresa`        VARCHAR(200) NOT NULL,
  `cargo`          VARCHAR(150) DEFAULT NULL,
  `propietario_id` BIGINT UNSIGNED DEFAULT NULL,
  `email`          VARCHAR(190) DEFAULT NULL,
  `email2`         VARCHAR(190) DEFAULT NULL,
  `telefono`       VARCHAR(60)  DEFAULT NULL,
  `movil`          VARCHAR(60)  DEFAULT NULL,
  `conmutador`     VARCHAR(60)  DEFAULT NULL,
  `sitio_web`      VARCHAR(255) DEFAULT NULL,
  `linkedin`       VARCHAR(255) DEFAULT NULL,
  `fuente`         VARCHAR(60)  DEFAULT NULL,
  `estado`         VARCHAR(40)  NOT NULL DEFAULT 'Sin contactar',
  `calificacion`   VARCHAR(20)  DEFAULT NULL,
  `sector`         VARCHAR(80)  DEFAULT NULL,
  `empleados`      INT UNSIGNED DEFAULT NULL,
  `puntaje`        INT          NOT NULL DEFAULT 0,   -- scoring
  `calle`          VARCHAR(250) DEFAULT NULL,
  `localidad`      VARCHAR(120) DEFAULT NULL,
  `provincia`      VARCHAR(80)  DEFAULT NULL,
  `codigo_postal`  VARCHAR(30)  DEFAULT NULL,
  `pais`           VARCHAR(60)  DEFAULT NULL,
  `descripcion`    TEXT         DEFAULT NULL,
  `no_email`       TINYINT(1)   NOT NULL DEFAULT 0,
  `email_rebotado` TINYINT(1)   NOT NULL DEFAULT 0,
  `ultima_actividad_en` DATETIME DEFAULT NULL,
  -- Conversion
  `convertido_en`   DATETIME       DEFAULT NULL,
  `convertido_por`  BIGINT UNSIGNED DEFAULT NULL,
  `cuenta_id`       BIGINT UNSIGNED DEFAULT NULL,
  `contacto_id`     BIGINT UNSIGNED DEFAULT NULL,
  `negocio_id`      BIGINT UNSIGNED DEFAULT NULL,
  `zoho_id`        VARCHAR(40)  DEFAULT NULL,
  `creado_por`     BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en`   DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pc_zoho` (`zoho_id`),
  KEY `ix_pc_email`  (`email`),
  KEY `ix_pc_estado` (`estado`),
  KEY `ix_pc_prop`   (`propietario_id`),
  KEY `ix_pc_conv`   (`convertido_en`),
  KEY `ix_pc_elim`   (`eliminado_en`),
  CONSTRAINT `fk_pc_prop` FOREIGN KEY (`propietario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Etiquetas libres, aplicables a cualquier modulo (patron polimorfico).
-- `etiquetas` es el catalogo (nombre unico y color); `etiqueta_registro` dice
-- que etiqueta tiene cada registro.
CREATE TABLE IF NOT EXISTS `etiquetas` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(60) NOT NULL,
  `color`  CHAR(7)     NOT NULL DEFAULT '#663399',
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_etiquetas_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polimorfica: (`entidad`, `entidad_id`) apunta a una fila de cualquier
-- tabla, asi que no puede tener clave foranea hacia ella. Cuando la purga
-- borra un contacto, sus etiquetas quedan huerfanas aca (no molestan, pero no
-- se limpian). La clave primaria impide poner dos veces la misma etiqueta al
-- mismo registro; borrar la etiqueta del catalogo la saca de todos (CASCADE).
CREATE TABLE IF NOT EXISTS `etiqueta_registro` (
  `etiqueta_id` INT UNSIGNED    NOT NULL,
  `entidad`     VARCHAR(30)     NOT NULL,   -- contacto | cuenta | posible_cliente | negocio
  `entidad_id`  BIGINT UNSIGNED NOT NULL,
  `creado_en`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`etiqueta_id`, `entidad`, `entidad_id`),
  KEY `ix_er_entidad` (`entidad`, `entidad_id`),
  CONSTRAINT `fk_er_etiqueta` FOREIGN KEY (`etiqueta_id`) REFERENCES `etiquetas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notas y adjuntos: tambien polimorficos, para no repetir tabla por modulo.
-- (`entidad`, `entidad_id`) dice de que ficha son; sin clave foranea, por lo
-- mismo que `etiqueta_registro`. Las notas las escriben las fichas y las
-- entradas desde la web (el mensaje del formulario queda como nota). El indice
-- incluye `creado_en` porque la linea de tiempo las lista en orden.
CREATE TABLE IF NOT EXISTS `notas` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entidad`    VARCHAR(30)     NOT NULL,
  `entidad_id` BIGINT UNSIGNED NOT NULL,
  `titulo`     VARCHAR(200)    DEFAULT NULL,
  `cuerpo`     TEXT            NOT NULL,
  `creado_por` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en` DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_notas_entidad` (`entidad`, `entidad_id`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archivos subidos a una ficha. El archivo vive en disco, en la carpeta de
-- almacenamiento de la instalacion (fuera del webroot); aca solo queda el
-- nombre original, la ruta relativa, el tipo y el tamano. adjunto_borrar()
-- (registros.php) borra el archivo del disco en el acto y solo marca la fila
-- con `eliminado_en`: a diferencia del resto, un adjunto borrado no se puede
-- recuperar. app/reinicio.php, al vaciar la tabla en pruebas, borra tambien
-- los archivos.
CREATE TABLE IF NOT EXISTS `adjuntos` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entidad`    VARCHAR(30)     NOT NULL,
  `entidad_id` BIGINT UNSIGNED NOT NULL,
  `nombre`     VARCHAR(255)    NOT NULL,
  `ruta`       VARCHAR(255)    NOT NULL,   -- relativa a STORAGE (fuera del webroot)
  `mime`       VARCHAR(100)    DEFAULT NULL,
  `bytes`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `creado_por` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `eliminado_en` DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_adj_entidad` (`entidad`, `entidad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  EMBUDO DE VENTAS
-- ============================================================

-- Embudos de venta (pipelines). Hoy se usa uno solo, «Ventas», que se siembra
-- al final de este archivo con id 1. `predeterminado` = 1 marca en cual entra
-- un negocio nuevo si no se elige otro.
CREATE TABLE IF NOT EXISTS `embudos` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(80)  NOT NULL,
  `activo` TINYINT(1)   NOT NULL DEFAULT 1,
  `predeterminado` TINYINT(1) NOT NULL DEFAULT 0,
  `orden`  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_embudos_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Etapas de cada embudo, en `orden`. `tipo` dice si la etapa cierra el
-- negocio ('ganada' / 'perdida') o lo deja abierto; `probabilidad` (0 a 100)
-- se copia al negocio al moverlo y alimenta el ingreso esperado. Borrar un
-- embudo borra sus etapas (CASCADE), pero `negocios` no deja borrar una etapa
-- que todavia tiene negocios (clave foranea sin ON DELETE).
CREATE TABLE IF NOT EXISTS `etapas` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `embudo_id`   INT UNSIGNED NOT NULL,
  `nombre`      VARCHAR(80)  NOT NULL,
  `probabilidad` TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0..100
  `tipo`        ENUM('abierta','ganada','perdida') NOT NULL DEFAULT 'abierta',
  `color`       CHAR(7)      NOT NULL DEFAULT '#663399',
  `orden`       INT          NOT NULL DEFAULT 0,
  `activo`      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_etapas` (`embudo_id`, `nombre`),
  KEY `ix_etapas_orden` (`embudo_id`, `orden`),
  CONSTRAINT `fk_etapas_embudo` FOREIGN KEY (`embudo_id`) REFERENCES `embudos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Negocios (oportunidades). Los escribe la pantalla del Embudo y la
-- conversion de posibles clientes. `ingreso_esperado` es una columna generada
-- (importe x probabilidad): no se escribe. `cerrado_en` se completa al pasar a
-- una etapa ganada o perdida. `campana_id` atribuye el negocio a la campana
-- de la que salio (sin clave foranea).
CREATE TABLE IF NOT EXISTS `negocios` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`          VARCHAR(200) NOT NULL,
  `cuenta_id`       BIGINT UNSIGNED DEFAULT NULL,
  `contacto_id`     BIGINT UNSIGNED DEFAULT NULL,
  `propietario_id`  BIGINT UNSIGNED DEFAULT NULL,
  `embudo_id`       INT UNSIGNED    NOT NULL,
  `etapa_id`        INT UNSIGNED    NOT NULL,
  `importe`         DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
  `moneda`          CHAR(3)         NOT NULL DEFAULT 'ARS',
  `probabilidad`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ingreso_esperado` DECIMAL(15,2) GENERATED ALWAYS AS
      (ROUND(`importe` * `probabilidad` / 100, 2)) STORED,
  `fecha_cierre`    DATE            DEFAULT NULL,
  `cerrado_en`      DATETIME        DEFAULT NULL,
  `motivo_perdida`  VARCHAR(200)    DEFAULT NULL,
  `tipo`            VARCHAR(40)     DEFAULT NULL,
  `fuente`          VARCHAR(60)     DEFAULT NULL,
  `campana_id`      BIGINT UNSIGNED DEFAULT NULL,   -- atribucion de la campana
  `siguiente_paso`  VARCHAR(255)    DEFAULT NULL,
  `descripcion`     TEXT            DEFAULT NULL,
  `zoho_id`         VARCHAR(40)     DEFAULT NULL,
  `creado_por`      BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en`    DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_negocios_zoho` (`zoho_id`),
  KEY `ix_neg_cuenta`  (`cuenta_id`),
  KEY `ix_neg_etapa`   (`etapa_id`),
  KEY `ix_neg_prop`    (`propietario_id`),
  KEY `ix_neg_cierre`  (`fecha_cierre`),
  KEY `ix_neg_elim`    (`eliminado_en`),
  KEY `fk_neg_contacto`(`contacto_id`),
  KEY `fk_neg_embudo`  (`embudo_id`),
  CONSTRAINT `fk_neg_cuenta`   FOREIGN KEY (`cuenta_id`)      REFERENCES `cuentas` (`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_neg_contacto` FOREIGN KEY (`contacto_id`)    REFERENCES `contactos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_neg_prop`     FOREIGN KEY (`propietario_id`) REFERENCES `usuarios` (`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_neg_embudo`   FOREIGN KEY (`embudo_id`)      REFERENCES `embudos` (`id`),
  CONSTRAINT `fk_neg_etapa`    FOREIGN KEY (`etapa_id`)       REFERENCES `etapas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de etapas: permite medir cuanto tarda un negocio en cada paso.
-- Una fila por paso: `desde` cuando entro a la etapa y `hasta` cuando salio
-- (NULL = la etapa actual).
CREATE TABLE IF NOT EXISTS `negocio_etapas` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `negocio_id` BIGINT UNSIGNED NOT NULL,
  `etapa_id`   INT UNSIGNED    NOT NULL,
  `desde`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `hasta`      DATETIME        DEFAULT NULL,
  `usuario_id` BIGINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ne_negocio` (`negocio_id`, `desde`),
  CONSTRAINT `fk_ne_negocio` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contactos que participan de un negocio, con su rol.
CREATE TABLE IF NOT EXISTS `negocio_contactos` (
  `negocio_id`  BIGINT UNSIGNED NOT NULL,
  `contacto_id` BIGINT UNSIGNED NOT NULL,
  `rol`         VARCHAR(60)     DEFAULT NULL,
  PRIMARY KEY (`negocio_id`, `contacto_id`),
  KEY `fk_nc_contacto` (`contacto_id`),
  CONSTRAINT `fk_nc_negocio`  FOREIGN KEY (`negocio_id`)  REFERENCES `negocios` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_nc_contacto` FOREIGN KEY (`contacto_id`) REFERENCES `contactos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Objetivos de venta por usuario y periodo (alimenta el pronostico).
CREATE TABLE IF NOT EXISTS `cuotas` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` BIGINT UNSIGNED NOT NULL,
  `periodo`    CHAR(7)         NOT NULL,   -- AAAA-MM
  `objetivo`   DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
  `moneda`     CHAR(3)         NOT NULL DEFAULT 'ARS',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cuotas` (`usuario_id`, `periodo`),
  CONSTRAINT `fk_cuotas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  ACTIVIDADES
-- ============================================================

-- Una sola tabla para tarea / llamada / reunion: comparten el 90% de los
-- campos y asi la "linea de tiempo" de un registro sale de una consulta.
-- La escriben las pantallas de Actividades, la agenda (cada reserva crea su
-- reunion) y las entradas desde la web. `recordar_min` se carga desde el
-- formulario, pero hoy ningun proceso manda ese aviso ni completa
-- `recordado_en` (los recordatorios del cron son los de `agenda_reservas`).
-- `gcal_event_id`/`meet_url` son del evento de Google Calendar que se crea
-- para las reuniones. No confundir con `actividad` (auditoria).
CREATE TABLE IF NOT EXISTS `actividades` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo`           ENUM('tarea','llamada','reunion') NOT NULL,
  `asunto`         VARCHAR(200) NOT NULL,
  `propietario_id` BIGINT UNSIGNED DEFAULT NULL,
  -- A que registro pertenece (uno de estos, o ninguno)
  `contacto_id`         BIGINT UNSIGNED DEFAULT NULL,
  `posible_cliente_id`  BIGINT UNSIGNED DEFAULT NULL,
  `cuenta_id`           BIGINT UNSIGNED DEFAULT NULL,
  `negocio_id`          BIGINT UNSIGNED DEFAULT NULL,
  `estado`         VARCHAR(40)  NOT NULL DEFAULT 'No iniciada',
  `prioridad`      VARCHAR(20)  NOT NULL DEFAULT 'Normal',
  `vence_el`       DATETIME     DEFAULT NULL,   -- tareas
  `inicia_el`      DATETIME     DEFAULT NULL,   -- llamadas y reuniones
  `termina_el`     DATETIME     DEFAULT NULL,
  `duracion_min`   INT UNSIGNED DEFAULT NULL,
  `lugar`          VARCHAR(200) DEFAULT NULL,
  `sentido`        ENUM('entrante','saliente') DEFAULT NULL,  -- llamadas
  `resultado`      VARCHAR(80)  DEFAULT NULL,
  `completada_en`  DATETIME     DEFAULT NULL,
  `recordar_min`   INT UNSIGNED DEFAULT NULL,   -- minutos antes
  `recordado_en`   DATETIME     DEFAULT NULL,
  -- Google Calendar / Meet (reuniones)
  `gcal_event_id`  VARCHAR(128) DEFAULT NULL,
  `meet_url`       VARCHAR(255) DEFAULT NULL,
  `descripcion`    TEXT         DEFAULT NULL,
  `creado_por`     BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en`   DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_act_tipo`     (`tipo`, `eliminado_en`),
  KEY `ix_act_prop`     (`propietario_id`, `vence_el`),
  KEY `ix_act_contacto` (`contacto_id`),
  KEY `ix_act_negocio`  (`negocio_id`),
  KEY `ix_act_cuenta`   (`cuenta_id`),
  KEY `ix_act_pc`       (`posible_cliente_id`),
  KEY `ix_act_agenda`   (`inicia_el`),
  CONSTRAINT `fk_acti_contacto` FOREIGN KEY (`contacto_id`)        REFERENCES `contactos` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_acti_pc`       FOREIGN KEY (`posible_cliente_id`) REFERENCES `posibles_clientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_acti_cuenta`   FOREIGN KEY (`cuenta_id`)          REFERENCES `cuentas` (`id`)           ON DELETE SET NULL,
  CONSTRAINT `fk_acti_negocio`  FOREIGN KEY (`negocio_id`)         REFERENCES `negocios` (`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_acti_prop`     FOREIGN KEY (`propietario_id`)     REFERENCES `usuarios` (`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invitados de una reunion (por correo, sean o no contactos del CRM), con su
-- respuesta a la invitacion. Un mismo correo una sola vez por reunion.
CREATE TABLE IF NOT EXISTS `actividad_invitados` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actividad_id`  BIGINT UNSIGNED NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `nombre`        VARCHAR(160) DEFAULT NULL,
  `respuesta`     ENUM('pendiente','acepto','rechazo','quizas') NOT NULL DEFAULT 'pendiente',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai` (`actividad_id`, `email`),
  CONSTRAINT `fk_ai_actividad` FOREIGN KEY (`actividad_id`) REFERENCES `actividades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  AGENDA PROPIA (reemplazo de Calendly)
-- ============================================================

-- Tipo de reunion publicable: "Presentacion 30 min", "Demo 45 min".
-- Cada vendedor tiene los suyos; la URL publica es <vendedor>/<slug>, por eso
-- el slug es unico por usuario y no global. La web usa el slug 'diagnostico'.
-- `zona_horaria` es la del vendedor: los horarios se ofrecen en esa zona.
CREATE TABLE IF NOT EXISTS `agenda_tipos` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`     BIGINT UNSIGNED NOT NULL,
  `slug`           VARCHAR(60)  NOT NULL,   -- url publica
  `nombre`         VARCHAR(120) NOT NULL,
  `descripcion`    TEXT         DEFAULT NULL,
  `duracion_min`   INT UNSIGNED NOT NULL DEFAULT 30,
  `margen_min`     INT UNSIGNED NOT NULL DEFAULT 10,   -- colchon entre reuniones
  `aviso_min_horas` INT UNSIGNED NOT NULL DEFAULT 4,   -- antelacion minima
  `ventana_dias`   INT UNSIGNED NOT NULL DEFAULT 30,   -- hasta cuando se puede reservar
  `zona_horaria`   VARCHAR(60)  NOT NULL DEFAULT 'America/Argentina/Buenos_Aires',
  `con_meet`       TINYINT(1)   NOT NULL DEFAULT 1,
  `activo`         TINYINT(1)   NOT NULL DEFAULT 1,
  `creado_en`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agenda_slug` (`usuario_id`, `slug`),
  CONSTRAINT `fk_at_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horario laboral por dia de la semana (0=domingo .. 6=sabado).
-- Puede haber varias franjas el mismo dia (manana y tarde). Un dia sin filas
-- es un dia sin turnos. Los feriados se descuentan aparte (tabla `feriados`).
CREATE TABLE IF NOT EXISTS `agenda_horarios` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo_id`  INT UNSIGNED NOT NULL,
  `dia`      TINYINT UNSIGNED NOT NULL,
  `desde`    TIME NOT NULL,
  `hasta`    TIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ah_tipo` (`tipo_id`, `dia`),
  CONSTRAINT `fk_ah_tipo` FOREIGN KEY (`tipo_id`) REFERENCES `agenda_tipos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reuniones reservadas: desde la agenda publica (/r/), desde la web por la
-- API o cargadas desde el CRM (`origen`). La reserva se guarda primero y el
-- evento de Google + el correo se hacen despues; `avisada_en` NULL con
-- estado 'confirmada' es una reserva a medio completar, que el cron termina
-- (agenda_completar_pendientes()). `recordatorio_enviado_en` evita mandar dos
-- veces el recordatorio de 24 h. Reprogramar crea una fila nueva que apunta a
-- la vieja con `reprogramada_de`.
-- No hay restriccion unica sobre (usuario_id, inicia_el): que dos personas no
-- tomen el mismo horario lo garantiza la aplicacion con un GET_LOCK por
-- vendedor (agenda.php), no la base.
CREATE TABLE IF NOT EXISTS `agenda_reservas` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo_id`       INT UNSIGNED    NOT NULL,
  `usuario_id`    BIGINT UNSIGNED NOT NULL,
  `token`         CHAR(32)        NOT NULL,   -- para reprogramar/cancelar sin login
  `inicia_el`     DATETIME        NOT NULL,
  `termina_el`    DATETIME        NOT NULL,
  `invitado_nombre` VARCHAR(160)  NOT NULL,
  `invitado_email`  VARCHAR(190)  NOT NULL,
  `invitado_empresa` VARCHAR(200) DEFAULT NULL,
  `invitado_telefono` VARCHAR(60) DEFAULT NULL,
  `comentario`    TEXT            DEFAULT NULL,
  `estado`        ENUM('confirmada','reprogramada','cancelada') NOT NULL DEFAULT 'confirmada',
  `gcal_event_id` VARCHAR(128)    DEFAULT NULL,
  `meet_url`      VARCHAR(255)    DEFAULT NULL,
  -- Cambio en Google que quedo por hacer porque Google fallo despues de
  -- guardar en la base ('mover' o 'borrar'); el cron lo reintenta (013).
  `gcal_pendiente` VARCHAR(10)    DEFAULT NULL,
  -- Una sola invitacion que se actualiza: mismo UID al mover y al cancelar.
  `ics_uid`         VARCHAR(255)    DEFAULT NULL,
  `ics_secuencia`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `reprogramada_de` BIGINT UNSIGNED DEFAULT NULL,   -- la reserva que esta reemplaza
  `origen`          VARCHAR(20)     DEFAULT NULL,   -- web | publica | crm
  `avisada_en`      DATETIME        DEFAULT NULL,   -- evento + correo ya hechos
  `consentimiento_en`      DATETIME    DEFAULT NULL,
  `consentimiento_version` VARCHAR(40) DEFAULT NULL,
  `contacto_id`   BIGINT UNSIGNED DEFAULT NULL,
  `actividad_id`  BIGINT UNSIGNED DEFAULT NULL,
  `campana_id`    BIGINT UNSIGNED DEFAULT NULL,   -- de que campana vino
  `recordatorio_enviado_en` DATETIME DEFAULT NULL,
  `creado_en`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ar_token` (`token`),
  KEY `ix_ar_usuario` (`usuario_id`, `inicia_el`),
  KEY `ix_ar_estado`  (`estado`, `inicia_el`),
  KEY `ix_ar_prog`    (`reprogramada_de`),
  KEY `ix_ar_avisada` (`estado`, `avisada_en`),
  KEY `ix_ar_gcal_pendiente` (`gcal_pendiente`),
  CONSTRAINT `fk_ar_tipo`    FOREIGN KEY (`tipo_id`)    REFERENCES `agenda_tipos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ar_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entradas desde la web: cada consulta o reserva que llega de intellihelp.tech,
-- con la pagina y el canal por el que llego la persona. La fila se crea antes
-- de procesar el pedido, con la clave de idempotencia unica, para que un
-- reintento de la web no duplique la reunion. (migracion 008)
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
  -- (migracion 019) el token o 0: calculada, no ocupa lugar ni se escribe
  `token_clave`     BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`token_id`, 0)) VIRTUAL,
  PRIMARY KEY (`id`),
  -- La idempotencia es por clave de API. En un UNIQUE los NULL no chocan
  -- entre si: por eso la clave usa `token_clave` (0 para la agenda publica,
  -- `token_id` NULL) y no `token_id`.
  UNIQUE KEY `uq_ew_idem_clave` (`token_clave`, `idempotencia`),
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
-- Lo escribe la API y la agenda publica en cada pedido; el cron borra una vez
-- por dia los contadores con `vence_en` pasado (por eso el indice). Es
-- descartable: vaciarla solo reinicia los limites.
CREATE TABLE IF NOT EXISTS `api_limites` (
  `clave`    VARCHAR(120) NOT NULL,     -- 'k:<token>:escritura:60', 'ip:<hash>:reserva:3600', …
  `ventana`  INT UNSIGNED NOT NULL,     -- floor(time() / segundos)
  `conteo`   INT UNSIGNED NOT NULL DEFAULT 0,
  `vence_en` DATETIME     NOT NULL,
  PRIMARY KEY (`clave`, `ventana`),
  KEY `ix_al_vence` (`vence_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feriados (migracion 009): los dias en que la agenda no ofrece horarios.
-- Los nacionales los mantiene la sincronizacion diaria con el calendario
-- oficial (origen 'auto'); los regionales y los dias que la empresa no trabaja
-- se cargan a mano. `ignorado` = figura en el oficial pero se trabaja.
CREATE TABLE IF NOT EXISTS `feriados` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fecha`          DATE         NOT NULL,
  `nombre`         VARCHAR(160) NOT NULL,
  `tipo`           VARCHAR(20)  NOT NULL DEFAULT 'inamovible', -- inamovible | trasladable | puente | no_laborable
  `jurisdiccion`   VARCHAR(20)  NOT NULL DEFAULT 'nacional',   -- 'nacional' o 'CABA', 'BA', ...
  `origen`         ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  `ignorado`       TINYINT(1)   NOT NULL DEFAULT 0,
  `creado_por`     BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_feriado_dia` (`fecha`, `jurisdiccion`),
  KEY `ix_feriado_vigente` (`ignorado`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Credenciales OAuth de Google por usuario (refresh token cifrado en la app).
-- Las escribe el login con Google; las usa gcal.php para crear y borrar los
-- eventos con Meet. Sin fila, el proximo ingreso pide el consentimiento de
-- nuevo y Google manda otro refresh token.
CREATE TABLE IF NOT EXISTS `google_tokens` (
  `usuario_id`     BIGINT UNSIGNED NOT NULL,
  `refresh_token`  TEXT            DEFAULT NULL,
  `access_token`   TEXT            DEFAULT NULL,
  `expira_en`      DATETIME        DEFAULT NULL,
  `scopes`         TEXT            DEFAULT NULL,
  `calendario_id`  VARCHAR(190)    NOT NULL DEFAULT 'primary',
  `actualizado_en` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`usuario_id`),
  CONSTRAINT `fk_gt_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  CAMPANAS DE CORREO  (motor Pulsemail llevado a web)
-- ============================================================

-- Plantillas de correo de las campanas. `bloques` (el JSON del editor
-- visual), `tipo` ('visual' | 'html') y `preajuste` (la clave del molde
-- prearmado del que salio, que usa app/reinicio.php para reescribirlas) son de
-- la migracion 002. `cuerpo_html` es lo que se manda; en las visuales se
-- regenera a partir de `bloques` al guardar.
CREATE TABLE IF NOT EXISTS `plantillas` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(160) NOT NULL,
  `asunto`     VARCHAR(255) NOT NULL,
  `cuerpo_html` MEDIUMTEXT  NOT NULL,
  `cuerpo_texto` MEDIUMTEXT DEFAULT NULL,   -- alternativa en texto plano (anti-spam)
  `bloques`    MEDIUMTEXT   DEFAULT NULL,   -- (migracion 002)
  `carpeta`    VARCHAR(80)  DEFAULT NULL,
  `tipo`       ENUM('visual','html','texto') NOT NULL DEFAULT 'visual',   -- (002; 'texto' en la 021)
  `preajuste`  VARCHAR(40)  DEFAULT NULL,   -- (migracion 002)
  `activo`    TINYINT(1)   NOT NULL DEFAULT 1,
  `creado_por` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en` DATETIME   DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_plantillas_elim` (`eliminado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Listas de destinatarios para las campanas. `categoria_id` (segmento: RRHH,
-- bancos, …) es de la migracion 002, sin clave foranea; su indice, de la 018.
CREATE TABLE IF NOT EXISTS `listas` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(160) NOT NULL,
  `descripcion` TEXT        DEFAULT NULL,
  `categoria_id` INT UNSIGNED DEFAULT NULL,   -- (migracion 002)
  `creado_por` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en` DATETIME   DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_listas_elim` (`eliminado_en`),
  KEY `ix_listas_categoria` (`categoria_id`)   -- (migracion 018)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un miembro puede ser un contacto, un posible cliente o un correo suelto
-- (asi se puede cargar una lista importada sin ensuciar el CRM).
-- `email` se guarda siempre, aunque haya contacto: es la direccion a la que
-- se manda, y `nombre`/`empresa` son la foto del momento para las variables
-- de la plantilla. El UNIQUE impide repetir una direccion en la misma lista.
-- `contacto_id` y `posible_cliente_id` no tienen clave foranea.
CREATE TABLE IF NOT EXISTS `lista_miembros` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lista_id`           BIGINT UNSIGNED NOT NULL,
  `contacto_id`        BIGINT UNSIGNED DEFAULT NULL,
  `posible_cliente_id` BIGINT UNSIGNED DEFAULT NULL,
  `email`              VARCHAR(190) NOT NULL,
  `nombre`             VARCHAR(160) DEFAULT NULL,
  `empresa`            VARCHAR(200) DEFAULT NULL,
  `creado_en`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lm` (`lista_id`, `email`),
  KEY `ix_lm_contacto` (`contacto_id`),
  CONSTRAINT `fk_lm_lista` FOREIGN KEY (`lista_id`) REFERENCES `listas` (`id`) ON DELETE CASCADE,
  -- (migracion 019) un contacto purgado sale de las listas: no sigue recibiendo campañas
  CONSTRAINT `fk_lm_contacto` FOREIGN KEY (`contacto_id`) REFERENCES `contactos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lm_pc` FOREIGN KEY (`posible_cliente_id`) REFERENCES `posibles_clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campanas de correo masivo. Las crea la pantalla de Campanas; el estado lo
-- mueven la pantalla (programar, pausar, cancelar) y el despachador del cron
-- ('enviando' → 'completada'). `cuenta_correo_id` (la casilla desde la que
-- sale) y `categoria_id` son de la migracion 002, sin clave foranea; sus
-- indices, de la 018.
CREATE TABLE IF NOT EXISTS `campanas` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`        VARCHAR(200) NOT NULL,
  `tipo`          VARCHAR(40)  NOT NULL DEFAULT 'Correo masivo',
  `estado`        ENUM('borrador','programada','enviando','pausada','completada','cancelada')
                  NOT NULL DEFAULT 'borrador',
  `plantilla_id`  BIGINT UNSIGNED DEFAULT NULL,
  `lista_id`      BIGINT UNSIGNED DEFAULT NULL,
  `cuenta_correo_id` INT UNSIGNED DEFAULT NULL,   -- (migracion 002)
  `categoria_id`  INT UNSIGNED DEFAULT NULL,      -- (migracion 002)
  `propietario_id` BIGINT UNSIGNED DEFAULT NULL,
  `remitente_nombre` VARCHAR(160) DEFAULT NULL,
  `remitente_email`  VARCHAR(190) DEFAULT NULL,
  `responder_a`   VARCHAR(190) DEFAULT NULL,
  `copia_a`       VARCHAR(500) DEFAULT NULL,   -- CC separados por coma
  `asunto`        VARCHAR(255) DEFAULT NULL,   -- pisa el de la plantilla si esta
  -- Ritmo de envio: la pausa aleatoria es lo que evita el filtro anti-spam
  `pausa_min_seg` INT UNSIGNED NOT NULL DEFAULT 25,
  `pausa_max_seg` INT UNSIGNED NOT NULL DEFAULT 45,
  `limite_diario` INT UNSIGNED NOT NULL DEFAULT 300,
  `enviar_desde`  TIME DEFAULT NULL,           -- ventana horaria permitida
  `enviar_hasta`  TIME DEFAULT NULL,
  `programada_para` DATETIME DEFAULT NULL,
  `inicio_en`     DATETIME DEFAULT NULL,
  `fin_en`        DATETIME DEFAULT NULL,
  -- Contadores (se actualizan al despachar; evitan COUNT(*) en cada pantalla)
  `total`         INT UNSIGNED NOT NULL DEFAULT 0,
  `enviados`      INT UNSIGNED NOT NULL DEFAULT 0,
  `rebotados`     INT UNSIGNED NOT NULL DEFAULT 0,
  `respondidos`   INT UNSIGNED NOT NULL DEFAULT 0,
  `desuscriptos`  INT UNSIGNED NOT NULL DEFAULT 0,
  `reuniones`     INT UNSIGNED NOT NULL DEFAULT 0,
  `errores`       INT UNSIGNED NOT NULL DEFAULT 0,
  `descripcion`   TEXT DEFAULT NULL,
  `creado_por`    BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_camp_estado` (`estado`),
  KEY `ix_camp_elim`   (`eliminado_en`),
  -- Los de las claves foraneas, con el nombre que MariaDB les da solo: asi
  -- quedan en el mismo orden que en una base migrada.
  KEY `fk_camp_plantilla` (`plantilla_id`),
  KEY `fk_camp_lista`     (`lista_id`),
  KEY `ix_camp_cuenta`   (`cuenta_correo_id`),   -- (migracion 018)
  KEY `ix_camp_categoria` (`categoria_id`),       -- (migracion 018)
  CONSTRAINT `fk_camp_plantilla` FOREIGN KEY (`plantilla_id`) REFERENCES `plantillas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_camp_lista`     FOREIGN KEY (`lista_id`)     REFERENCES `listas` (`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La COLA. Una fila por destinatario. El cron toma las 'pendiente' cuyo
-- proximo_intento ya paso y las despacha de a una.
-- Cada envio se toma pasandolo de 'pendiente' a 'enviando' con un UPDATE
-- condicionado al estado: es lo que impide que dos corridas del cron manden
-- el mismo correo. `token` va en el enlace de baja y en el pixel de apertura.
-- `cuenta_correo_id` (la casilla que lo mando) es de la migracion 002; el
-- indice (casilla, enviado_en) de la 018 es el que usa la cuenta de «enviados
-- hoy por casilla».
CREATE TABLE IF NOT EXISTS `envios` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campana_id`    BIGINT UNSIGNED NOT NULL,
  `cuenta_correo_id` INT UNSIGNED DEFAULT NULL,   -- (migracion 002)
  `contacto_id`  BIGINT UNSIGNED DEFAULT NULL,
  `posible_cliente_id` BIGINT UNSIGNED DEFAULT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `nombre`        VARCHAR(160) DEFAULT NULL,
  `empresa`       VARCHAR(200) DEFAULT NULL,
  `estado`        ENUM('pendiente','enviando','enviado','rebotado','rechazado','omitido','error')
                  NOT NULL DEFAULT 'pendiente',
  `motivo`        VARCHAR(255) DEFAULT NULL,   -- por que se omitio / que error dio
  `intentos`      INT UNSIGNED NOT NULL DEFAULT 0,
  `proximo_intento_en` DATETIME DEFAULT NULL,
  `enviado_en`    DATETIME     DEFAULT NULL,
  `message_id`    VARCHAR(255) DEFAULT NULL,   -- para cruzar rebotes y respuestas
  `token`         CHAR(32)     NOT NULL,       -- desuscripcion y seguimiento
  `abierto_en`    DATETIME     DEFAULT NULL,
  `respondido_en` DATETIME     DEFAULT NULL,
  `rebotado_en`   DATETIME     DEFAULT NULL,
  `creado_en`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- La cola del despachador busca por (estado, proximo_intento_en): ese es el
  -- indice `ix_envios_cola`. `ix_envios_msgid` cruza rebotes y respuestas.
  UNIQUE KEY `uq_envios_campana_email` (`campana_id`, `email`),   -- nunca dos veces al mismo
  UNIQUE KEY `uq_envios_token` (`token`),
  KEY `ix_envios_cola`   (`estado`, `proximo_intento_en`),
  KEY `ix_envios_msgid`  (`message_id`),
  KEY `ix_envios_email`  (`email`),
  KEY `ix_envios_contacto` (`contacto_id`),
  KEY `ix_envios_cuenta` (`cuenta_correo_id`, `enviado_en`),   -- (migracion 018)
  CONSTRAINT `fk_envios_campana` FOREIGN KEY (`campana_id`) REFERENCES `campanas` (`id`) ON DELETE CASCADE,
  -- (migracion 019) la purga de un contacto deja el envio como historial, sin la referencia
  CONSTRAINT `fk_envios_contacto` FOREIGN KEY (`contacto_id`) REFERENCES `contactos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_envios_pc` FOREIGN KEY (`posible_cliente_id`) REFERENCES `posibles_clientes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitacora fina de cada envio (apertura, clic, rebote, respuesta, baja).
-- La escriben el despachador, el lector IMAP y las paginas publicas de baja y
-- seguimiento. Solo se agrega, nunca se edita; se borra con el envio.
CREATE TABLE IF NOT EXISTS `envio_eventos` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `envio_id`  BIGINT UNSIGNED NOT NULL,
  `tipo`      ENUM('encolado','enviado','apertura','clic','respuesta','rebote','baja','error') NOT NULL,
  `detalle`   VARCHAR(500) DEFAULT NULL,
  `ip`        VARCHAR(64)  DEFAULT NULL,   -- hash de la IP (015), no la IP en claro
  `creado_en` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ee_envio` (`envio_id`, `creado_en`),
  CONSTRAINT `fk_ee_envio` FOREIGN KEY (`envio_id`) REFERENCES `envios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lista negra global. Se consulta ANTES de encolar cualquier envio.
-- Una fila por direccion (clave primaria = email, ya normalizado en
-- minusculas): la escriben el enlace de baja, el lector IMAP (rebote duro) y
-- el admin a mano. Vale para todas las campanas y todas las casillas.
CREATE TABLE IF NOT EXISTS `desuscripciones` (
  `email`      VARCHAR(190) NOT NULL,
  `motivo`     ENUM('baja','rebote_duro','queja','manual') NOT NULL DEFAULT 'baja',
  `campana_id` BIGINT UNSIGNED DEFAULT NULL,
  `detalle`    VARCHAR(255) DEFAULT NULL,
  `ip`         VARCHAR(64)  DEFAULT NULL,   -- hash de la IP (015), no la IP en claro
  `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  KEY `ix_desus_motivo` (`motivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado del lector IMAP: hasta que UID se leyo cada buzon, para no reescanear
-- todo en cada pasada del cron (mismo truco que Pulsemail).
-- Con varias casillas (migracion 002) hay una fila por casilla, con `buzon` =
-- 'INBOX:<usuario>' (imap.php). La columna `cuentas_correo.imap_ultimo_uid`
-- que creaba la migracion 002 nunca se uso y la 019 la saca.
CREATE TABLE IF NOT EXISTS `imap_estado` (
  `buzon`      VARCHAR(120) NOT NULL,
  `ultimo_uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  -- UIDVALIDITY de la casilla al guardar la marca: si cambia, el servidor
  -- renumero los mensajes y la marca ya no sirve (016).
  `uidvalidity` BIGINT UNSIGNED DEFAULT NULL,
  `ultima_corrida` DATETIME DEFAULT NULL,
  PRIMARY KEY (`buzon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Casillas de correo (migracion 002, + `firma_html` de la 005 y lo de la
-- 018). Con varias casillas se pueden correr campanas en paralelo sin que se
-- pisen el limite diario ni la reputacion entre ellas.
-- La escribe la pantalla de Casillas (cuentas_correo.php); la leen el
-- despachador (para mandar) y el lector IMAP (rebotes y respuestas).
-- `smtp_pass`/`imap_pass` son contrasenas «de aplicacion» de Google cifradas
-- con el secreto de la instalacion (cifrar()), que no se pueden volver a leer
-- de ningun lado: por eso app/reinicio.php conserva esta tabla. `smtp_pass`
-- tiene DEFAULT '' (018) porque el alta la graba en un UPDATE aparte.
-- `firma_html` es la firma de la casilla (no la del usuario, que esta en
-- `usuarios.firma_html`). `ultimo_error`/`verificada_en` los deja la prueba
-- de conexion. La marca de lectura de cada casilla vive en
-- `imap_estado`.
-- Una direccion por casilla viva: `remitente_vivo` (018) es una columna
-- calculada que vale el remitente mientras la casilla no esta dada de baja y
-- NULL despues, asi que una casilla dada de baja no impide volver a cargar
-- la misma direccion.
CREATE TABLE IF NOT EXISTS `cuentas_correo` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`         VARCHAR(120) NOT NULL,          -- "Comercial", "RRHH", "Carlos"
  `remitente`      VARCHAR(190) NOT NULL,          -- direccion que ve el destinatario
  `remitente_nombre` VARCHAR(160) DEFAULT NULL,
  `firma_html`     TEXT DEFAULT NULL,              -- (migracion 005)
  `responder_a`    VARCHAR(190) DEFAULT NULL,
  -- Salida
  `smtp_host`      VARCHAR(120) NOT NULL DEFAULT 'smtp.gmail.com',
  `smtp_port`      INT UNSIGNED NOT NULL DEFAULT 587,
  `smtp_seguridad` ENUM('tls','ssl') NOT NULL DEFAULT 'tls',
  `smtp_user`      VARCHAR(190) NOT NULL,
  `smtp_pass`      VARCHAR(255) NOT NULL DEFAULT '',
  -- Entrada (rebotes y respuestas). Si se deja vacia se usa la de salida.
  `imap_host`      VARCHAR(120) DEFAULT 'imap.gmail.com',
  `imap_port`      INT UNSIGNED DEFAULT 993,
  `imap_user`      VARCHAR(190) DEFAULT NULL,
  `imap_pass`      VARCHAR(255) DEFAULT NULL,
  -- Ritmo propio de la cuenta: cada casilla tiene su tope diario.
  `limite_diario`  INT UNSIGNED NOT NULL DEFAULT 300,
  `usuario_id`     BIGINT UNSIGNED DEFAULT NULL,   -- duenio, si es personal
  `activo`         TINYINT(1)   NOT NULL DEFAULT 1,
  `predeterminada` TINYINT(1)   NOT NULL DEFAULT 0,
  `ultimo_error`   VARCHAR(255) DEFAULT NULL,
  `verificada_en`  DATETIME     DEFAULT NULL,
  `creado_en`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en`   DATETIME     DEFAULT NULL,
  `remitente_vivo` VARCHAR(190)                    -- (migracion 018)
      GENERATED ALWAYS AS (IF(`eliminado_en` IS NULL, `remitente`, NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cuenta_remitente_viva` (`remitente_vivo`),
  KEY `ix_cc_activo` (`activo`, `eliminado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Segmentos de las listas y campanas (migracion 002): RRHH, petroleras,
-- bancos… No hay pantalla para crearlos: salen de los datos iniciales del
-- final. Por eso app/reinicio.php conserva la tabla.
CREATE TABLE IF NOT EXISTS `categorias` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(80) NOT NULL,
  `color`  CHAR(7)     NOT NULL DEFAULT '#663399',
  `orden`  INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categorias_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imagenes de las campanas (migracion 002). Se guardan en el servidor y se
-- sirven por URL publica: incrustarlas en el correo engorda el mensaje y
-- Gmail recorta a los 102 KB de HTML. `archivo` es el nombre en disco
-- (unico); `nombre`, el original que subio la persona.
CREATE TABLE IF NOT EXISTS `imagenes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(255) NOT NULL,
  `archivo`    VARCHAR(120) NOT NULL,
  `mime`       VARCHAR(60)  NOT NULL,
  `bytes`      INT UNSIGNED NOT NULL DEFAULT 0,
  `ancho`      INT UNSIGNED DEFAULT NULL,
  `alto`       INT UNSIGNED DEFAULT NULL,
  `creado_por` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_imagenes_archivo` (`archivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alertas del sistema (migracion 003). Las crea notificar() (respuesta a una
-- campana, reunion agendada, rebote, fin de campana); la campana de la barra
-- superior las muestra y las marca leidas, y el cron manda por correo las
-- que tienen `email_en` vacio, agrupadas en un solo mensaje por corrida.
-- `usuario_id` NULL = para todo el equipo.
CREATE TABLE IF NOT EXISTS `notificaciones` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`   BIGINT UNSIGNED DEFAULT NULL,
  `tipo`         VARCHAR(40)  NOT NULL,       -- respuesta | reunion | rebote | campana | sistema
  `titulo`       VARCHAR(200) NOT NULL,
  `detalle`      VARCHAR(500) DEFAULT NULL,
  `entidad`      VARCHAR(40)  DEFAULT NULL,   -- adonde lleva el clic
  `entidad_id`   BIGINT UNSIGNED DEFAULT NULL,
  `url`          VARCHAR(255) DEFAULT NULL,
  -- «Visto en pantalla» y «avisado por correo» son dos cosas distintas.
  `leida_en`     DATETIME DEFAULT NULL,
  `email_en`     DATETIME DEFAULT NULL,
  `creada_en`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_usuario` (`usuario_id`, `leida_en`, `id`),
  KEY `idx_notif_pendiente_email` (`email_en`, `id`),
  KEY `idx_notif_creada` (`creada_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversaciones (migracion 004): las respuestas que entran (las guarda el
-- lector IMAP, 'entrante') y lo que se contesta desde el CRM ('saliente').
-- Ninguna columna de ids tiene clave foranea: si la purga borra el contacto
-- o la campana, los mensajes quedan apuntando a nada. `cuenta_correo_id` es
-- INT como `cuentas_correo.id` (la 018 lo corrigio: era BIGINT).
-- `uq_msg`: el Message-ID evita guardar dos veces el mismo correo al releer
-- la casilla; un correo SIN Message-ID queda con NULL y ese si se puede
-- repetir (los NULL no chocan en un UNIQUE).
CREATE TABLE IF NOT EXISTS `mensajes` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contacto_id`  BIGINT UNSIGNED DEFAULT NULL,
  `envio_id`     BIGINT UNSIGNED DEFAULT NULL,
  `campana_id`   BIGINT UNSIGNED DEFAULT NULL,
  `cuenta_correo_id` INT UNSIGNED DEFAULT NULL,
  `direccion`    ENUM('entrante','saliente') NOT NULL,
  `de`           VARCHAR(190) NOT NULL,
  `de_nombre`    VARCHAR(160) DEFAULT NULL,
  `para`         VARCHAR(190) NOT NULL,
  `asunto`       VARCHAR(400) DEFAULT NULL,
  `cuerpo_texto` MEDIUMTEXT   DEFAULT NULL,
  `cuerpo_html`  MEDIUMTEXT   DEFAULT NULL,
  -- Las tres cabeceras que pegan la respuesta al mensaje original.
  `message_id`   VARCHAR(255) DEFAULT NULL,
  `in_reply_to`  VARCHAR(255) DEFAULT NULL,
  `referencias`  TEXT         DEFAULT NULL,
  `fecha`        DATETIME     NOT NULL,
  `usuario_id`   BIGINT UNSIGNED DEFAULT NULL,   -- quien contesto, si salio de aca
  `leido_en`     DATETIME     DEFAULT NULL,
  `creado_en`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_msg` (`message_id`),
  KEY `ix_msg_contacto` (`contacto_id`, `fecha`),
  KEY `ix_msg_envio` (`envio_id`),
  KEY `ix_msg_sin_leer` (`leido_en`, `direccion`),
  -- La conversacion se arma por direccion de correo (016).
  KEY `ix_msg_de`   (`de`, `fecha`),
  KEY `ix_msg_para` (`para`, `fecha`),
  -- (migracion 019) la conversacion queda aunque se purgue el contacto o la campaña
  CONSTRAINT `fk_msg_contacto` FOREIGN KEY (`contacto_id`) REFERENCES `contactos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_msg_envio`    FOREIGN KEY (`envio_id`)    REFERENCES `envios` (`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_msg_campana`  FOREIGN KEY (`campana_id`)  REFERENCES `campanas` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lecturas de alertas por persona (migracion 010): una alerta del equipo
-- (notificaciones.usuario_id NULL) se marca leida para cada usuario por
-- separado, asi que una persona no se la esconde a las demas. Las alertas
-- personales siguen usando notificaciones.leida_en.
CREATE TABLE IF NOT EXISTS `notificacion_lecturas` (
  `notificacion_id` BIGINT UNSIGNED NOT NULL,
  `usuario_id`      BIGINT UNSIGNED NOT NULL,
  `leida_en`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notificacion_id`, `usuario_id`),
  KEY `ix_lectura_usuario` (`usuario_id`, `notificacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens de baja que sobreviven a la purga (migracion 015): antes de borrar
-- una campana de la papelera, sus tokens se copian aca, para que el enlace de
-- baja de un correo ya entregado siga funcionando siempre.
CREATE TABLE IF NOT EXISTS `bajas_tokens` (
  `token`      CHAR(32)        NOT NULL,
  `email`      VARCHAR(190)    NOT NULL,
  `campana_id` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token`),
  KEY `ix_bt_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  MIGRACION / IMPORTACION
-- ============================================================

-- Un registro por cada importacion (CSV de Zoho o lista suelta) con sus
-- totales: que entro, que se actualizo, que se salteo y por que (`resumen`).
-- Es solo historial: borrarla no deshace ninguna importacion.
CREATE TABLE IF NOT EXISTS `import_lotes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `origen`     VARCHAR(60)  NOT NULL,     -- 'zoho' | 'csv'
  `modulo`     VARCHAR(40)  NOT NULL,     -- contactos | cuentas | posibles_clientes | lista
  `archivo`    VARCHAR(255) DEFAULT NULL,
  `filas`      INT UNSIGNED NOT NULL DEFAULT 0,
  `creados`    INT UNSIGNED NOT NULL DEFAULT 0,
  `actualizados` INT UNSIGNED NOT NULL DEFAULT 0,
  `omitidos`   INT UNSIGNED NOT NULL DEFAULT 0,
  `errores`    INT UNSIGNED NOT NULL DEFAULT 0,
  `resumen`    TEXT         DEFAULT NULL,
  `creado_por` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesiones de ITHub cerradas (aviso por detras de ITHub, ithub-logout.php).
-- auth_user() corta toda sesion del CRM abierta con ese `sid`. Se limpia sola
-- (mas de dos dias no sirve: la sesion maxima del CRM es de 8 h).
CREATE TABLE IF NOT EXISTS `ithub_sesiones_cerradas` (
  `sid`        CHAR(32)  NOT NULL,     -- id de la sesion de ITHub (claim `sid`)
  `cerrada_en` DATETIME  NOT NULL,
  PRIMARY KEY (`sid`),
  KEY `idx_ithub_cerrada_en` (`cerrada_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  DATOS INICIALES (configuracion, no datos de negocio)
-- ============================================================

-- ON DUPLICATE KEY UPDATE `valor` = `valor`: si el ajuste ya existe no se
-- toca, asi que reimportar el esquema no pisa lo que cargo el admin.
--
-- `app_entorno` arranca en 'testing'. Una instalacion que va a tener datos
-- reales tiene que pasarse a 'produccion' (Ajustes → Modo de trabajo) antes
-- de cargar el primer dato: en 'testing' las campanas no tienen las
-- protecciones de produccion. app/reinicio.php, ademas de 'testing', exige
-- que la instalacion sea la de pruebas (carpeta crm-pruebas o base
-- *_pruebas) y una confirmacion escrita.
INSERT INTO `app_settings` (`clave`, `valor`) VALUES
  ('app_nombre',      'CRM INTELLIHELP'),
  ('app_entorno',     'testing'),
  ('moneda_default',  'ARS'),
  ('smtp_host',       'smtp.gmail.com'),
  ('smtp_port',       '587'),
  ('smtp_seguridad',  'tls'),
  ('imap_host',       'imap.gmail.com'),
  ('imap_port',       '993'),
  ('campana_pausa_min', '25'),
  ('campana_pausa_max', '45'),
  ('campana_limite_diario', '300'),
  -- Dias que se conserva lo archivado antes de borrarlo de verdad.
  ('papelera_dias', '60'),
  -- Modo prueba (015): ademas de los usuarios activos, a quienes pueden
  -- salir las campanas (correos separados por coma).
  ('testing_lista_blanca', ''),
  -- Entradas desde la web (migracion 008): responsable de las consultas
  -- (vacio = el vendedor del tipo 'diagnostico'), aviso en la campana y si el
  -- invitado va tambien como invitado del evento de Google.
  ('web_dueno_id', ''),
  ('avisar_web', '1'),
  ('agenda_invitado_en_google', '1'),
  -- Feriados (migracion 009): si la agenda los respeta, que jurisdiccion
  -- rige ademas de los nacionales y el estado de la sincronizacion.
  ('feriados_activo', '1'),
  ('feriados_jurisdiccion', 'CABA'),
  ('feriados_sincronizado_en', ''),
  ('feriados_intento_en', ''),
  ('feriados_ultimo_error', ''),
  -- Datos de la empresa para las plantillas (migracion 002).
  ('empresa_nombre',   'INTELLIHELP'),
  ('empresa_web',      'intellihelp.tech'),
  ('empresa_telefono', ''),
  ('empresa_email',    ''),
  ('empresa_whatsapp', ''),
  -- Que alertas se avisan por correo y a quien (migracion 003; vacio = a
  -- todos los administradores activos).
  ('avisar_respuesta', '1'),
  ('avisar_reunion',   '1'),
  ('avisar_rebote',    '0'),
  ('avisar_campana',   '1'),
  ('avisar_email_a',   '')
ON DUPLICATE KEY UPDATE `valor` = `valor`;

-- Segmentos (migracion 002). Reimportar solo vuelve los colores a estos.
INSERT INTO `categorias` (`nombre`, `color`, `orden`) VALUES
  ('Recursos Humanos', '#663399', 1),
  ('Energia y Petroleo', '#B0561F', 2),
  ('Bancos y Finanzas', '#1F6F9C', 3),
  ('Laboratorios y Quimica', '#5F8A12', 4),
  ('Tecnologia', '#7C3AED', 5),
  ('Industria', '#8A6D3B', 6),
  ('Control de accesos', '#0F766E', 7),
  ('General', '#6F6A7D', 99)
ON DUPLICATE KEY UPDATE `color` = VALUES(`color`);

-- Embudo por defecto. Las probabilidades salen de la propuesta de
-- md/ZOHO-CRM-REFERENCIA.md y se pueden cambiar desde la app.
-- Reimportar sobre una base en uso no pisa nada: si el embudo 1 o una etapa
-- con el mismo (embudo, nombre) ya existen, quedan como estan (antes volvian
-- a estos nombres y probabilidades). Las etapas que se renombraron en la app
-- no coinciden y se agregan de nuevo.
-- (app/reinicio.php siembra otro embudo, de 5 etapas, cuando no hay ninguno.)
INSERT INTO `embudos` (`id`, `nombre`, `activo`, `predeterminado`, `orden`) VALUES
  (1, 'Ventas', 1, 1, 0)
ON DUPLICATE KEY UPDATE `id` = `id`;

INSERT INTO `etapas` (`embudo_id`, `nombre`, `probabilidad`, `tipo`, `color`, `orden`) VALUES
  (1, 'Calificacion',      10, 'abierta', '#8B6BB7', 1),
  (1, 'Relevamiento',      20, 'abierta', '#7C5AAF', 2),
  (1, 'Propuesta enviada', 40, 'abierta', '#663399', 3),
  (1, 'Negociacion',       60, 'abierta', '#B08D57', 4),
  (1, 'A firmar',          80, 'abierta', '#7FA828', 5),
  (1, 'Ganado',           100, 'ganada',  '#9CC930', 6),
  (1, 'Perdido',            0, 'perdida', '#A12A1E', 7)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Se vuelven a prender las claves foraneas apagadas al principio. Una
-- instalacion nueva no necesita nada mas: las migraciones hasta la 009 y la
-- 018 ya estan incluidas arriba.
SET FOREIGN_KEY_CHECKS = 1;

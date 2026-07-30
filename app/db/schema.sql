-- ============================================================
--  CRM INTELLIHELP — esquema de base de datos
--  MariaDB 10.4+ / MySQL 8.0+  ·  utf8mb4
--
--  Uso:  mysql -u USUARIO -p BASE < app/db/schema.sql
--
--  Convenciones (valen para TODAS las tablas de negocio):
--    · id            BIGINT UNSIGNED autoincremental
--    · creado_por    usuario que dio el alta
--    · creado_en / actualizado_en
--    · eliminado_en  borrado LOGICO — nada se borra fisico (regla de oro)
--    · zoho_id       id original de Zoho, para reimportar sin duplicar
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- ============================================================
--  PLATAFORMA
-- ============================================================

-- Usuarios de la aplicacion. No hay contrasenas: el acceso es 100% Google
-- Workspace, asi que solo guardamos la identidad que devuelve Google.
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
CREATE TABLE IF NOT EXISTS `app_settings` (
  `clave`          VARCHAR(60) NOT NULL,
  `valor`          TEXT        DEFAULT NULL,
  `actualizado_en` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria. Alimenta la seccion CRON. Dos canales: 'app' (lo que hace la
-- gente + errores) y 'api' (cada pedido a la API de lectura).
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

-- Tokens de la API de lectura (para ITHOS/Jarvis), mismo modelo que APP Tickets:
-- se guarda solo el SHA-256; la clave completa se muestra una unica vez.
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(120) NOT NULL,
  `prefijo`    VARCHAR(16)  NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  `scope`      ENUM('lectura','root') NOT NULL DEFAULT 'lectura',
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
CREATE TABLE IF NOT EXISTS `cuentas` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`          VARCHAR(200) NOT NULL,
  `propietario_id`  BIGINT UNSIGNED DEFAULT NULL,
  `cuenta_padre_id` BIGINT UNSIGNED DEFAULT NULL,
  `tipo`            VARCHAR(40)  DEFAULT NULL,   -- Cliente / Prospecto / Socio / ...
  `sector`          VARCHAR(80)  DEFAULT NULL,
  `sitio_web`       VARCHAR(255) DEFAULT NULL,
  `telefono`        VARCHAR(60)  DEFAULT NULL,
  `email`           VARCHAR(190) DEFAULT NULL,
  `cuit`            VARCHAR(11)  DEFAULT NULL,   -- Argentina (modulo 11)
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
  UNIQUE KEY `uq_cuentas_zoho` (`zoho_id`),
  KEY `ix_cuentas_nombre` (`nombre`),
  KEY `ix_cuentas_prop`   (`propietario_id`),
  KEY `ix_cuentas_elim`   (`eliminado_en`),
  KEY `ix_cuentas_cuit`   (`cuit`),
  KEY `fk_cuentas_padre`  (`cuenta_padre_id`),
  CONSTRAINT `fk_cuentas_prop`  FOREIGN KEY (`propietario_id`)  REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cuentas_padre` FOREIGN KEY (`cuenta_padre_id`) REFERENCES `cuentas` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contactos = personas. Los campos "extra" (conmutador, telefono directo,
-- linkedin, correo terciario) existen porque el cliente ya los usa en Zoho.
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
  `email_rebotado` TINYINT(1)   NOT NULL DEFAULT 0,
  `ultima_actividad_en` DATETIME DEFAULT NULL,
  `zoho_id`        VARCHAR(40)  DEFAULT NULL,
  `creado_por`     BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en`   DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contactos_zoho` (`zoho_id`),
  KEY `ix_contactos_email`  (`email`),
  KEY `ix_contactos_cuenta` (`cuenta_id`),
  KEY `ix_contactos_prop`   (`propietario_id`),
  KEY `ix_contactos_elim`   (`eliminado_en`),
  KEY `ix_contactos_nombre` (`apellido`, `nombre`),
  KEY `fk_contactos_reporta`(`reporta_a_id`),
  CONSTRAINT `fk_contactos_cuenta`  FOREIGN KEY (`cuenta_id`)      REFERENCES `cuentas` (`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_contactos_prop`    FOREIGN KEY (`propietario_id`) REFERENCES `usuarios` (`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_contactos_reporta` FOREIGN KEY (`reporta_a_id`)   REFERENCES `contactos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Posibles clientes (leads): contacto crudo, todavia sin calificar. Al
-- convertirlo se crean Cuenta + Contacto (+ Negocio opcional) y queda marcado.
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
CREATE TABLE IF NOT EXISTS `etiquetas` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(60) NOT NULL,
  `color`  CHAR(7)     NOT NULL DEFAULT '#663399',
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_etiquetas_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `embudos` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(80)  NOT NULL,
  `activo` TINYINT(1)   NOT NULL DEFAULT 1,
  `predeterminado` TINYINT(1) NOT NULL DEFAULT 0,
  `orden`  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_embudos_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  CONSTRAINT `fk_ar_tipo`    FOREIGN KEY (`tipo_id`)    REFERENCES `agenda_tipos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ar_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Credenciales OAuth de Google por usuario (refresh token cifrado en la app).
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

CREATE TABLE IF NOT EXISTS `plantillas` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(160) NOT NULL,
  `asunto`     VARCHAR(255) NOT NULL,
  `cuerpo_html` MEDIUMTEXT  NOT NULL,
  `cuerpo_texto` MEDIUMTEXT DEFAULT NULL,   -- alternativa en texto plano (anti-spam)
  `carpeta`    VARCHAR(80)  DEFAULT NULL,
  `activo`     TINYINT(1)   NOT NULL DEFAULT 1,
  `creado_por` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en` DATETIME   DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_plantillas_elim` (`eliminado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `listas` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(160) NOT NULL,
  `descripcion` TEXT        DEFAULT NULL,
  `creado_por` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eliminado_en` DATETIME   DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_listas_elim` (`eliminado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un miembro puede ser un contacto, un posible cliente o un correo suelto
-- (asi se puede cargar una lista importada sin ensuciar el CRM).
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
  CONSTRAINT `fk_lm_lista` FOREIGN KEY (`lista_id`) REFERENCES `listas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `campanas` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`        VARCHAR(200) NOT NULL,
  `tipo`          VARCHAR(40)  NOT NULL DEFAULT 'Correo masivo',
  `estado`        ENUM('borrador','programada','enviando','pausada','completada','cancelada')
                  NOT NULL DEFAULT 'borrador',
  `plantilla_id`  BIGINT UNSIGNED DEFAULT NULL,
  `lista_id`      BIGINT UNSIGNED DEFAULT NULL,
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
  CONSTRAINT `fk_camp_plantilla` FOREIGN KEY (`plantilla_id`) REFERENCES `plantillas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_camp_lista`     FOREIGN KEY (`lista_id`)     REFERENCES `listas` (`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La COLA. Una fila por destinatario. El cron toma las 'pendiente' cuyo
-- proximo_intento ya paso y las despacha de a una.
CREATE TABLE IF NOT EXISTS `envios` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campana_id`    BIGINT UNSIGNED NOT NULL,
  `contacto_id`   BIGINT UNSIGNED DEFAULT NULL,
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
  UNIQUE KEY `uq_envios_campana_email` (`campana_id`, `email`),   -- nunca dos veces al mismo
  UNIQUE KEY `uq_envios_token` (`token`),
  KEY `ix_envios_cola`   (`estado`, `proximo_intento_en`),
  KEY `ix_envios_msgid`  (`message_id`),
  KEY `ix_envios_email`  (`email`),
  KEY `ix_envios_contacto` (`contacto_id`),
  CONSTRAINT `fk_envios_campana` FOREIGN KEY (`campana_id`) REFERENCES `campanas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitacora fina de cada envio (apertura, clic, rebote, respuesta, baja).
CREATE TABLE IF NOT EXISTS `envio_eventos` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `envio_id`  BIGINT UNSIGNED NOT NULL,
  `tipo`      ENUM('encolado','enviado','apertura','clic','respuesta','rebote','baja','error') NOT NULL,
  `detalle`   VARCHAR(500) DEFAULT NULL,
  `ip`        VARCHAR(45)  DEFAULT NULL,
  `creado_en` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ee_envio` (`envio_id`, `creado_en`),
  CONSTRAINT `fk_ee_envio` FOREIGN KEY (`envio_id`) REFERENCES `envios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lista negra global. Se consulta ANTES de encolar cualquier envio.
CREATE TABLE IF NOT EXISTS `desuscripciones` (
  `email`      VARCHAR(190) NOT NULL,
  `motivo`     ENUM('baja','rebote_duro','queja','manual') NOT NULL DEFAULT 'baja',
  `campana_id` BIGINT UNSIGNED DEFAULT NULL,
  `detalle`    VARCHAR(255) DEFAULT NULL,
  `ip`         VARCHAR(45)  DEFAULT NULL,
  `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  KEY `ix_desus_motivo` (`motivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado del lector IMAP: hasta que UID se leyo cada buzon, para no reescanear
-- todo en cada pasada del cron (mismo truco que Pulsemail).
CREATE TABLE IF NOT EXISTS `imap_estado` (
  `buzon`      VARCHAR(120) NOT NULL,
  `ultimo_uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `ultima_corrida` DATETIME DEFAULT NULL,
  PRIMARY KEY (`buzon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  MIGRACION / IMPORTACION
-- ============================================================

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


-- ============================================================
--  DATOS INICIALES (configuracion, no datos de negocio)
-- ============================================================

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
  ('campana_limite_diario', '300')
ON DUPLICATE KEY UPDATE `valor` = `valor`;

-- Embudo por defecto. Las probabilidades son las que propusimos en
-- md/ZOHO-CRM-REFERENCIA.md y el cliente puede cambiarlas desde la app.
INSERT INTO `embudos` (`id`, `nombre`, `activo`, `predeterminado`, `orden`) VALUES
  (1, 'Ventas', 1, 1, 0)
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

INSERT INTO `etapas` (`embudo_id`, `nombre`, `probabilidad`, `tipo`, `color`, `orden`) VALUES
  (1, 'Calificacion',      10, 'abierta', '#8B6BB7', 1),
  (1, 'Relevamiento',      20, 'abierta', '#7C5AAF', 2),
  (1, 'Propuesta enviada', 40, 'abierta', '#663399', 3),
  (1, 'Negociacion',       60, 'abierta', '#B08D57', 4),
  (1, 'A firmar',          80, 'abierta', '#7FA828', 5),
  (1, 'Ganado',           100, 'ganada',  '#9CC930', 6),
  (1, 'Perdido',            0, 'perdida', '#A12A1E', 7)
ON DUPLICATE KEY UPDATE `probabilidad` = VALUES(`probabilidad`);

SET FOREIGN_KEY_CHECKS = 1;

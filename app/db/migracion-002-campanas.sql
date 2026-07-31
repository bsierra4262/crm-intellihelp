-- ============================================================
--  Migracion 002 — Campanas: varias cuentas de correo, categorias de
--  listas y plantillas con editor visual por bloques.
--
--  Es idempotente: se puede correr varias veces sin romper nada.
--    mysql -u USUARIO -p BASE < app/db/migracion-002-campanas.sql
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
--  1. Varias cuentas de correo
--
--  Antes habia UNA sola cuenta en los ajustes. Con varias se pueden
--  correr campanas en paralelo (una por vendedor, o una por rubro) sin
--  que se pisen el limite diario ni la reputacion entre ellas.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cuentas_correo` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`         VARCHAR(120) NOT NULL,          -- "Comercial", "RRHH", "Carlos"
  `remitente`      VARCHAR(190) NOT NULL,          -- direccion que ve el destinatario
  `remitente_nombre` VARCHAR(160) DEFAULT NULL,
  `responder_a`    VARCHAR(190) DEFAULT NULL,
  -- Salida
  `smtp_host`      VARCHAR(120) NOT NULL DEFAULT 'smtp.gmail.com',
  `smtp_port`      INT UNSIGNED NOT NULL DEFAULT 587,
  `smtp_seguridad` ENUM('tls','ssl') NOT NULL DEFAULT 'tls',
  `smtp_user`      VARCHAR(190) NOT NULL,
  `smtp_pass`      VARCHAR(255) NOT NULL,
  -- Entrada (rebotes y respuestas). Si se deja vacia se usa la de salida.
  `imap_host`      VARCHAR(120) DEFAULT 'imap.gmail.com',
  `imap_port`      INT UNSIGNED DEFAULT 993,
  `imap_user`      VARCHAR(190) DEFAULT NULL,
  `imap_pass`      VARCHAR(255) DEFAULT NULL,
  `imap_ultimo_uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cuenta_remitente` (`remitente`),
  KEY `ix_cc_activo` (`activo`, `eliminado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  2. Categorias de listas
--
--  Para separar por rubro (RRHH, petroleras, bancos...) y poder armar
--  campanas distintas sobre segmentos distintos.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categorias` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(80) NOT NULL,
  `color`  CHAR(7)     NOT NULL DEFAULT '#663399',
  `orden`  INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categorias_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ------------------------------------------------------------
--  3. Columnas nuevas
--  (se agregan sólo si faltan: el bloque de abajo lo resuelve)
-- ------------------------------------------------------------

-- listas.categoria_id
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'listas' AND COLUMN_NAME = 'categoria_id') = 0,
  'ALTER TABLE `listas` ADD COLUMN `categoria_id` INT UNSIGNED DEFAULT NULL AFTER `descripcion`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- plantillas.bloques (JSON del editor visual) y plantillas.tipo
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plantillas' AND COLUMN_NAME = 'bloques') = 0,
  'ALTER TABLE `plantillas` ADD COLUMN `bloques` MEDIUMTEXT DEFAULT NULL AFTER `cuerpo_texto`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plantillas' AND COLUMN_NAME = 'tipo') = 0,
  "ALTER TABLE `plantillas` ADD COLUMN `tipo` ENUM('visual','html') NOT NULL DEFAULT 'visual' AFTER `carpeta`",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plantillas' AND COLUMN_NAME = 'preajuste') = 0,
  "ALTER TABLE `plantillas` ADD COLUMN `preajuste` VARCHAR(40) DEFAULT NULL AFTER `tipo`",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- campanas.cuenta_correo_id y categoria
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campanas' AND COLUMN_NAME = 'cuenta_correo_id') = 0,
  'ALTER TABLE `campanas` ADD COLUMN `cuenta_correo_id` INT UNSIGNED DEFAULT NULL AFTER `lista_id`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campanas' AND COLUMN_NAME = 'categoria_id') = 0,
  'ALTER TABLE `campanas` ADD COLUMN `categoria_id` INT UNSIGNED DEFAULT NULL AFTER `cuenta_correo_id`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- La cuenta usada en cada envio: sirve para medir la reputacion por casilla.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'envios' AND COLUMN_NAME = 'cuenta_correo_id') = 0,
  'ALTER TABLE `envios` ADD COLUMN `cuenta_correo_id` INT UNSIGNED DEFAULT NULL AFTER `campana_id`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
--  4. Imagenes de las campanas
--
--  Se guardan en el servidor y se sirven por URL publica: incrustarlas
--  en el correo como adjunto engorda el mensaje y Gmail recorta a los
--  102 KB de HTML.
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
--  5. Datos de la empresa para las plantillas
-- ------------------------------------------------------------
INSERT INTO `app_settings` (`clave`, `valor`) VALUES
  ('empresa_nombre',   'INTELLIHELP'),
  ('empresa_web',      'intellihelp.tech'),
  ('empresa_telefono', ''),
  ('empresa_email',    ''),
  ('empresa_whatsapp', '')
ON DUPLICATE KEY UPDATE `valor` = `valor`;

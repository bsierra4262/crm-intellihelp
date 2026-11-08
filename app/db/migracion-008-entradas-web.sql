-- ============================================================
--  Entradas desde la web: consultas y reservas que llegan de intellihelp.tech.
--
--  Cada formulario que entra deja una fila acá, con la página desde la que se
--  mandó y el canal por el que llegó la persona. Es lo que permite contestar
--  «de dónde salen las reuniones», que hasta ahora sólo se podía adivinar.
--
--  La fila se crea ANTES de procesar el pedido, con la clave de idempotencia
--  única: si la web reintenta porque se le cortó la conexión, el segundo pedido
--  encuentra el primero y no se duplica la reunión.
--
--  Es idempotente: se puede correr muchas veces.
-- ============================================================

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ew_idem`   (`token_id`, `idempotencia`),
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
CREATE TABLE IF NOT EXISTS `api_limites` (
  `clave`    VARCHAR(120) NOT NULL,     -- 'k:<token>:escritura:60', 'ip:<hash>:reserva:3600', …
  `ventana`  INT UNSIGNED NOT NULL,     -- floor(time() / segundos)
  `conteo`   INT UNSIGNED NOT NULL DEFAULT 0,
  `vence_en` DATETIME     NOT NULL,
  PRIMARY KEY (`clave`, `ventana`),
  KEY `ix_al_vence` (`vence_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Claves de la API con permiso de escritura y su secreto para firmar.
ALTER TABLE `api_tokens`
  MODIFY COLUMN `scope` ENUM('lectura','root','escritura') NOT NULL DEFAULT 'lectura',
  ADD COLUMN IF NOT EXISTS `secreto_cifrado` TEXT DEFAULT NULL AFTER `token_hash`;

-- Reservas: una sola invitación que se actualiza, y de dónde vino.
ALTER TABLE `agenda_reservas`
  ADD COLUMN IF NOT EXISTS `ics_uid`         VARCHAR(255)    DEFAULT NULL AFTER `meet_url`,
  ADD COLUMN IF NOT EXISTS `ics_secuencia`   INT UNSIGNED    NOT NULL DEFAULT 0 AFTER `ics_uid`,
  ADD COLUMN IF NOT EXISTS `reprogramada_de` BIGINT UNSIGNED DEFAULT NULL AFTER `ics_secuencia`,
  ADD COLUMN IF NOT EXISTS `origen`          VARCHAR(20)     DEFAULT NULL AFTER `reprogramada_de`,
  ADD COLUMN IF NOT EXISTS `avisada_en`      DATETIME        DEFAULT NULL AFTER `origen`,
  ADD COLUMN IF NOT EXISTS `consentimiento_en`      DATETIME    DEFAULT NULL AFTER `avisada_en`,
  ADD COLUMN IF NOT EXISTS `consentimiento_version` VARCHAR(40) DEFAULT NULL AFTER `consentimiento_en`,
  ADD INDEX IF NOT EXISTS `ix_ar_prog`    (`reprogramada_de`),
  ADD INDEX IF NOT EXISTS `ix_ar_avisada` (`estado`, `avisada_en`);

-- Lo que ya estaba lo hizo el código anterior, que creaba el evento y mandaba
-- el mail en el mismo momento: se da por avisado, para que el cron no lo
-- vuelva a mandar. Las filas nuevas siempre traen `origen`, así que correr
-- esto otra vez no toca nada.
UPDATE `agenda_reservas`
   SET `avisada_en` = COALESCE(`avisada_en`, `creado_en`)
 WHERE `origen` IS NULL;

-- El identificador de la invitación es el mismo que venía usando agenda_ics():
-- así una cancelación de una reunión vieja sigue pisando la invitación original.
UPDATE `agenda_reservas`
   SET `ics_uid` = CONCAT('reserva-', `id`, '-', `token`, '@intellihelp.tech')
 WHERE `ics_uid` IS NULL;

-- Ajustes nuevos. No pisan lo que ya esté cargado.
INSERT INTO `app_settings` (`clave`, `valor`) VALUES
    ('web_dueno_id',               ''),
    ('avisar_web',                 '1'),
    ('agenda_invitado_en_google',  '1')
ON DUPLICATE KEY UPDATE `clave` = `clave`;

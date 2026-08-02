-- ============================================================
--  Conversaciones: el correo que entra y el que sale, guardado.
--
--  Hasta ahora, cuando alguien respondía una campaña, el sistema marcaba el
--  envío como «respondido» y tiraba el mensaje: no quedaba en ningún lado qué
--  había dicho la persona. La única acción posible era marcar la alerta como
--  leída, lo cual no sirve de nada: para leer la respuesta había que irse al
--  correo, y para contestarla también. El CRM avisaba de algo que después no
--  podía mostrar.
--
--  Con esto la respuesta queda guardada y se puede leer y contestar desde
--  adentro, y la conversación entera queda pegada al contacto: el que atiende
--  mañana ve lo que se habló, aunque lo haya hablado otro.
-- ============================================================

CREATE TABLE IF NOT EXISTS `mensajes` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contacto_id`  BIGINT UNSIGNED DEFAULT NULL,
  `envio_id`     BIGINT UNSIGNED DEFAULT NULL,
  `campana_id`   BIGINT UNSIGNED DEFAULT NULL,
  `cuenta_correo_id` BIGINT UNSIGNED DEFAULT NULL,
  -- Quién lo escribió: 'entrante' lo mandó el contacto, 'saliente' lo mandamos
  -- nosotros desde el CRM.
  `direccion`    ENUM('entrante','saliente') NOT NULL,
  `de`           VARCHAR(190) NOT NULL,
  `de_nombre`    VARCHAR(160) DEFAULT NULL,
  `para`         VARCHAR(190) NOT NULL,
  `asunto`       VARCHAR(400) DEFAULT NULL,
  `cuerpo_texto` MEDIUMTEXT   DEFAULT NULL,
  `cuerpo_html`  MEDIUMTEXT   DEFAULT NULL,
  -- Las tres cabeceras que hacen que el cliente de correo del destinatario
  -- muestre la respuesta pegada al mensaje original en vez de suelta.
  `message_id`   VARCHAR(255) DEFAULT NULL,
  `in_reply_to`  VARCHAR(255) DEFAULT NULL,
  `referencias`  TEXT         DEFAULT NULL,
  `fecha`        DATETIME     NOT NULL,
  `usuario_id`   BIGINT UNSIGNED DEFAULT NULL,   -- quién contestó, si salió de acá
  `leido_en`     DATETIME     DEFAULT NULL,
  `creado_en`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- El mismo correo puede aparecer dos veces si se relee la casilla: el
  -- Message-ID lo identifica y evita duplicarlo.
  UNIQUE KEY `uq_msg` (`message_id`),
  KEY `ix_msg_contacto` (`contacto_id`, `fecha`),
  KEY `ix_msg_envio` (`envio_id`),
  KEY `ix_msg_sin_leer` (`leido_en`, `direccion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

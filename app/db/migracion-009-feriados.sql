-- ============================================================
--  Feriados: los días en que la agenda no ofrece horarios.
--
--  Los nacionales se cargan solos: una vez por día el CRM consulta el
--  calendario oficial (ArgentinaDatos, que publica el del Ministerio del
--  Interior) del año en curso y del siguiente. Suma los que se decretan
--  durante el año, reubica los trasladables cuando se fija su fecha y quita
--  los que dejan de figurar. Los regionales y los días que la empresa no
--  trabaja se cargan a mano desde Más → Feriados.
--
--  · `jurisdiccion`: 'nacional' o el código de la provincia ('CABA', 'BA'…).
--    Rige el nacional y el de la jurisdicción configurada en el ajuste
--    `feriados_jurisdiccion` (por defecto CABA).
--  · `origen`: 'auto' lo mantiene la sincronización; 'manual' lo cargó una
--    persona y la sincronización no lo toca nunca.
--  · `ignorado`: figura en el calendario oficial pero la empresa trabaja ese
--    día. Queda la fila para que la sincronización no lo vuelva a agregar.
--
--  Es idempotente: se puede correr muchas veces.
--
--  La usan app/feriados.php (sincronización y consultas), pages/feriados.php
--  y, a través de feriados_del_rango(), la agenda. Sin esta tabla la agenda
--  sigue funcionando sin descontar feriados (feriados_asegurar() ignora el
--  error 42S02 de tabla inexistente).
-- ============================================================

CREATE TABLE IF NOT EXISTS `feriados` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fecha`          DATE         NOT NULL,
  `nombre`         VARCHAR(160) NOT NULL,
  `tipo`           VARCHAR(20)  NOT NULL DEFAULT 'inamovible', -- inamovible | trasladable | puente | no_laborable
  `jurisdiccion`   VARCHAR(20)  NOT NULL DEFAULT 'nacional',
  `origen`         ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  `ignorado`       TINYINT(1)   NOT NULL DEFAULT 0,
  -- Usuario que lo cargó a mano; NULL en los que trae la sincronización.
  -- Sin clave foránea: si se borra el usuario, la fila queda igual.
  `creado_por`     BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Un feriado por día y jurisdicción: dos del mismo día se guardan en una
  -- sola fila con los dos nombres (feriados_parsear()).
  UNIQUE KEY `ux_feriado_dia` (`fecha`, `jurisdiccion`),
  KEY `ix_feriado_vigente` (`ignorado`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes nuevos. No pisan lo que ya esté cargado.
--   · feriados_activo: '0' apaga el descuento de feriados en la agenda;
--   · feriados_sincronizado_en: última sincronización buena (Y-m-d H:i:s);
--   · feriados_intento_en: último intento, bueno o malo (para no reintentar
--     enseguida contra una fuente caída);
--   · feriados_ultimo_error: el motivo del último intento fallido, para la pantalla.
INSERT INTO `app_settings` (`clave`, `valor`) VALUES
    ('feriados_activo',          '1'),
    ('feriados_jurisdiccion',    'CABA'),
    ('feriados_sincronizado_en', ''),
    ('feriados_intento_en',      ''),
    ('feriados_ultimo_error',    '')
ON DUPLICATE KEY UPDATE `clave` = `clave`;

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
-- ============================================================

CREATE TABLE IF NOT EXISTS `feriados` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fecha`          DATE         NOT NULL,
  `nombre`         VARCHAR(160) NOT NULL,
  `tipo`           VARCHAR(20)  NOT NULL DEFAULT 'inamovible', -- inamovible | trasladable | puente | no_laborable
  `jurisdiccion`   VARCHAR(20)  NOT NULL DEFAULT 'nacional',
  `origen`         ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  `ignorado`       TINYINT(1)   NOT NULL DEFAULT 0,
  `creado_por`     BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_feriado_dia` (`fecha`, `jurisdiccion`),
  KEY `ix_feriado_vigente` (`ignorado`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes nuevos. No pisan lo que ya esté cargado.
INSERT INTO `app_settings` (`clave`, `valor`) VALUES
    ('feriados_activo',          '1'),
    ('feriados_jurisdiccion',    'CABA'),
    ('feriados_sincronizado_en', ''),
    ('feriados_intento_en',      ''),
    ('feriados_ultimo_error',    '')
ON DUPLICATE KEY UPDATE `clave` = `clave`;

-- ============================================================
--  Migracion 020 — Ingreso con ITHub (sesion unica de Intellihelp).
--
--  Es idempotente: se puede correr varias veces sin romper nada.
--    python bin/deploy.py migrar app/db/migracion-020-ithub.sql --pruebas
--    python bin/deploy.py migrar app/db/migracion-020-ithub.sql --produccion
--
--  Agrega la tabla donde ithub-logout anota las sesiones de ITHub que se
--  cerraron (aviso por detras, back-channel logout). auth_user() la consulta
--  en cada pedido: una sesion del CRM abierta con ese `sid` se corta. No toca
--  ningun dato existente. schema.sql ya la trae para una instalacion nueva.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ithub_sesiones_cerradas` (
  `sid`        CHAR(32)  NOT NULL,     -- id de la sesion de ITHub (claim `sid`)
  `cerrada_en` DATETIME  NOT NULL,
  PRIMARY KEY (`sid`),
  KEY `idx_ithub_cerrada_en` (`cerrada_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

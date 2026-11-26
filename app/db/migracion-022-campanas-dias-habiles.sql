-- ============================================================
--  Migracion 022 — Campañas sólo en días hábiles.
--
--  Es idempotente: se puede correr varias veces sin romper nada.
--    python bin/deploy.py migrar app/db/migracion-022-campanas-dias-habiles.sql --pruebas
--    python bin/deploy.py migrar app/db/migracion-022-campanas-dias-habiles.sql --produccion
--
--  `solo_habiles` = 1: la campaña no manda los sábados, los domingos ni los
--  feriados (los de la tabla `feriados`, que el cron actualiza solo desde el
--  calendario oficial). Las campañas que ya existen quedan como estaban (0).
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `campanas`
  ADD COLUMN IF NOT EXISTS `solo_habiles` TINYINT(1) NOT NULL DEFAULT 0 AFTER `enviar_hasta`;

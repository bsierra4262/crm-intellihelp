-- ============================================================
--  Aprobar una ficha es una DECISIÓN, no un cálculo.
--
--  Hasta ahora había un solo dato, `pendiente_norm`, que se recalcula en cada
--  guardado a partir de si la ficha tiene todo lo que pedimos. Sirve para decir
--  «le falta el CUIT», pero no alcanza para lo que hace falta ahora: que
--  alguien mire una ficha incompleta y decida que así está bien.
--
--  Si se usara la misma columna para las dos cosas, la aprobación duraría hasta
--  la próxima edición: al guardar se recalcula y la ficha vuelve a pendiente,
--  borrando en silencio una decisión que alguien tomó.
--
--  Por eso son dos:
--    · `pendiente_norm`  le falta algo   (lo calcula el sistema)
--    · `aprobado_en`     alguien la dio por buena igual  (lo decide una persona)
--
--  Una ficha está pendiente cuando le falta algo Y nadie la aprobó.
-- ============================================================

ALTER TABLE `contactos`
  ADD COLUMN IF NOT EXISTS `aprobado_en`  DATETIME        DEFAULT NULL AFTER `pendiente_norm`,
  ADD COLUMN IF NOT EXISTS `aprobado_por` BIGINT UNSIGNED DEFAULT NULL AFTER `aprobado_en`,
  ADD INDEX IF NOT EXISTS `ix_cont_aprob` (`pendiente_norm`, `aprobado_en`, `eliminado_en`);

ALTER TABLE `cuentas`
  ADD COLUMN IF NOT EXISTS `aprobado_en`  DATETIME        DEFAULT NULL AFTER `pendiente_norm`,
  ADD COLUMN IF NOT EXISTS `aprobado_por` BIGINT UNSIGNED DEFAULT NULL AFTER `aprobado_en`,
  ADD INDEX IF NOT EXISTS `ix_cta_aprob` (`pendiente_norm`, `aprobado_en`, `eliminado_en`);

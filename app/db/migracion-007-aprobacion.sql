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
--  En SQL: `pendiente_norm = 1 AND aprobado_en IS NULL` (así filtra crm.php).
--
--  Quién escribe: la acción «Aprobar» de los listados (aprobar_registros() en
--  crm.php) completa `aprobado_en` y `aprobado_por` (id de `usuarios`, sin
--  clave foránea) o los vuelve a NULL.
--
--  Índices: `ix_cont_aprob`/`ix_cta_aprob` empiezan por `pendiente_norm`, así
--  que cubren también lo que hacían `ix_cont_pend`/`ix_cta_pend` de la 006,
--  que quedan redundantes (la migracion 018 los saca).
--
--  ADD COLUMN / ADD INDEX IF NOT EXISTS es sintaxis de MariaDB (en MySQL 8
--  falla). Requiere la 006. schema.sql ya incluye esto: una instalacion nueva no
--  necesita correrla (si se corre igual, no cambia nada).
-- ============================================================

ALTER TABLE `contactos`
  ADD COLUMN IF NOT EXISTS `aprobado_en`  DATETIME        DEFAULT NULL AFTER `pendiente_norm`,
  ADD COLUMN IF NOT EXISTS `aprobado_por` BIGINT UNSIGNED DEFAULT NULL AFTER `aprobado_en`,
  ADD INDEX IF NOT EXISTS `ix_cont_aprob` (`pendiente_norm`, `aprobado_en`, `eliminado_en`);

ALTER TABLE `cuentas`
  ADD COLUMN IF NOT EXISTS `aprobado_en`  DATETIME        DEFAULT NULL AFTER `pendiente_norm`,
  ADD COLUMN IF NOT EXISTS `aprobado_por` BIGINT UNSIGNED DEFAULT NULL AFTER `aprobado_en`,
  ADD INDEX IF NOT EXISTS `ix_cta_aprob` (`pendiente_norm`, `aprobado_en`, `eliminado_en`);

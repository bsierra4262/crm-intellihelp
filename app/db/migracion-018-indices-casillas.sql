-- ============================================================
--  Migracion 018 — Indices que faltaban, tipos alineados y casillas que se
--  pueden volver a cargar despues de darlas de baja.
--
--  Es idempotente: se puede correr varias veces sin romper nada.
--    python bin/deploy.py migrar app/db/migracion-018-indices-casillas.sql --pruebas
--    python bin/deploy.py migrar app/db/migracion-018-indices-casillas.sql --produccion
--
--  Requiere las migraciones 002 a 007 (en produccion ya estan). No borra ni
--  cambia ningun dato: agrega indices, saca dos indices repetidos y ajusta
--  definiciones de columnas sin tocar sus valores.
--
--  Sintaxis de MariaDB (ADD/DROP ... IF EXISTS / IF NOT EXISTS), como las 005
--  a 009: el hosting usa MariaDB. schema.sql ya trae todo esto para una
--  instalacion nueva.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
--  1. Envios por casilla
--
--  La pantalla de Casillas y el despachador cuentan «cuantos salieron hoy por
--  esta casilla» en cada carga. Sin indice por `cuenta_correo_id` eso
--  recorria `envios` entera una vez por casilla. Con (casilla, fecha) la
--  cuenta sale del indice, siempre que la consulta pida un rango de fechas
--  (enviado_en >= CURDATE()) y no DATE(enviado_en) = CURDATE().
-- ------------------------------------------------------------
ALTER TABLE `envios`
  ADD INDEX IF NOT EXISTS `ix_envios_cuenta` (`cuenta_correo_id`, `enviado_en`);

-- ------------------------------------------------------------
--  2. Columnas de la 002 que apuntan a otra tabla y no tenian indice
--
--  Filtrar las listas por segmento y las campanas por casilla o por segmento
--  recorria la tabla entera.
-- ------------------------------------------------------------
ALTER TABLE `listas`
  ADD INDEX IF NOT EXISTS `ix_listas_categoria` (`categoria_id`);

ALTER TABLE `campanas`
  ADD INDEX IF NOT EXISTS `ix_camp_cuenta`    (`cuenta_correo_id`),
  ADD INDEX IF NOT EXISTS `ix_camp_categoria` (`categoria_id`);

-- ------------------------------------------------------------
--  3. Indices repetidos de la 006
--
--  `ix_cont_aprob`/`ix_cta_aprob` (007) empiezan por `pendiente_norm`, asi que
--  cubren todo lo que hacian `ix_cont_pend`/`ix_cta_pend`. Mantener los dos
--  sólo encarece cada alta y cada edicion. En la misma sentencia se asegura
--  el indice que queda: si faltara la 007, la sentencia falla entera y no se
--  saca nada.
-- ------------------------------------------------------------
ALTER TABLE `contactos`
  ADD INDEX IF NOT EXISTS `ix_cont_aprob` (`pendiente_norm`, `aprobado_en`, `eliminado_en`),
  DROP INDEX IF EXISTS `ix_cont_pend`;

ALTER TABLE `cuentas`
  ADD INDEX IF NOT EXISTS `ix_cta_aprob` (`pendiente_norm`, `aprobado_en`, `eliminado_en`),
  DROP INDEX IF EXISTS `ix_cta_pend`;

-- ------------------------------------------------------------
--  4. mensajes.cuenta_correo_id con el mismo tipo que cuentas_correo.id
--
--  Era BIGINT y la columna a la que apunta es INT: no rompia nada, pero
--  impedia declarar la clave foranea el dia que se quiera. Los ids de
--  casillas son numeros chicos, asi que el cambio no altera ningun valor.
-- ------------------------------------------------------------
ALTER TABLE `mensajes`
  MODIFY COLUMN `cuenta_correo_id` INT UNSIGNED DEFAULT NULL;

-- ------------------------------------------------------------
--  5. Casillas
--
--  a) `smtp_pass` era NOT NULL sin valor por defecto y el alta de la casilla
--     la graba en un UPDATE aparte: con la base en modo estricto el alta
--     fallaba. Con DEFAULT '' el alta funciona en cualquier modo.
--  b) Una casilla dada de baja (borrado logico) seguia ocupando su direccion
--     por `uq_cuenta_remitente`, y volver a cargarla chocaba con la clave.
--     Ahora la unicidad vale sólo entre las casillas vivas: `remitente_vivo`
--     es una columna calculada (no ocupa lugar ni se escribe) que vale el
--     remitente mientras la casilla no esta dada de baja, y NULL despues; los
--     NULL no chocan en un UNIQUE. Como hoy la direccion ya es unica en toda
--     la tabla, el indice nuevo no puede encontrar repetidos.
-- ------------------------------------------------------------
ALTER TABLE `cuentas_correo`
  MODIFY COLUMN `smtp_pass` VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE `cuentas_correo`
  ADD COLUMN IF NOT EXISTS `remitente_vivo` VARCHAR(190)
      GENERATED ALWAYS AS (IF(`eliminado_en` IS NULL, `remitente`, NULL)) VIRTUAL,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_cuenta_remitente_viva` (`remitente_vivo`),
  DROP INDEX IF EXISTS `uq_cuenta_remitente`;

-- ============================================================
--  Normalización: qué ficha está completa y cuál falta terminar.
--
--  Una base comercial sirve en la medida en que se pueda usar. Un contacto sin
--  apellido no se puede tratar por su nombre en una campaña; uno sin empresa no
--  se puede agrupar ni asignar; una empresa sin CUIT no se puede facturar. Esos
--  datos igual entran —lo importante es no perder al que llegó—, pero tienen
--  que quedar señalados para poder completarlos después, en vez de descubrirlo
--  el día que hace falta.
--
--  Por eso la ficha no se rechaza: se marca. `pendiente_norm` en 1 significa
--  «entró, pero le falta algo de lo que pedimos siempre».
--
--  Se guarda como columna y no se calcula al vuelo porque hay que poder filtrar
--  por esto sobre miles de filas, y una condición armada con seis OR sobre
--  campos sin índice recorre la tabla entera en cada consulta.
-- ============================================================

ALTER TABLE `contactos`
  ADD COLUMN IF NOT EXISTS `pendiente_norm` TINYINT(1) NOT NULL DEFAULT 0 AFTER `email_rebotado`,
  ADD INDEX IF NOT EXISTS `ix_cont_pend` (`pendiente_norm`, `eliminado_en`);

ALTER TABLE `cuentas`
  ADD COLUMN IF NOT EXISTS `pendiente_norm` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sector`,
  ADD INDEX IF NOT EXISTS `ix_cta_pend` (`pendiente_norm`, `eliminado_en`);

-- Lo que ya estaba cargado se clasifica con la misma regla, para que el filtro
-- diga la verdad desde el primer día y no sólo sobre lo que se cargue de ahora
-- en más.
UPDATE `contactos` SET `pendiente_norm` = 1
 WHERE `nombre`   IS NULL OR TRIM(`nombre`)   = ''
    OR `apellido` IS NULL OR TRIM(`apellido`) = ''
    OR `email`    IS NULL OR TRIM(`email`)    = ''
    OR `cuenta_id` IS NULL;

UPDATE `cuentas` SET `pendiente_norm` = 1
 WHERE `nombre` IS NULL OR TRIM(`nombre`) = ''
    OR `cuit`   IS NULL OR TRIM(`cuit`)   = ''
    OR `tipo`   IS NULL OR TRIM(`tipo`)   = ''
    OR `sector` IS NULL OR TRIM(`sector`) = '';

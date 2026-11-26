-- ============================================================
--  Migracion 021 — Plantillas en texto plano.
--
--  Es idempotente: se puede correr varias veces sin romper nada.
--    python bin/deploy.py migrar app/db/migracion-021-plantillas-texto.sql --pruebas
--    python bin/deploy.py migrar app/db/migracion-021-plantillas-texto.sql --produccion
--
--  Agrega el tipo 'texto' a `plantillas.tipo`: un correo sin diseño, que sale
--  sólo como text/plain (sin HTML ni píxel de apertura), igual que los que
--  mandaba Pulsemail. El cuerpo vive en `cuerpo_texto`; `cuerpo_html` queda
--  vacío. No toca ninguna plantilla existente.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `plantillas`
  MODIFY `tipo` ENUM('visual','html','texto') NOT NULL DEFAULT 'visual';

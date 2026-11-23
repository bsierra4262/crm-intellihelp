-- ============================================================
--  Migracion 019 — Claves foraneas que faltaban, idempotencia de la agenda
--  publica, una columna sin uso y las contraseñas viejas en texto plano.
--
--  Es idempotente: se puede correr varias veces sin romper nada.
--    python bin/deploy.py migrar app/db/migracion-019-integridad.sql --pruebas
--    python bin/deploy.py migrar app/db/migracion-019-integridad.sql --produccion
--
--  Requiere las migraciones 002 a 018. Sintaxis de MariaDB (IF EXISTS /
--  IF NOT EXISTS), como las 005 a 018. schema.sql ya trae todo esto para una
--  instalacion nueva.
--
--  Que datos toca (revisado en las dos bases el 23/11/2026: no habia ninguna
--  referencia rota, ninguna idempotencia repetida, y la contraseña vieja era
--  la misma que la de la casilla):
--    · una referencia a un contacto, posible cliente, envio o campaña que ya
--      no existe se pone en NULL (no se borra la fila: el correo, el nombre
--      y la empresa quedan en ella);
--    · se borran los ajustes `smtp_pass` / `imap_pass` SOLO si hay una casilla
--      activa con contraseña (que es la que se usa);
--    · se saca la columna `cuentas_correo.imap_ultimo_uid`, que nunca se uso
--      (siempre valia 0).
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
--  1. Referencias rotas -> NULL
--
--  La purga de la papelera borraba contactos y posibles clientes y dejaba en
--  `envios`, `lista_miembros` y `mensajes` el id de un registro que ya no
--  existe. Antes de declarar las claves hay que dejarlas en NULL (si no, el
--  ALTER falla). Nada se pierde: esas filas guardan su propio correo.
-- ------------------------------------------------------------
UPDATE `envios` e LEFT JOIN `contactos` c ON c.id = e.contacto_id
   SET e.contacto_id = NULL WHERE e.contacto_id IS NOT NULL AND c.id IS NULL;
UPDATE `envios` e LEFT JOIN `posibles_clientes` p ON p.id = e.posible_cliente_id
   SET e.posible_cliente_id = NULL WHERE e.posible_cliente_id IS NOT NULL AND p.id IS NULL;
UPDATE `lista_miembros` m LEFT JOIN `contactos` c ON c.id = m.contacto_id
   SET m.contacto_id = NULL WHERE m.contacto_id IS NOT NULL AND c.id IS NULL;
UPDATE `lista_miembros` m LEFT JOIN `posibles_clientes` p ON p.id = m.posible_cliente_id
   SET m.posible_cliente_id = NULL WHERE m.posible_cliente_id IS NOT NULL AND p.id IS NULL;
UPDATE `mensajes` m LEFT JOIN `contactos` c ON c.id = m.contacto_id
   SET m.contacto_id = NULL WHERE m.contacto_id IS NOT NULL AND c.id IS NULL;
UPDATE `mensajes` m LEFT JOIN `envios` e ON e.id = m.envio_id
   SET m.envio_id = NULL WHERE m.envio_id IS NOT NULL AND e.id IS NULL;
UPDATE `mensajes` m LEFT JOIN `campanas` k ON k.id = m.campana_id
   SET m.campana_id = NULL WHERE m.campana_id IS NOT NULL AND k.id IS NULL;

-- ------------------------------------------------------------
--  2. Claves foraneas
--
--  Desde ahora, cuando la purga borra un contacto o un posible cliente:
--    · `envios` y `mensajes` quedan con la referencia en NULL: son el
--      historial de la campaña y de la conversacion, y guardan el correo;
--    · el miembro de una lista que apuntaba a ese contacto se borra con el
--      (CASCADE): un contacto eliminado no tiene que seguir recibiendo
--      campañas por estar en una lista. Los miembros cargados sólo con un
--      correo (sin contacto) no se tocan.
--  Purgar una campaña ya borraba sus envios (fk_envios_campana); ahora los
--  mensajes de esa campaña quedan, sin la referencia.
-- ------------------------------------------------------------
ALTER TABLE `envios`
  ADD CONSTRAINT `fk_envios_contacto` FOREIGN KEY IF NOT EXISTS (`contacto_id`)
      REFERENCES `contactos` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_envios_pc` FOREIGN KEY IF NOT EXISTS (`posible_cliente_id`)
      REFERENCES `posibles_clientes` (`id`) ON DELETE SET NULL;

ALTER TABLE `lista_miembros`
  ADD CONSTRAINT `fk_lm_contacto` FOREIGN KEY IF NOT EXISTS (`contacto_id`)
      REFERENCES `contactos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lm_pc` FOREIGN KEY IF NOT EXISTS (`posible_cliente_id`)
      REFERENCES `posibles_clientes` (`id`) ON DELETE CASCADE;

ALTER TABLE `mensajes`
  ADD CONSTRAINT `fk_msg_contacto` FOREIGN KEY IF NOT EXISTS (`contacto_id`)
      REFERENCES `contactos` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_msg_envio` FOREIGN KEY IF NOT EXISTS (`envio_id`)
      REFERENCES `envios` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_msg_campana` FOREIGN KEY IF NOT EXISTS (`campana_id`)
      REFERENCES `campanas` (`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------
--  3. Idempotencia de la agenda publica
--
--  En un UNIQUE los NULL no chocan entre si: `uq_ew_idem (token_id,
--  idempotencia)` no frenaba dos entradas repetidas de la agenda publica
--  (`token_id` NULL). `token_clave` es una columna calculada (no ocupa lugar
--  ni se escribe) que vale el token o 0, y la clave nueva la usa. Si hubiera
--  repetidos, la sentencia falla entera y queda la clave vieja.
-- ------------------------------------------------------------
ALTER TABLE `entradas_web`
  ADD COLUMN IF NOT EXISTS `token_clave` BIGINT UNSIGNED
      GENERATED ALWAYS AS (COALESCE(`token_id`, 0)) VIRTUAL,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_ew_idem_clave` (`token_clave`, `idempotencia`),
  DROP INDEX IF EXISTS `uq_ew_idem`;

-- ------------------------------------------------------------
--  4. Columna sin uso
--
--  La marca de lectura de cada casilla vive en `app_settings`
--  ('INBOX:<usuario>', imap.php); `imap_ultimo_uid` nunca se escribio.
-- ------------------------------------------------------------
ALTER TABLE `cuentas_correo`
  DROP COLUMN IF EXISTS `imap_ultimo_uid`;

-- ------------------------------------------------------------
--  5. Contraseñas de la configuracion vieja
--
--  Antes de las casillas multiples, la contraseña de la casilla se guardaba
--  en `app_settings` en texto plano. Las casillas actuales la guardan
--  cifrada y son las que se usan. Se borra la copia vieja sólo si hay una
--  casilla activa con contraseña: en una instalacion que todavia no cargo
--  casillas, los ajustes viejos siguen siendo lo unico que tiene.
-- ------------------------------------------------------------
DELETE FROM `app_settings`
 WHERE `clave` IN ('smtp_pass', 'imap_pass')
   AND EXISTS (SELECT 1 FROM `cuentas_correo`
                WHERE `eliminado_en` IS NULL AND `activo` = 1 AND `smtp_pass` <> '');

-- ============================================================
--  Campañas: tokens de baja que sobreviven a la purga, IP con hash y
--  lista blanca del modo prueba.
--
--  · `bajas_tokens`: purgar_papelera() (campanas.php) copia acá los tokens de
--    la cola de una campaña ANTES de borrarla (la cola se va con ella por el
--    ON DELETE CASCADE de `envios`). pages/baja.php busca el token primero en
--    `envios` y, si no está, acá (baja_buscar_token): el enlace de baja de un
--    correo ya entregado tiene que funcionar siempre (obligación legal).
--    Sin clave foránea a propósito: la campaña ya no existe cuando se usa.
--    Sin esta tabla el código sigue andando: la búsqueda ignora el error y la
--    purga deja las campañas en la papelera en vez de borrar sus tokens.
--  · `envio_eventos.ip` y `desuscripciones.ip` pasan de 45 a 64 caracteres:
--    ahí se guarda ahora ip_hash() (HMAC-SHA256 en hexadecimal, 64), no la IP
--    en claro. Las IP viejas ya guardadas no se tocan (no se pueden hashear
--    desde SQL sin el secreto de la instalación). Agrandar una columna no
--    pierde datos. Hasta que esto se aplique, el código guarda la IP de la
--    baja en un paso aparte que puede fallar sin afectar la baja.
--  · Ajuste `testing_lista_blanca`: correos (separados por coma) a los que
--    se puede mandar en modo prueba, además de los usuarios activos. No pisa
--    lo que ya esté cargado. En producción (`app_entorno` = 'produccion') no
--    se usa.
--
--  Es idempotente: se puede correr muchas veces.
-- ============================================================

CREATE TABLE IF NOT EXISTS `bajas_tokens` (
  `token`      CHAR(32)        NOT NULL,
  `email`      VARCHAR(190)    NOT NULL,
  `campana_id` BIGINT UNSIGNED DEFAULT NULL,
  `creado_en`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token`),
  KEY `ix_bt_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `envio_eventos`   MODIFY `ip` VARCHAR(64) DEFAULT NULL;
ALTER TABLE `desuscripciones` MODIFY `ip` VARCHAR(64) DEFAULT NULL;

INSERT INTO `app_settings` (`clave`, `valor`) VALUES
    ('testing_lista_blanca', '')
ON DUPLICATE KEY UPDATE `clave` = `clave`;

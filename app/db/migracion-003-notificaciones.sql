-- ============================================================
--  Alertas del sistema
--
--  Sin esto, las cosas importantes pasaban sin que nadie se enterara: un
--  cliente respondía una campaña, alguien agendaba una reunión, un contacto
--  se archivaba por rebote definitivo... y todo quedaba adentro de una
--  pantalla que había que ir a mirar a propósito.
--
--  Es idempotente: se puede correr muchas veces.
--
--  Las filas las crea notificar() desde el resto del sistema (respuesta a una
--  campana, reunion agendada, rebote, fin de campana); la campana de la barra
--  superior las muestra y las marca leidas, y el cron (paso «Alertas por
--  correo», notif_enviar_pendientes()) manda por correo las que tienen
--  `email_en` vacio, agrupadas en un solo mensaje por corrida.
--  No esta en schema.sql: una instalacion nueva la necesita igual.
-- ============================================================

CREATE TABLE IF NOT EXISTS notificaciones (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id   BIGINT UNSIGNED NULL,        -- NULL = para todo el equipo
    tipo         VARCHAR(40)  NOT NULL,       -- respuesta | reunion | rebote | campana | sistema
    titulo       VARCHAR(200) NOT NULL,
    detalle      VARCHAR(500) NULL,
    entidad      VARCHAR(40)  NULL,           -- adónde lleva el clic
    entidad_id   BIGINT UNSIGNED NULL,
    url          VARCHAR(255) NULL,
    -- Se separa «visto en pantalla» de «avisado por correo»: son dos cosas
    -- distintas y el cron necesita saber cuáles todavía no mandó.
    leida_en     DATETIME NULL,
    email_en     DATETIME NULL,
    creada_en    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- «Mis alertas sin leer, las mas nuevas primero» (la campana de la barra).
    -- Las de todo el equipo (usuario_id NULL) tambien entran por este indice.
    KEY idx_notif_usuario (usuario_id, leida_en, id),
    -- «Las que el cron todavia no mando por correo»: email_en IS NULL.
    -- idx_notif_creada sirve para limpiar o listar por fecha.
    KEY idx_notif_pendiente_email (email_en, id),
    KEY idx_notif_creada (creada_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué avisar por correo. Por defecto se avisa lo que mueve la aguja comercial
-- —una respuesta y una reunión— y no el ruido operativo.
-- `avisar_email_a`: a quien se manda el correo de alertas, separados por
-- coma (vacio = a todos los administradores activos). ON DUPLICATE KEY
-- UPDATE clave = clave no pisa nada de lo que ya este cargado.
INSERT INTO app_settings (clave, valor) VALUES
    ('avisar_respuesta',  '1'),
    ('avisar_reunion',    '1'),
    ('avisar_rebote',     '0'),
    ('avisar_campana',    '1'),
    ('avisar_email_a',    '')
ON DUPLICATE KEY UPDATE clave = clave;

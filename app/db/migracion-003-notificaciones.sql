-- ============================================================
--  Alertas del sistema
--
--  Sin esto, las cosas importantes pasaban sin que nadie se enterara: un
--  cliente respondía una campaña, alguien agendaba una reunión, un contacto
--  se archivaba por rebote definitivo... y todo quedaba adentro de una
--  pantalla que había que ir a mirar a propósito.
--
--  Es idempotente: se puede correr muchas veces.
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
    KEY idx_notif_usuario (usuario_id, leida_en, id),
    KEY idx_notif_pendiente_email (email_en, id),
    KEY idx_notif_creada (creada_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué avisar por correo. Por defecto se avisa lo que mueve la aguja comercial
-- —una respuesta y una reunión— y no el ruido operativo.
INSERT INTO app_settings (clave, valor) VALUES
    ('avisar_respuesta',  '1'),
    ('avisar_reunion',    '1'),
    ('avisar_rebote',     '0'),
    ('avisar_campana',    '1'),
    ('avisar_email_a',    '')
ON DUPLICATE KEY UPDATE clave = clave;

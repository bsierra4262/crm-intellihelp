-- ============================================================
--  Alertas del equipo: leídas POR PERSONA.
--
--  Una alerta sin `usuario_id` es para todo el equipo (una respuesta a una
--  campaña sin dueño, por ejemplo). Hasta ahora `notificaciones.leida_en` era
--  una sola columna: la primera persona que la marcaba como leída —o que
--  apretaba «Marcar todo como leído»— la hacía desaparecer para todos, y una
--  respuesta de un cliente podía quedar sin que nadie más se enterara.
--
--  Desde esta migración:
--    · las alertas PERSONALES (con usuario_id) siguen usando `leida_en`;
--    · las del EQUIPO se marcan en `notificacion_lecturas`, una fila por
--      alerta y persona. `leida_en` de esas alertas deja de usarse.
--
--  Sin esta tabla el código sigue funcionando como antes (notificaciones.php
--  detecta que falta y vuelve a la columna única), así que el código se puede
--  subir antes que la migración.
--
--  Es idempotente y segura con lo que ya hay: crea la tabla si falta y copia
--  como «leídas por todos» las alertas del equipo que hoy ya figuran leídas,
--  para que no reaparezcan como nuevas el día que se aplica. INSERT IGNORE
--  sobre la clave primaria: correrla dos veces no duplica nada. No borra ni
--  cambia ninguna fila de `notificaciones`.
--
--  La usan notif_de(), notif_sin_leer() y notif_marcar_leidas()
--  (notificaciones.php) y datos_prueba_borrar_alertas() (datos_prueba.php).
-- ============================================================

CREATE TABLE IF NOT EXISTS `notificacion_lecturas` (
  `notificacion_id` BIGINT UNSIGNED NOT NULL,
  `usuario_id`      BIGINT UNSIGNED NOT NULL,
  `leida_en`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notificacion_id`, `usuario_id`),
  -- «Lo que ya leyó esta persona» (para contar las sin leer de la campana).
  KEY `ix_lectura_usuario` (`usuario_id`, `notificacion_id`)
  -- Sin claves foráneas, como el resto de las tablas de alertas: si se borra
  -- una alerta o un usuario, la fila de lectura sobra pero no molesta.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lo que ya estaba leído (para todos, por la columna única) queda leído para
-- cada usuario, con la misma fecha.
INSERT IGNORE INTO `notificacion_lecturas` (`notificacion_id`, `usuario_id`, `leida_en`)
SELECT n.`id`, u.`id`, n.`leida_en`
FROM `notificaciones` n
CROSS JOIN `usuarios` u
WHERE n.`usuario_id` IS NULL AND n.`leida_en` IS NOT NULL;

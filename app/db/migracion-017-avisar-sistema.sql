-- ============================================================
--  Avisos por correo de «Problemas del sistema»: volver a encenderlos.
--
--  Hasta esta versión, cada vez que se guardaba la pantalla de Ajustes el
--  ajuste `avisar_sistema` quedaba en '0': la página ponía en cero todo
--  interruptor de aviso que no llegaba en el formulario, y la vista nunca
--  dibujó el de 'sistema'. Como no hay ninguna pantalla donde apagarlo a
--  propósito, un '0' guardado sólo puede venir de ese error. Se vuelve al
--  valor por defecto ('1'): que lleguen por correo los avisos de Google
--  Calendar sin responder o de una casilla que rechaza la contraseña.
--
--  Las alertas de sistema que se acumularon sin mandarse mientras estuvo
--  apagado (más de un día de antigüedad) se marcan como ya avisadas por
--  correo, para que el primer envío después de esto no sea un correo con
--  decenas de avisos viejos. No se borra nada: siguen en la campana y en la
--  bandeja de alertas, y `leida_en` no se toca.
--
--  Es idempotente: se puede correr muchas veces (la segunda vez no encuentra
--  nada que cambiar, salvo alertas de sistema viejas que hayan quedado sin
--  mandar, que es justo lo que se quiere marcar).
--
--  Si el código llega antes que esta migración no pasa nada: el código nuevo
--  ya no apaga el ajuste al guardar, sólo falta reponer el valor.
-- ============================================================

UPDATE `app_settings` SET `valor` = '1'
 WHERE `clave` = 'avisar_sistema' AND `valor` = '0';

UPDATE `notificaciones` SET `email_en` = NOW()
 WHERE `tipo` = 'sistema' AND `email_en` IS NULL
   AND `creada_en` < NOW() - INTERVAL 1 DAY;

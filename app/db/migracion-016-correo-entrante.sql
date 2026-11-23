-- ============================================================
--  Correo entrante y cuentas de correo: arreglos de la auditoría (lote D2).
--
--  1. `imap_estado.uidvalidity`: el UIDVALIDITY que anunció el servidor la
--     última vez que se abrió la casilla. Si el servidor renumera la casilla
--     (cambia el UIDVALIDITY), la marca `ultimo_uid` deja de significar algo
--     y imap.php vuelve a leer la ventana inicial en vez de quedarse sin leer
--     nada para siempre. Arranca en NULL: la primera lectura lo completa sin
--     reiniciar nada.
--  2. Índices de `mensajes` por `de` y por `para` (con la fecha): la
--     conversación se arma por dirección y, sin ellos, cada apertura
--     recorría la tabla entera.
--  3. `cuentas_correo.smtp_pass` con valor por defecto '': el alta de una
--     cuenta sin contraseña fallaba con MySQL en modo estricto (columna NOT
--     NULL sin valor por defecto). El código ya la manda siempre en el
--     INSERT; esto es para cualquier otro camino.
--
--  Es idempotente (IF NOT EXISTS; el MODIFY deja la columna igual si ya
--  estaba así) y no toca datos: sólo agrega una columna vacía, dos índices
--  y un valor por defecto.
--
--  El código funciona también sin esta migración: sin la columna
--  `uidvalidity` simplemente no detecta la renumeración (como hasta ahora).
-- ============================================================

ALTER TABLE `imap_estado`
  ADD COLUMN IF NOT EXISTS `uidvalidity` BIGINT UNSIGNED DEFAULT NULL AFTER `ultimo_uid`;

CREATE INDEX IF NOT EXISTS `ix_msg_de`   ON `mensajes` (`de`, `fecha`);
CREATE INDEX IF NOT EXISTS `ix_msg_para` ON `mensajes` (`para`, `fecha`);

ALTER TABLE `cuentas_correo`
  MODIFY `smtp_pass` VARCHAR(255) NOT NULL DEFAULT '';

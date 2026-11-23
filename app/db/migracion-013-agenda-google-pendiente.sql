-- ============================================================
--  Agenda: lo que quedó pendiente en Google al mover o cancelar.
--
--  Mover y cancelar una reunión escriben primero en la base y recién después
--  tocan el evento de Google Calendar. Si Google falla (o el proceso se corta
--  en el medio), la reserva queda marcada en `gcal_pendiente` y el cron lo
--  reintenta (agenda_sincronizar_google() en agenda.php):
--    · 'mover'  → el evento todavía tiene el horario viejo;
--    · 'borrar' → la reunión está cancelada y el evento sigue en el calendario.
--  NULL = nada pendiente (todas las filas que ya existen).
--
--  Es idempotente (ADD COLUMN / ADD INDEX IF NOT EXISTS, de MariaDB) y no
--  toca ningún dato: la columna nueva arranca vacía.
--
--  El código funciona sin esta migración: sin la columna no marca nada y
--  queda sólo el registro del error en la auditoría, como antes.
-- ============================================================

ALTER TABLE `agenda_reservas`
  ADD COLUMN IF NOT EXISTS `gcal_pendiente` VARCHAR(10) DEFAULT NULL AFTER `meet_url`,
  ADD INDEX IF NOT EXISTS `ix_ar_gcal_pendiente` (`gcal_pendiente`);

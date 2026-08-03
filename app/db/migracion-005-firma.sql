-- ============================================================
--  Firma de la casilla de correo.
--
--  Cuando se contesta desde el CRM, el correo salía pelado: sólo el texto
--  escrito. Desde Gmail, en cambio, cada casilla manda su firma —el nombre, el
--  cargo, el teléfono, la web— y quien lo recibe ve siempre lo mismo. Contestar
--  sin firma se nota, y se nota justo cuando alguien está evaluando si trabajar
--  con nosotros.
--
--  La firma es por casilla y no por usuario, porque es la casilla la que
--  representa a un área: lo que sale de la cuenta de RRHH firma como RRHH.
-- ============================================================

ALTER TABLE `cuentas_correo`
  ADD COLUMN IF NOT EXISTS `firma_html` TEXT DEFAULT NULL AFTER `remitente_nombre`;

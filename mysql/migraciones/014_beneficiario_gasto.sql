-- ===========================================================================
-- 014_beneficiario_gasto.sql — «Pagado a» en los gastos
--   gastos.beneficiario_tipo   proveedor | estacion (gasolinera) | otro
-- Los gastos con proveedor del directorio pasan a 'proveedor'; el resto queda como 'otro'.
-- No toca comprobante_fiscal ni revision_fiscal: un adjunto nunca vuelve deducible un gasto.
-- ===========================================================================
ALTER TABLE gastos
  ADD COLUMN beneficiario_tipo ENUM('proveedor','estacion','otro') NOT NULL DEFAULT 'otro'
      COMMENT 'A quién se pagó: proveedor del directorio, estación de servicio u otro (descripción libre)' AFTER proveedor_id;

UPDATE gastos SET beneficiario_tipo = 'proveedor' WHERE proveedor_id IS NOT NULL;

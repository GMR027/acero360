-- ===========================================================================
-- 027_categorias_adicionales.sql — tres tipos de gasto que antes caían en «Otros»
--
--   Gastos bancarios y comisiones  comisión, intereses, cuota anual de tarjeta
--   Flete y maniobras              flete, maniobra, acarreo de mercancía
--   Seguridad y capacitación       EPP (casco, guantes) y cursos del personal
--
-- Se insertan sólo las que no existan (nombre único), para poder re-ejecutarla.
-- ===========================================================================

INSERT IGNORE INTO categorias_gasto (nombre, activa) VALUES
  ('Gastos bancarios y comisiones', 1),
  ('Flete y maniobras', 1),
  ('Seguridad y capacitación', 1);

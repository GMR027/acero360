-- ===========================================================================
-- 016_logo_pdf.sql — tamaño del logotipo en los PDF, por empresa
--   empresas.logo_alto_pdf   alto máximo (px) del logotipo en el encabezado de cotizaciones y ventas;
--                            en los listados (catálogo, inventario) se usa el 70 %. El ancho se ajusta solo (contain).
-- ===========================================================================
ALTER TABLE empresas
  ADD COLUMN logo_alto_pdf SMALLINT UNSIGNED NOT NULL DEFAULT 64 COMMENT 'Alto máximo del logo en PDF (px)';

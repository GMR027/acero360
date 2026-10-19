-- Reversión de 007 (borra catálogo, configuración fiscal, retenciones, ajustes, declaraciones, cálculos e historial de cierres).
DROP TABLE periodos_historial, calculos_fiscales, declaraciones, ajustes_fiscales, retenciones, configuracion_fiscal, catalogo_fiscal;
DELETE FROM schema_migraciones WHERE nombre = '007_fiscal.sql';

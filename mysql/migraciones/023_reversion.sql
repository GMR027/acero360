-- Reversión de 023_imagen_catalogo_proveedor.sql (pierde la referencia a las imágenes; los archivos quedan en disco).
ALTER TABLE proveedor_productos DROP COLUMN imagen;
DELETE FROM schema_migraciones WHERE nombre = '023_imagen_catalogo_proveedor.sql';

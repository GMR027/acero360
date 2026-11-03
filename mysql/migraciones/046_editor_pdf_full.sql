-- Imagen de alta resolución de cada lámina para exportar el PDF.
-- marcas_png sigue siendo solo la miniatura del listado y de la vista de lectura.
ALTER TABLE editor_laminas ADD COLUMN marcas_full VARCHAR(100) DEFAULT NULL AFTER marcas_png;

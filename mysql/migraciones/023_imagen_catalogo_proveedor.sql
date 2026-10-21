-- ===========================================================================
-- 023_imagen_catalogo_proveedor.sql — imagen del artículo del catálogo del proveedor
--   proveedor_productos.imagen   nombre del archivo en public/image (NULL = sin imagen). Se muestra en la recepción de compras.
-- ===========================================================================
ALTER TABLE proveedor_productos
  ADD COLUMN imagen VARCHAR(255) DEFAULT NULL COMMENT 'Archivo de la imagen del artículo (public/image)' AFTER descripcion;

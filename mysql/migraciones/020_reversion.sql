-- Reversión de 020_semiterminados_proveedor.sql (pierde el catálogo y sus relaciones; respaldar antes).
DROP TABLE IF EXISTS proveedor_producto_relaciones;
DROP TABLE IF EXISTS proveedor_productos;
DELETE FROM schema_migraciones WHERE nombre = '020_semiterminados_proveedor.sql';

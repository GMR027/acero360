-- Reversión de 053_catalogo_web.sql (las portadas subidas quedan en public/image sin uso).
ALTER TABLE categorias_producto
    DROP INDEX uq_categorias_producto_slug,
    DROP COLUMN slug, DROP COLUMN descripcion, DROP COLUMN imagen, DROP COLUMN orden;
ALTER TABLE productos DROP COLUMN publicado_web;
DELETE FROM schema_migraciones WHERE nombre = '053_catalogo_web.sql';

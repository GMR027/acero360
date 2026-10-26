-- Reversión de 040_entrega_fotos.sql — quita la tabla de fotos de las entregas.
-- (Los archivos ya subidos a public/image no se tocan desde SQL.)
DROP TABLE IF EXISTS entrega_fotos;

-- Quita el registro para que `scripts/migrar.php` vuelva a aplicar la migración (patrón de 001-039).
DELETE FROM schema_migraciones WHERE nombre = '040_entrega_fotos.sql';

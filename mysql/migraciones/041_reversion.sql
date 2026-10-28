-- Reversión de 041_entrega_fotos_metadatos.sql — quita los metadatos EXIF
-- guardados de las fotos de entrega (los archivos no se tocan).
ALTER TABLE entrega_fotos
    DROP COLUMN tomada_en,
    DROP COLUMN latitud,
    DROP COLUMN longitud;

DELETE FROM schema_migraciones WHERE nombre = '041_entrega_fotos_metadatos.sql';

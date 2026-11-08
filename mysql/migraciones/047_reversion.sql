-- Reversión de 047_entrega_ubicacion.sql — quita la ubicación manual de la entrega.
ALTER TABLE entregas
    DROP COLUMN ubicacion_maps;

DELETE FROM schema_migraciones WHERE nombre = '047_entrega_ubicacion.sql';

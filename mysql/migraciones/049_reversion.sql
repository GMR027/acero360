-- Reversión de 049_entrega_ubicacion_coordenadas.sql — quita las coordenadas
-- normalizadas de la ubicación manual de las entregas.
ALTER TABLE entregas
    DROP COLUMN ubicacion_lon,
    DROP COLUMN ubicacion_lat;

DELETE FROM schema_migraciones WHERE nombre = '049_entrega_ubicacion_coordenadas.sql';

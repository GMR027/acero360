-- ===========================================================================
-- 049_entrega_ubicacion_coordenadas.sql — Coordenadas de la ubicación manual
--
-- `entregas.ubicacion_maps` guarda el enlace de Google Maps, las coordenadas o
-- la dirección que captura el usuario cuando las fotos no traen GPS. Para que
-- el mapa de ventas pueda ubicar esas entregas sin leer y parsear el texto en
-- cada consulta, aquí se guardan las coordenadas ya normalizadas.
--
-- Ambas columnas son NULAS: las entregas anteriores y las ubicaciones que son
-- sólo una dirección (sin coordenadas) siguen funcionando; el mapa las cuenta
-- como «sin ubicación».
-- ===========================================================================

ALTER TABLE entregas
    ADD COLUMN ubicacion_lat DECIMAL(10,7) DEFAULT NULL
        COMMENT 'Latitud de la ubicación manual de la entrega' AFTER ubicacion_maps,
    ADD COLUMN ubicacion_lon DECIMAL(10,7) DEFAULT NULL
        COMMENT 'Longitud de la ubicación manual de la entrega' AFTER ubicacion_lat;

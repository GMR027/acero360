-- ===========================================================================
-- 047_entrega_ubicacion.sql — Ubicación manual de una entrega
--
-- Si las fotos del acta no traen GPS en sus metadatos, el usuario puede pegar
-- aquí el enlace de Google Maps, unas coordenadas o una dirección. La columna
-- es NULA para no cambiar las entregas anteriores.
--
-- Prioridad al mostrar el mapa y la marca de agua:
--   1. GPS EXIF de la foto.
--   2. esta ubicación manual de la entrega.
--   3. la dirección registrada del cliente.
-- ===========================================================================

ALTER TABLE entregas
    ADD COLUMN ubicacion_maps VARCHAR(500) DEFAULT NULL
        COMMENT 'Enlace de Google Maps, coordenadas o dirección capturada a mano'
        AFTER notas;

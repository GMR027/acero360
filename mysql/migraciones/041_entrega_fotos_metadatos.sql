-- ===========================================================================
-- 041_entrega_fotos_metadatos.sql — Fecha de captura y GPS de las fotos del acta
--
-- Al guardar una foto de entrega, Intervention Image la re-codifica (reduce a
-- 1600 px) y con eso se pierden los metadatos EXIF del archivo original. Para
-- que la marca de agua pueda mostrar la fecha, la hora y la ubicación reales
-- de la toma, se leen ANTES de procesarla y se guardan aquí.
--
--   · tomada_en  fecha y hora de captura (EXIF DateTimeOriginal / DateTime)
--   · latitud    latitud en grados decimales (EXIF GPS), negativa al sur
--   · longitud   longitud en grados decimales (EXIF GPS), negativa al oeste
--
-- Las tres columnas son NULAS: las fotos anteriores y las que no traigan EXIF
-- siguen funcionando; la vista usa como respaldo la fecha/hora de registro de
-- la entrega y la ubicación del cliente.
-- ===========================================================================

ALTER TABLE entrega_fotos
    ADD COLUMN tomada_en DATETIME     DEFAULT NULL COMMENT 'Fecha y hora de captura (EXIF)' AFTER archivo,
    ADD COLUMN latitud   DECIMAL(10,7) DEFAULT NULL COMMENT 'Latitud GPS en grados decimales (EXIF)' AFTER tomada_en,
    ADD COLUMN longitud  DECIMAL(10,7) DEFAULT NULL COMMENT 'Longitud GPS en grados decimales (EXIF)' AFTER latitud;

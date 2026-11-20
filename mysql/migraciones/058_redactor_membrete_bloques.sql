-- ===========================================================================
-- 058_redactor_membrete_bloques.sql — Membrete por bloques del Redactor
--
-- Guarda en JSON el diseño del encabezado, del pie y de la marca de agua:
--   • Encabezado y pie: logo (con opacidad y tamaño), título y subencabezado,
--     con disposición (logo a la izquierda/arriba/derecha) y alineación.
--   • Pie: numeración de páginas con su posición.
--   • Marca de agua: texto, color, tamaño, opacidad y ángulo, centrada.
--
-- Las columnas antiguas (encabezado_texto, pie_texto, imagen_encabezado…)
-- se conservan para poder leer documentos creados antes de esta migración;
-- el modelo las convierte en el JSON cuando el documento aún no lo tiene.
-- ===========================================================================

ALTER TABLE redactor_documentos
    ADD COLUMN membrete MEDIUMTEXT NULL AFTER pie_texto;

-- ===========================================================================
-- 044_redactor_membrete.sql — Hojas membretadas y numeración con posición
--
--   • Imagen de encabezado y de pie por documento, pensadas para repetirse en
--     todas las páginas (logotipo, dirección, avisos legales…).
--   • Opacidad, posición (X/Y en cm desde la esquina de la hoja) y ancho en cm.
--   • Posición de la numeración: pie o encabezado, izquierda/centro/derecha.
--   Los archivos se guardan en public/uploads/vplan y se referencian por nombre.
-- ===========================================================================

ALTER TABLE redactor_documentos
    ADD COLUMN num_posicion VARCHAR(20) NOT NULL DEFAULT 'pie_centro' AFTER numerar,
    ADD COLUMN imagen_encabezado VARCHAR(64) NOT NULL DEFAULT '' AFTER pie_texto,
    ADD COLUMN imagen_encabezado_opacidad TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER imagen_encabezado,
    ADD COLUMN imagen_encabezado_x DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER imagen_encabezado_opacidad,
    ADD COLUMN imagen_encabezado_y DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER imagen_encabezado_x,
    ADD COLUMN imagen_encabezado_ancho DECIMAL(5,2) NOT NULL DEFAULT 5 AFTER imagen_encabezado_y,
    ADD COLUMN imagen_pie VARCHAR(64) NOT NULL DEFAULT '' AFTER imagen_encabezado_ancho,
    ADD COLUMN imagen_pie_opacidad TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER imagen_pie,
    ADD COLUMN imagen_pie_x DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER imagen_pie_opacidad,
    ADD COLUMN imagen_pie_y DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER imagen_pie_x,
    ADD COLUMN imagen_pie_ancho DECIMAL(5,2) NOT NULL DEFAULT 5 AFTER imagen_pie_y;

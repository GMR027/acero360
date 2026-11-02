-- Reversión de 044_redactor_membrete.sql — quita el membrete y la posición
-- de la numeración de los documentos del Redactor.

ALTER TABLE redactor_documentos
    DROP COLUMN num_posicion,
    DROP COLUMN imagen_encabezado,
    DROP COLUMN imagen_encabezado_opacidad,
    DROP COLUMN imagen_encabezado_x,
    DROP COLUMN imagen_encabezado_y,
    DROP COLUMN imagen_encabezado_ancho,
    DROP COLUMN imagen_pie,
    DROP COLUMN imagen_pie_opacidad,
    DROP COLUMN imagen_pie_x,
    DROP COLUMN imagen_pie_y,
    DROP COLUMN imagen_pie_ancho;

DELETE FROM schema_migraciones WHERE nombre = '044_redactor_membrete.sql';

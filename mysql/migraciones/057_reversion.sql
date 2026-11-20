-- Reversión de 057_archivo_carpeta_color_icono.sql — quita el color y el
-- icono personalizados de las carpetas del Archivo.

ALTER TABLE archivo_carpetas
    DROP COLUMN color,
    DROP COLUMN icono;

DELETE FROM schema_migraciones WHERE nombre = '057_archivo_carpeta_color_icono.sql';

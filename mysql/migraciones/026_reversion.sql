-- Reversión de 026_archivo.sql (borra las carpetas y los archivos registrados;
-- los bytes de storage/archivo/ quedan en disco: revisarlos a mano si hace falta).
DROP TABLE IF EXISTS archivo_archivos;
DROP TABLE IF EXISTS archivo_carpetas;
DELETE FROM schema_migraciones WHERE nombre = '026_archivo.sql';

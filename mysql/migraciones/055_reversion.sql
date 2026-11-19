-- Reversión de 055_llaves_avisos.sql (la app deja de recibir avisos hasta volver a activarlos).
DROP TABLE IF EXISTS llaves_avisos_android;
DELETE FROM schema_migraciones WHERE nombre = '055_llaves_avisos.sql';

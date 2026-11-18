-- Reversión de 054_llaves_huella.sql (las apps con huella vuelven a pedir contraseña).
DROP TABLE IF EXISTS llaves_huella_android;
DELETE FROM schema_migraciones WHERE nombre = '054_llaves_huella.sql';

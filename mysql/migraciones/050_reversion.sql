-- Reversión de 050_android.sql — quita el registro de la aplicación Android.
-- Los APK del almacén (<documentos>/android) no se borran: hazlo a mano si hace falta.
DROP TABLE dispositivos_android;
DROP TABLE paquetes_android;
DELETE FROM schema_migraciones WHERE nombre = '050_android.sql';

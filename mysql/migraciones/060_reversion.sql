-- Reversión de 060_entregas_app.sql — quita las estructuras de la app de entregas.
-- Las columnas nuevas de entregas/incidencias son opcionales; al quitarlas se
-- conservan las entregas y el historial existentes.

DROP TABLE IF EXISTS intento_fotos;
DROP TABLE IF EXISTS intentos_entrega;
DROP TABLE IF EXISTS tokens_entregas;

ALTER TABLE paquetes_android
  DROP INDEX uq_android_app_version,
  ADD UNIQUE KEY uq_android_version (version),
  DROP COLUMN aplicacion;

ALTER TABLE entrega_incidencias
  DROP INDEX uq_incidencia_token,
  DROP COLUMN origen,
  DROP COLUMN token;

ALTER TABLE entregas
  DROP COLUMN origen,
  DROP COLUMN firma_archivo,
  DROP COLUMN recibio_nombre;

DELETE FROM schema_migraciones WHERE nombre = '060_entregas_app.sql';

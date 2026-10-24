-- Reversión de 028_calendario.sql — elimina la tabla del calendario.
DROP TABLE IF EXISTS calendario_eventos;
-- Quita el registro para que `scripts/migrar.php` vuelva a aplicar la migración (patrón de 001-027).
DELETE FROM schema_migraciones WHERE nombre = '028_calendario.sql';

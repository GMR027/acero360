-- Reversión de 031_indice_recepciones.sql — la tabla vuelve a no tener índice
-- de fecha (sólo conservaba orden_id y token).

ALTER TABLE recepciones
    DROP INDEX idx_recepcion_fecha;
-- Quita el registro para que `scripts/migrar.php` vuelva a aplicar la migración (patrón de 001-027).
DELETE FROM schema_migraciones WHERE nombre = '031_indice_recepciones.sql';

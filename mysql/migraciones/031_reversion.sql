-- Reversión de 031_indice_recepciones.sql — la tabla vuelve a no tener índice
-- de fecha (sólo conservaba orden_id y token).

ALTER TABLE recepciones
    DROP INDEX idx_recepcion_fecha;

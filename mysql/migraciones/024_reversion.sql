-- Reversión de 024_recepcion_sin_relacion.sql (solo si no hay recepciones con destino 'sin_relacion').
ALTER TABLE recepcion_partidas
  MODIFY COLUMN destino ENUM('stock','por_terminar','material') NOT NULL DEFAULT 'stock';
DELETE FROM schema_migraciones WHERE nombre = '024_recepcion_sin_relacion.sql';

-- ===========================================================================
-- 024_recepcion_sin_relacion.sql — recibir artículos que no están relacionados con un producto propio ni un material
--   recepcion_partidas.destino  nuevo valor 'sin_relacion': solo registra cuánto llegó (cumplimiento de la compra);
--                               no mueve ningún inventario.
-- ===========================================================================
ALTER TABLE recepcion_partidas
  MODIFY COLUMN destino ENUM('stock','por_terminar','material','sin_relacion') NOT NULL DEFAULT 'stock';

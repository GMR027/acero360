-- Reversión de 022_iva_compras.sql (pierde la tasa y las marcas de partida gravada; el importe de IVA se conserva).
ALTER TABLE orden_compra_partidas DROP COLUMN aplica_iva;
ALTER TABLE ordenes_compra DROP COLUMN iva_tasa;
DELETE FROM schema_migraciones WHERE nombre = '022_iva_compras.sql';

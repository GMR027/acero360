-- Reversión de 021_partida_catalogo.sql (pierde la referencia al catálogo y las copias de código y costo del catálogo).
ALTER TABLE orden_compra_partidas
  DROP FOREIGN KEY fk_ocp_proveedor_producto, DROP KEY idx_ocp_proveedor_producto,
  DROP COLUMN costo_catalogo, DROP COLUMN codigo, DROP COLUMN proveedor_producto_id;
DELETE FROM schema_migraciones WHERE nombre = '021_partida_catalogo.sql';

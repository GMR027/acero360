-- Reversión de 019_compras_unificadas.sql. Solo si no se capturaron compras de insumos ni pagos
-- (se pierden las tablas nuevas). Respaldar antes.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS pagos_compra;
DROP TABLE IF EXISTS movimientos_material;
DROP TABLE IF EXISTS movimientos_por_terminar;
ALTER TABLE productos DROP COLUMN stock_por_terminar;
ALTER TABLE recepcion_partidas
  DROP FOREIGN KEY fk_rp_material, DROP KEY fk_rp_material, DROP COLUMN destino, DROP COLUMN material_id,
  MODIFY COLUMN cantidad_recibida INT NOT NULL, MODIFY COLUMN producto_id INT UNSIGNED NOT NULL;
ALTER TABLE orden_compra_partidas
  DROP FOREIGN KEY fk_ocp_material, DROP KEY fk_ocp_material, DROP COLUMN controla, DROP COLUMN unidad, DROP COLUMN material_id,
  MODIFY COLUMN cantidad_solicitada INT NOT NULL, MODIFY COLUMN producto_id INT UNSIGNED NOT NULL;
DROP TABLE IF EXISTS materiales;
ALTER TABLE ordenes_compra
  DROP FOREIGN KEY fk_oc_gasto, DROP FOREIGN KEY fk_oc_empresa, DROP FOREIGN KEY fk_oc_contribuyente, DROP FOREIGN KEY fk_oc_categoria,
  DROP KEY uq_oc_gasto, DROP KEY idx_oc_tipo, DROP KEY idx_oc_categoria, DROP KEY idx_oc_contribuyente,
  DROP COLUMN gasto_id, DROP COLUMN iva, DROP COLUMN estado_cfdi, DROP COLUMN empresa_id, DROP COLUMN contribuyente_id,
  DROP COLUMN categoria_id, DROP COLUMN referencia, DROP COLUMN tipo,
  MODIFY COLUMN estado ENUM('solicitada','parcial','completa','cerrada','cancelada') NOT NULL DEFAULT 'solicitada';
SET FOREIGN_KEY_CHECKS = 1;
DELETE FROM schema_migraciones WHERE nombre = '019_compras_unificadas.sql';

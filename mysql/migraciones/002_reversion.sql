-- Reversión de 002 (sólo si no hay recepciones capturadas que perder).
ALTER TABLE movimientos_inventario DROP FOREIGN KEY fk_mov_recepcion,
  DROP COLUMN recepcion_id, DROP COLUMN motivo;
DROP TABLE recepcion_partidas, recepciones, orden_compra_partidas, ordenes_compra;
ALTER TABLE proveedores DROP COLUMN contacto, DROP COLUMN email, DROP COLUMN observaciones, DROP COLUMN activo;
DROP TABLE auditoria, configuracion;
ALTER TABLE productos DROP INDEX uq_productos_sku, DROP COLUMN costo_actual, DROP COLUMN stock_minimo;
DROP TABLE producto_empresa;
ALTER TABLE empresas DROP FOREIGN KEY fk_empresa_contribuyente, DROP COLUMN contribuyente_id;
DROP TABLE contribuyentes;
DELETE FROM schema_migraciones WHERE nombre = '002_negocios_catalogo_compras.sql';

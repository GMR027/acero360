ALTER TABLE ingresos DROP COLUMN facturacion_origen, DROP COLUMN facturacion_manual, DROP COLUMN factura_documento_id,
  DROP COLUMN facturacion_usuario_id, DROP COLUMN facturacion_en;
ALTER TABLE ventas DROP COLUMN convertida_en, DROP COLUMN convertida_por;
DELETE FROM schema_migraciones WHERE nombre = '012_facturacion_comercial.sql';

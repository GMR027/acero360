-- Reversión de 036_complementos_clientes.sql
DROP TABLE IF EXISTS complemento_cobros;

-- Los complementos de este lado no existen en la versión anterior: se marcan
-- como de proveedor sólo para poder reducir el ENUM sin perder filas.
UPDATE complementos_pago SET lado = 'proveedor' WHERE lado = 'cliente';
ALTER TABLE complemento_doctos
  DROP FOREIGN KEY fk_docto_ingreso,
  DROP KEY idx_docto_ingreso,
  DROP COLUMN ingreso_id;

ALTER TABLE complementos_pago
  DROP KEY idx_complemento_lado,
  DROP COLUMN lado;

UPDATE lecturas_documentales SET destino = 'complemento' WHERE destino = 'complemento_venta';
ALTER TABLE lecturas_documentales
  MODIFY destino ENUM('gasto','venta','compra','complemento') NOT NULL;
-- Quita el registro para que `scripts/migrar.php` vuelva a aplicar la migración (patrón de 001-027).
DELETE FROM schema_migraciones WHERE nombre = '036_complementos_clientes.sql';

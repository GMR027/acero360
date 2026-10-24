-- Reversión de 034_complementos_pago.sql
DROP TABLE IF EXISTS complemento_pagos;
DROP TABLE IF EXISTS complemento_doctos;
DROP TABLE IF EXISTS complementos_pago;

UPDATE lecturas_documentales SET destino = 'compra' WHERE destino = 'complemento';
UPDATE lecturas_documentales SET entidad = NULL, entidad_id = NULL WHERE entidad = 'complemento';

ALTER TABLE lecturas_documentales
  MODIFY destino ENUM('gasto','venta','compra') NOT NULL,
  MODIFY entidad ENUM('ingreso','gasto','orden_compra') DEFAULT NULL;
-- Quita el registro para que `scripts/migrar.php` vuelva a aplicar la migración (patrón de 001-027).
DELETE FROM schema_migraciones WHERE nombre = '034_complementos_pago.sql';

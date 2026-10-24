-- Reversión de 032_compras_inteligentes.sql
-- Las lecturas de «compra» se marcan como de gasto (pierden el flujo nuevo) y
-- las confirmadas contra una orden quedan sin entidad (se re-analizan si hace falta).
UPDATE lecturas_documentales SET destino = 'gasto' WHERE destino = 'compra';
UPDATE lecturas_documentales SET entidad = NULL, entidad_id = NULL WHERE entidad = 'orden_compra';

ALTER TABLE lecturas_documentales
  MODIFY destino ENUM('gasto','venta') NOT NULL,
  MODIFY entidad ENUM('ingreso','gasto') DEFAULT NULL;
-- Quita el registro para que `scripts/migrar.php` vuelva a aplicar la migración (patrón de 001-027).
DELETE FROM schema_migraciones WHERE nombre = '032_compras_inteligentes.sql';

-- Reversión de 003: elimina el vínculo (las ventas creadas desde cotizaciones se conservan como ventas directas).
ALTER TABLE ventas DROP FOREIGN KEY fk_venta_cotizacion, DROP INDEX uq_venta_cotizacion, DROP COLUMN cotizacion_id;
DELETE FROM schema_migraciones WHERE nombre = '003_ventas_unificadas.sql';

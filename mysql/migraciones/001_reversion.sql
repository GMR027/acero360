-- Reversión de 001: SOLO si no hay ventas nuevas que perder. Restaura el nombre de la tabla antigua.
DROP TABLE venta_detalles;
ALTER TABLE movimientos_inventario DROP FOREIGN KEY fk_movimiento_venta, DROP COLUMN venta_id;
DROP TABLE ventas;
RENAME TABLE ventas_entregas_legado TO ventas;
DELETE FROM schema_migraciones WHERE nombre = '001_ventas_directas_legado.sql';

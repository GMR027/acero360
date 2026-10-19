ALTER TABLE ventas DROP COLUMN entrega_cerrada_en, DROP COLUMN entrega_cerrada_motivo;
DELETE FROM schema_migraciones WHERE nombre = '005_cierre_entrega_devolucion.sql';

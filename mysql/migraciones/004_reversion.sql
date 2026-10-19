-- Reversión de 004 (borra el historial de preparaciones y entregas).
DROP TABLE entrega_renglones, entregas, preparacion_ventas, preparaciones;
DELETE FROM schema_migraciones WHERE nombre = '004_entregas.sql';

-- Reversión de 052_pedidos_portal.sql (borra los pedidos capturados).
ALTER TABLE clientes DROP COLUMN portal_contrasena, DROP COLUMN portal_actualizada_en;
DROP TABLE pedido_detalles;
DROP TABLE pedidos;
DELETE FROM schema_migraciones WHERE nombre = '052_pedidos_portal.sql';

-- Reversión de 051_catalogos_producto_cliente.sql. productos.categoria (texto)
-- conserva el nombre, así que no se pierde la categoría al revertir.
-- Las imágenes de uso quedan en la carpeta de imágenes: bórralas a mano si hace falta.
ALTER TABLE clientes DROP FOREIGN KEY fk_clientes_ubicacion, DROP KEY idx_clientes_ubicacion,
    DROP COLUMN ubicacion_id, DROP COLUMN ubicacion_mapa, DROP COLUMN distribuidor;
DROP TABLE ubicaciones_cliente;
ALTER TABLE productos DROP FOREIGN KEY fk_productos_categoria, DROP KEY idx_productos_categoria,
    DROP COLUMN categoria_id, DROP COLUMN imagen_uso;
DROP TABLE categorias_producto;
DELETE FROM schema_migraciones WHERE nombre = '051_catalogos_producto_cliente.sql';

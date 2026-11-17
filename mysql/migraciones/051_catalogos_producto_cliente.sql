-- ===========================================================================
-- 051_catalogos_producto_cliente.sql — Catálogos de categorías de producto y
-- ubicaciones de cliente, imagen de «detalle de uso» y cliente distribuidor.
--
-- • categorias_producto: la categoría del producto deja de ser texto libre.
--   productos.categoria_id apunta al catálogo; productos.categoria se CONSERVA
--   como copia del nombre (la llena la aplicación al guardar y al renombrar)
--   para que la búsqueda, el cotizador y los reportes sigan funcionando igual.
--   Las categorías ya escritas se convierten en filas del catálogo.
-- • productos.imagen_uso: segunda imagen (cómo se usa / instala el producto).
-- • ubicaciones_cliente: catálogo de ubicaciones (texto) para los clientes.
-- • clientes.ubicacion_id, clientes.ubicacion_mapa (enlace de Google Maps),
--   clientes.distribuidor.
-- ===========================================================================

CREATE TABLE categorias_producto (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre    VARCHAR(80)  NOT NULL,
  creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categorias_producto_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categorias_producto (nombre)
SELECT DISTINCT LEFT(TRIM(categoria), 80) FROM productos
 WHERE categoria IS NOT NULL AND TRIM(categoria) <> '';

ALTER TABLE productos
    ADD COLUMN categoria_id INT UNSIGNED DEFAULT NULL
        COMMENT 'Categoría del catálogo categorias_producto' AFTER categoria,
    ADD COLUMN imagen_uso VARCHAR(255) DEFAULT NULL
        COMMENT 'Imagen del detalle de uso del producto' AFTER imagen,
    ADD KEY idx_productos_categoria (categoria_id),
    ADD CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id)
        REFERENCES categorias_producto (id) ON DELETE SET NULL;

UPDATE productos p
  JOIN categorias_producto c ON c.nombre = LEFT(TRIM(p.categoria), 80)
   SET p.categoria_id = c.id, p.categoria = c.nombre;

CREATE TABLE ubicaciones_cliente (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre    VARCHAR(120) NOT NULL,
  creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ubicaciones_cliente_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE clientes
    ADD COLUMN ubicacion_id INT UNSIGNED DEFAULT NULL
        COMMENT 'Ubicación del catálogo ubicaciones_cliente' AFTER ubicacion,
    ADD COLUMN ubicacion_mapa VARCHAR(500) DEFAULT NULL
        COMMENT 'Enlace o texto de ubicación de Google Maps' AFTER ubicacion_id,
    ADD COLUMN distribuidor TINYINT(1) NOT NULL DEFAULT 0 AFTER vip,
    ADD KEY idx_clientes_ubicacion (ubicacion_id),
    ADD CONSTRAINT fk_clientes_ubicacion FOREIGN KEY (ubicacion_id)
        REFERENCES ubicaciones_cliente (id) ON DELETE SET NULL;

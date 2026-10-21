-- ===========================================================================
-- 020_semiterminados_proveedor.sql — catálogo de productos semiterminados por proveedor
--   proveedor_productos            lo que cada proveedor vende: nombre, descripción, código, costo unitario y estado.
--                                  Un proveedor tiene muchos; el mismo artículo puede existir con otro costo en otro proveedor.
--                                  Nunca se borra: se desactiva (las compras históricas conservan su referencia).
--   proveedor_producto_relaciones  vínculo OPCIONAL y flexible con los productos propios (N:M, sin cantidades ni etapas
--                                  de fabricación): qué semiterminado de qué proveedor se usa para un producto propio.
-- No toca compras ni inventario existentes.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS proveedor_productos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proveedor_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(150) NOT NULL COMMENT 'Nombre comercial del semiterminado',
  descripcion VARCHAR(255) DEFAULT NULL,
  codigo VARCHAR(40) DEFAULT NULL COMMENT 'Código del proveedor o interno; único por proveedor cuando se captura',
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Costo vigente de compra; las compras guardan el suyo',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_por INT UNSIGNED DEFAULT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pp_proveedor_nombre (proveedor_id, nombre),
  UNIQUE KEY uq_pp_proveedor_codigo (proveedor_id, codigo),
  KEY idx_pp_activo (activo),
  CONSTRAINT fk_pp_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pp_usuario FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Productos semiterminados que ofrece cada proveedor (no son productos propios)';

CREATE TABLE IF NOT EXISTS proveedor_producto_relaciones (
  proveedor_producto_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  creado_por INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (proveedor_producto_id, producto_id),
  KEY idx_ppr_producto (producto_id),
  CONSTRAINT fk_ppr_semi FOREIGN KEY (proveedor_producto_id) REFERENCES proveedor_productos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ppr_producto FOREIGN KEY (producto_id) REFERENCES productos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Qué semiterminado de qué proveedor se usa para un producto propio (relación opcional)';

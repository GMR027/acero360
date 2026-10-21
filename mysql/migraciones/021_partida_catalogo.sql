-- ===========================================================================
-- 021_partida_catalogo.sql — la partida de compra recuerda qué producto del catálogo del proveedor se compró
--   proveedor_producto_id  referencia al catálogo (NULL = producto fuera del catálogo, como antes)
--   codigo                 código del proveedor tal como estaba al comprar (copia)
--   costo_catalogo         costo del catálogo al comprar (copia). costo_esperado sigue siendo el costo USADO: si es distinto,
--                          el usuario lo modificó a mano y se nota.
-- El nombre (descripcion), el costo usado (costo_esperado) y el importe ya eran copias propias de la partida: el catálogo
-- puede cambiar o desactivarse sin alterar compras anteriores. Las compras existentes quedan sin referencia.
-- ===========================================================================
ALTER TABLE orden_compra_partidas
  ADD COLUMN proveedor_producto_id INT UNSIGNED DEFAULT NULL COMMENT 'Producto del catálogo del proveedor (opcional)' AFTER material_id,
  ADD COLUMN codigo VARCHAR(40) DEFAULT NULL COMMENT 'Código del proveedor al comprar (copia)' AFTER descripcion,
  ADD COLUMN costo_catalogo DECIMAL(12,2) DEFAULT NULL COMMENT 'Costo del catálogo al comprar (copia); si difiere de costo_esperado, se modificó a mano' AFTER costo_esperado,
  ADD KEY idx_ocp_proveedor_producto (proveedor_producto_id),
  ADD CONSTRAINT fk_ocp_proveedor_producto FOREIGN KEY (proveedor_producto_id) REFERENCES proveedor_productos (id) ON DELETE RESTRICT ON UPDATE CASCADE;

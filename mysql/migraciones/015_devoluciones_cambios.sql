-- ===========================================================================
-- 015_devoluciones_cambios.sql — devoluciones y cambios de productos vendidos
--   venta_devoluciones          qué se devolvió de cada venta, cuántas piezas requieren cambio, cuándo y quién lo registró
--   venta_cambios_entregados    entregas de la pieza de reemplazo (la pieza devuelta suma al inventario al devolver;
--                               la de reemplazo se descuenta al entregarla)
-- Las devoluciones anteriores (movimientos de inventario «devolucion») se copian con cambio = 0.
-- El motivo NO es obligatorio: solo hay una nota opcional.
-- ===========================================================================
CREATE TABLE venta_devoluciones (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id          INT UNSIGNED NOT NULL,
  producto_id       INT UNSIGNED NOT NULL,
  fecha             DATE NOT NULL,
  cantidad_devuelta INT UNSIGNED NOT NULL,
  cantidad_cambio   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Piezas devueltas que el cliente debe recibir de nuevo (≤ devuelta)',
  nota              VARCHAR(200) DEFAULT NULL,
  usuario_id        INT UNSIGNED DEFAULT NULL,
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_devolucion_venta (venta_id),
  CONSTRAINT fk_devolucion_venta FOREIGN KEY (venta_id) REFERENCES ventas (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_devolucion_producto FOREIGN KEY (producto_id) REFERENCES productos (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_devolucion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE venta_cambios_entregados (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  devolucion_id INT UNSIGNED NOT NULL,
  cantidad      INT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  usuario_id    INT UNSIGNED DEFAULT NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cambio_devolucion (devolucion_id),
  CONSTRAINT fk_cambio_devolucion FOREIGN KEY (devolucion_id) REFERENCES venta_devoluciones (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_cambio_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO venta_devoluciones (venta_id, producto_id, fecha, cantidad_devuelta, cantidad_cambio, usuario_id, creado_en)
SELECT m.venta_id, m.producto_id, DATE(MIN(m.creado_en)), SUM(m.cantidad), 0, MAX(m.usuario_id), MIN(m.creado_en)
  FROM movimientos_inventario m
 WHERE m.motivo = 'devolucion' AND m.venta_id IS NOT NULL
 GROUP BY m.venta_id, m.producto_id, DATE(m.creado_en);

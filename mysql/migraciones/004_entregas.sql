-- ===========================================================================
-- 004_entregas.sql — Etapa 4: pantalla de entrega y preparación de pedidos
--
--   preparaciones         lote de ventas que se prepara junto (productos agrupados)
--   preparacion_ventas    ventas que lo componen
--   entregas              cada entrega (total o parcial) de una venta a su cliente
--   entrega_renglones     piezas realmente entregadas por producto
--
-- Nada de esto toca el inventario: la salida ya ocurrió al registrar la venta.
-- ===========================================================================

CREATE TABLE preparaciones (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio       VARCHAR(20)  NOT NULL COMMENT 'Ej. PREP-2026-0001',
  estado      ENUM('pendiente','en_preparacion','preparada','en_reparto','entregada','cancelada')
              NOT NULL DEFAULT 'pendiente',
  notas       VARCHAR(255) DEFAULT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_preparacion_folio (folio),
  KEY idx_preparacion_estado (estado),
  CONSTRAINT fk_preparacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE preparacion_ventas (
  preparacion_id INT UNSIGNED NOT NULL,
  venta_id       INT UNSIGNED NOT NULL,
  agregada_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (preparacion_id, venta_id),
  KEY idx_pv_venta (venta_id),
  CONSTRAINT fk_pv_preparacion FOREIGN KEY (preparacion_id) REFERENCES preparaciones (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pv_venta FOREIGN KEY (venta_id) REFERENCES ventas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE entregas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id       INT UNSIGNED NOT NULL,
  preparacion_id INT UNSIGNED DEFAULT NULL,
  fecha          DATE         NOT NULL,
  token          CHAR(32)     NOT NULL COMMENT 'Un solo uso: reenviar el formulario no duplica la entrega',
  notas          VARCHAR(255) DEFAULT NULL,
  usuario_id     INT UNSIGNED NOT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entrega_token (token),
  KEY idx_entrega_venta (venta_id),
  KEY idx_entrega_preparacion (preparacion_id),
  CONSTRAINT fk_entrega_venta FOREIGN KEY (venta_id) REFERENCES ventas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_entrega_preparacion FOREIGN KEY (preparacion_id) REFERENCES preparaciones (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_entrega_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE entrega_renglones (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entrega_id  INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  cantidad    INT          NOT NULL,
  PRIMARY KEY (id),
  KEY idx_er_entrega (entrega_id),
  CONSTRAINT fk_er_entrega FOREIGN KEY (entrega_id) REFERENCES entregas (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_er_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

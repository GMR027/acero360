-- ===========================================================================
-- 052_pedidos_portal.sql — Pedidos de clientes y contraseña del portal.
--
-- • pedidos / pedido_detalles: lo que el cliente pide antes de volverse
--   cotización o venta. Mismo esquema de importes que cotizaciones (subtotal
--   bruto, descuento, IVA, total). Al marcarlo como cotización se guarda
--   cotizacion_id; al marcarlo como venta, venta_id.
--   origen = 'portal' queda reservado para el portal público (otra app que
--   comparte esta base): inserta con usuario_id NULL y estado 'pendiente'.
-- • clientes.portal_contrasena: hash bcrypt (password_hash) con el que el
--   cliente entra al portal. NUNCA texto plano.
-- ===========================================================================

CREATE TABLE pedidos (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio              VARCHAR(20)  NOT NULL COMMENT 'Ej. PED-2026-0001',
  empresa_id         INT UNSIGNED NOT NULL COMMENT 'Negocio al que se le pide',
  cliente_id         INT UNSIGNED NOT NULL,
  usuario_id         INT UNSIGNED DEFAULT NULL COMMENT 'Admin que lo capturó (NULL si vino del portal)',
  origen             ENUM('admin','portal') NOT NULL DEFAULT 'admin',
  fecha              DATE         NOT NULL,
  estado             ENUM('pendiente','cotizado','vendido','cancelado') NOT NULL DEFAULT 'pendiente',
  subtotal           DECIMAL(14,2) NOT NULL DEFAULT 0,
  descuento_pct      DECIMAL(5,2)  NOT NULL DEFAULT 0 COMMENT 'Descuento global capturado',
  descuento          DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Descuento total (renglones + global)',
  aplica_iva         TINYINT(1)   NOT NULL DEFAULT 1,
  iva                DECIMAL(14,2) NOT NULL DEFAULT 0,
  total              DECIMAL(14,2) NOT NULL DEFAULT 0,
  condiciones_envio  TEXT         DEFAULT NULL,
  condiciones_compra TEXT         DEFAULT NULL,
  comentarios        TEXT         DEFAULT NULL,
  cotizacion_id      INT UNSIGNED DEFAULT NULL,
  venta_id           INT UNSIGNED DEFAULT NULL,
  cancelado_por      INT UNSIGNED DEFAULT NULL,
  cancelado_en       DATETIME     DEFAULT NULL,
  creado_en          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pedido_folio (folio),
  KEY idx_pedidos_empresa (empresa_id),
  KEY idx_pedidos_cliente (cliente_id),
  KEY idx_pedidos_estado (estado),
  CONSTRAINT fk_pedido_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pedido_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pedido_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_pedido_venta FOREIGN KEY (venta_id) REFERENCES ventas (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedido_detalles (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id       INT UNSIGNED NOT NULL,
  producto_id     INT UNSIGNED NOT NULL,
  cantidad        INT          NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(12,2) NOT NULL,
  descuento_pct   DECIMAL(5,2)  NOT NULL DEFAULT 0,
  subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_pdetalle_pedido (pedido_id),
  CONSTRAINT fk_pdetalle_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pdetalle_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE clientes
    ADD COLUMN portal_contrasena VARCHAR(255) DEFAULT NULL
        COMMENT 'Hash bcrypt de la contraseña del portal de clientes' AFTER email,
    ADD COLUMN portal_actualizada_en DATETIME DEFAULT NULL AFTER portal_contrasena;

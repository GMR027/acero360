-- ===========================================================================
-- migracion_ventas_directas.sql
-- Reemplaza el antiguo registro de entrega por VENTAS DIRECTAS (sin cotización).
--
-- Uso:
--   mysql -u root -p cotizaciones < mysql/migracion_ventas_directas.sql
--
-- Antes de aplicarla genera un respaldo:
--   mysqldump -u root -p cotizaciones > respaldo.sql
--
-- QUÉ HACE
--   1. Elimina la tabla "ventas" anterior (registro de entrega ligado a una
--      cotización). Sus datos NO se migran, por decisión del negocio.
--   2. Crea "ventas" (encabezado) y "venta_detalles" (renglones), con la misma
--      estructura que cotizaciones / cotizacion_detalles.
--   3. Agrega movimientos_inventario.venta_id para saber qué salida de almacén
--      nació de una venta directa.
-- ===========================================================================

DROP TABLE IF EXISTS ventas;

CREATE TABLE ventas (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio               VARCHAR(20)  NOT NULL COMMENT 'Ej. VEN-2026-0001',
  empresa_id          INT UNSIGNED NOT NULL COMMENT 'Negocio emisor',
  cliente_id          INT UNSIGNED NOT NULL COMMENT 'Cliente destino',
  usuario_id          INT UNSIGNED NOT NULL COMMENT 'Admin que la registró',
  fecha               DATE         NOT NULL,
  estado              ENUM('registrada','cancelada') NOT NULL DEFAULT 'registrada'
                      COMMENT 'Cancelada = devuelve el stock y no cuenta en ganancias',
  subtotal            DECIMAL(14,2) NOT NULL DEFAULT 0,
  descuento           DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Descuento total (renglones + global)',
  aplica_iva          TINYINT(1)   NOT NULL DEFAULT 1,
  iva                 DECIMAL(14,2) NOT NULL DEFAULT 0,
  total               DECIMAL(14,2) NOT NULL DEFAULT 0,
  condiciones_envio   TEXT         DEFAULT NULL COMMENT 'Términos logísticos',
  condiciones_compra  TEXT         DEFAULT NULL COMMENT 'Términos comerciales',
  comentarios         TEXT         DEFAULT NULL,
  creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_venta_folio (folio),
  KEY idx_ventas_cliente (cliente_id),
  KEY idx_ventas_empresa (empresa_id),
  KEY idx_ventas_fecha (fecha),
  CONSTRAINT fk_venta_empresa
    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_venta_cliente
    FOREIGN KEY (cliente_id) REFERENCES clientes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_venta_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE venta_detalles (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id        INT UNSIGNED NOT NULL,
  producto_id     INT UNSIGNED NOT NULL,
  cantidad        INT          NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(12,2) NOT NULL COMMENT 'Lista O precio especial (snapshot)',
  descuento_pct   DECIMAL(5,2)  NOT NULL DEFAULT 0,
  subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_detalle_venta (venta_id),
  CONSTRAINT fk_vdetalle_venta
    FOREIGN KEY (venta_id) REFERENCES ventas (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_vdetalle_producto
    FOREIGN KEY (producto_id) REFERENCES productos (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE movimientos_inventario
  ADD COLUMN venta_id INT UNSIGNED DEFAULT NULL
      COMMENT 'Venta directa que originó el movimiento'
      AFTER cotizacion_id,
  ADD KEY idx_movimiento_venta (venta_id),
  ADD CONSTRAINT fk_movimiento_venta
    FOREIGN KEY (venta_id) REFERENCES ventas (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- ===========================================================================
-- 002_negocios_catalogo_compras.sql — Etapa 2
--
--   1. contribuyentes: entidad FISCAL separada de los negocios comerciales.
--   2. producto_empresa: un producto puede venderse en varios negocios y su
--      existencia sigue viviendo UNA sola vez en productos.stock.
--   3. productos: costo actual, stock mínimo y SKU único.
--   4. configuracion y auditoria (base para el resto de las etapas).
--   5. proveedores: activo/contacto/observaciones.
--   6. Órdenes de compra, partidas y recepciones (parciales, con historial).
--   7. movimientos_inventario: recepción de origen y motivo.
--
-- Se aplica con:  php scripts/migrar.php   (ver schema_migraciones)
-- Reversión:      mysql/migraciones/002_reversion.sql  (o restaurar el respaldo)
-- ===========================================================================

-- 1. Entidad fiscal ---------------------------------------------------------
CREATE TABLE contribuyentes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rfc           VARCHAR(13)  NOT NULL,
  razon_social  VARCHAR(200) DEFAULT NULL,
  pais          VARCHAR(40)  NOT NULL DEFAULT 'México',
  tipo_persona  ENUM('fisica','moral') NOT NULL DEFAULT 'fisica',
  regimen_clave VARCHAR(5)   DEFAULT NULL COMMENT 'Clave SAT del régimen (se confirma con el contador)',
  regimen       VARCHAR(120) DEFAULT NULL,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contribuyente_rfc (rfc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO contribuyentes (rfc, razon_social, tipo_persona, regimen)
SELECT COALESCE(NULLIF(MAX(rfc), ''), 'XAXX010101000'), 'Contribuyente principal', 'fisica',
       'Actividades Empresariales y Profesionales'
  FROM empresas;

ALTER TABLE empresas
  ADD COLUMN contribuyente_id INT UNSIGNED DEFAULT NULL AFTER id,
  ADD KEY idx_empresa_contribuyente (contribuyente_id),
  ADD CONSTRAINT fk_empresa_contribuyente
    FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

UPDATE empresas SET contribuyente_id = (SELECT MIN(id) FROM contribuyentes);

-- 2. Productos <-> negocios (N:M) -------------------------------------------
CREATE TABLE producto_empresa (
  producto_id INT UNSIGNED NOT NULL,
  empresa_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (producto_id, empresa_id),
  KEY idx_pe_empresa (empresa_id),
  CONSTRAINT fk_pe_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pe_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO producto_empresa (producto_id, empresa_id)
SELECT id, empresa_id FROM productos;

-- 3. Productos --------------------------------------------------------------
UPDATE productos SET sku = NULL WHERE TRIM(sku) = '';

ALTER TABLE productos
  ADD COLUMN costo_actual DECIMAL(12,2) NOT NULL DEFAULT 0
      COMMENT 'Costo actual de adquisición (editable; no altera compras históricas)' AFTER precio,
  ADD COLUMN stock_minimo INT DEFAULT NULL COMMENT 'Alerta de existencias bajas (opcional)' AFTER stock,
  ADD UNIQUE KEY uq_productos_sku (sku);

ALTER TABLE productos
  MODIFY empresa_id INT UNSIGNED NOT NULL COMMENT 'Negocio principal; los demás viven en producto_empresa';

-- 4. Configuración y auditoría ----------------------------------------------
CREATE TABLE configuracion (
  clave          VARCHAR(60)  NOT NULL,
  valor          VARCHAR(255) NOT NULL,
  actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion (clave, valor) VALUES ('permitir_stock_negativo', '1');

CREATE TABLE auditoria (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED DEFAULT NULL,
  entidad     VARCHAR(40)  NOT NULL,
  entidad_id  INT UNSIGNED DEFAULT NULL,
  accion      VARCHAR(40)  NOT NULL,
  motivo      VARCHAR(255) DEFAULT NULL,
  detalle     JSON         DEFAULT NULL,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_auditoria_entidad (entidad, entidad_id),
  KEY idx_auditoria_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Proveedores ------------------------------------------------------------
ALTER TABLE proveedores
  ADD COLUMN contacto      VARCHAR(120) DEFAULT NULL AFTER telefono,
  ADD COLUMN email         VARCHAR(120) DEFAULT NULL AFTER contacto,
  ADD COLUMN observaciones TEXT         DEFAULT NULL AFTER tipo_proveedor,
  ADD COLUMN activo        TINYINT(1)   NOT NULL DEFAULT 1 AFTER observaciones;

-- 6. Órdenes de compra ------------------------------------------------------
CREATE TABLE ordenes_compra (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio            VARCHAR(20)  NOT NULL COMMENT 'Ej. OC-2026-0001',
  proveedor_id     INT UNSIGNED NOT NULL,
  fecha_solicitud  DATE         NOT NULL,
  estado           ENUM('solicitada','parcial','completa','cerrada','cancelada')
                   NOT NULL DEFAULT 'solicitada',
  total_esperado   DECIMAL(14,2) NOT NULL DEFAULT 0,
  observaciones    TEXT         DEFAULT NULL,
  usuario_id       INT UNSIGNED NOT NULL,
  cerrada_en       DATETIME     DEFAULT NULL,
  cerrada_por      INT UNSIGNED DEFAULT NULL,
  motivo_cierre    VARCHAR(255) DEFAULT NULL,
  creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oc_folio (folio),
  KEY idx_oc_proveedor (proveedor_id),
  KEY idx_oc_estado (estado),
  CONSTRAINT fk_oc_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_oc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orden_compra_partidas (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden_id            INT UNSIGNED NOT NULL,
  producto_id         INT UNSIGNED NOT NULL COMMENT 'Producto comercial terminado que se recibirá',
  descripcion         VARCHAR(200) DEFAULT NULL COMMENT 'Cómo lo nombra el proveedor (p. ej. sin pintar)',
  cantidad_solicitada INT          NOT NULL,
  costo_esperado      DECIMAL(12,2) NOT NULL DEFAULT 0,
  importe_esperado    DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ocp_orden (orden_id),
  CONSTRAINT fk_ocp_orden FOREIGN KEY (orden_id) REFERENCES ordenes_compra (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ocp_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recepciones (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden_id         INT UNSIGNED NOT NULL,
  fecha            DATE         NOT NULL,
  token            CHAR(32)     NOT NULL COMMENT 'Un solo uso: impide duplicar la recepción al reenviar el formulario',
  actualiza_costo  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = copió el costo recibido a productos.costo_actual',
  notas            VARCHAR(255) DEFAULT NULL,
  usuario_id       INT UNSIGNED NOT NULL,
  creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recepcion_token (token),
  KEY idx_recepcion_orden (orden_id),
  CONSTRAINT fk_recepcion_orden FOREIGN KEY (orden_id) REFERENCES ordenes_compra (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_recepcion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recepcion_partidas (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recepcion_id      INT UNSIGNED NOT NULL,
  partida_id        INT UNSIGNED NOT NULL,
  producto_id       INT UNSIGNED NOT NULL,
  cantidad_recibida INT          NOT NULL,
  costo_unitario    DECIMAL(12,2) NOT NULL COMMENT 'Costo efectivo (histórico, no cambia)',
  PRIMARY KEY (id),
  KEY idx_rp_recepcion (recepcion_id),
  KEY idx_rp_partida (partida_id),
  CONSTRAINT fk_rp_recepcion FOREIGN KEY (recepcion_id) REFERENCES recepciones (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_rp_partida FOREIGN KEY (partida_id) REFERENCES orden_compra_partidas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_rp_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Movimientos de inventario ----------------------------------------------
ALTER TABLE movimientos_inventario
  ADD COLUMN recepcion_id INT UNSIGNED DEFAULT NULL AFTER venta_id,
  ADD COLUMN motivo VARCHAR(30) DEFAULT NULL
      COMMENT 'venta, recepcion, ajuste, devolucion, correccion' AFTER recepcion_id,
  ADD KEY idx_mov_recepcion (recepcion_id),
  ADD CONSTRAINT fk_mov_recepcion FOREIGN KEY (recepcion_id) REFERENCES recepciones (id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

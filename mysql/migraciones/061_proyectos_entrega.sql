-- ===========================================================================
-- 061_proyectos_entrega.sql — Proyectos de entrega por cliente
--
-- Un proyecto es un COMPROMISO comercial (ej. 2,000 canastillas para la
-- vialidad X). No descuenta inventario ni genera ingresos: eso lo siguen
-- haciendo las ventas y las entregas.
--
--   proyectos_entrega          cabecera del proyecto
--   proyecto_entrega_partidas  productos y cantidades comprometidas
--   ventas.proyecto_id         venta vinculada al proyecto (opcional)
--
-- El avance (vendido, entregado y pendiente) se calcula al vuelo con las
-- ventas y entregas reales; no se guardan contadores que puedan desincronizarse.
-- ===========================================================================

CREATE TABLE proyectos_entrega (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio             VARCHAR(20)  NOT NULL COMMENT 'Ej. PRY-2026-0001',
  cliente_id        INT UNSIGNED NOT NULL,
  empresa_id        INT UNSIGNED NOT NULL COMMENT 'Negocio que compromete el proyecto',
  nombre            VARCHAR(160) NOT NULL,
  descripcion       TEXT         DEFAULT NULL,
  fecha_inicio      DATE         DEFAULT NULL,
  fecha_compromiso  DATE         DEFAULT NULL,
  estado            ENUM('activo','pausado','completado','cancelado')
                    NOT NULL DEFAULT 'activo',
  notas             TEXT         DEFAULT NULL,
  usuario_id        INT UNSIGNED NOT NULL,
  creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proyecto_folio (folio),
  KEY idx_proyecto_cliente (cliente_id),
  KEY idx_proyecto_empresa (empresa_id),
  KEY idx_proyecto_estado (estado),
  KEY idx_proyecto_compromiso (fecha_compromiso),
  CONSTRAINT fk_proyecto_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_proyecto_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_proyecto_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE proyecto_entrega_partidas (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proyecto_id           INT UNSIGNED NOT NULL,
  producto_id           INT UNSIGNED NOT NULL,
  cantidad_comprometida INT          NOT NULL,
  precio_unitario       DECIMAL(12,2) DEFAULT NULL
                        COMMENT 'Precio acordado opcional; no altera ventas',
  notas                 VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proyecto_producto (proyecto_id, producto_id),
  KEY idx_proyecto_partida_producto (producto_id),
  CONSTRAINT fk_proyecto_partida_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos_entrega (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_proyecto_partida_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ventas
  ADD COLUMN proyecto_id INT UNSIGNED DEFAULT NULL
      COMMENT 'Proyecto de entrega al que pertenece la venta (opcional)' AFTER cotizacion_id,
  ADD KEY idx_ventas_proyecto (proyecto_id),
  ADD CONSTRAINT fk_venta_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos_entrega (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

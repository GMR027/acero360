-- ===========================================================================
-- 019_compras_unificadas.sql — Compras y Proveedores en un solo flujo
--   ordenes_compra        tipo (semiterminado | insumo), referencia del proveedor, categoría, entidad fiscal,
--                         estado del CFDI, IVA capturado y vínculo ÚNICO con el gasto (una compra = un gasto).
--   orden_compra_partidas producto opcional (insumos y relación pendiente), material opcional, unidad,
--                         «controla» (lleva recepción) y cantidades decimales.
--   recepcion_partidas    destino de lo recibido (por terminar o material) y cantidades decimales.
--   productos             stock_por_terminar: semiterminados recibidos que aún NO se pueden vender.
--   movimientos_por_terminar · materiales · movimientos_material   bitácoras propias (no mezclan existencias).
--   pagos_compra          pagos parciales o totales; reducen el saldo y NUNCA crean gastos.
-- Nada se borra: las compras existentes quedan como semiterminado/pendiente de CFDI.
-- ===========================================================================

ALTER TABLE ordenes_compra
  MODIFY COLUMN estado ENUM('solicitada','parcial','completa','cerrada','cancelada','sin_recepcion')
      NOT NULL DEFAULT 'solicitada',
  ADD COLUMN tipo ENUM('semiterminado','insumo') NOT NULL DEFAULT 'semiterminado'
      COMMENT 'semiterminado = producto del catálogo por terminar; insumo = material o consumible' AFTER folio,
  ADD COLUMN referencia VARCHAR(60) DEFAULT NULL COMMENT 'Pedido, remisión o folio del proveedor' AFTER fecha_solicitud,
  ADD COLUMN categoria_id TINYINT UNSIGNED DEFAULT NULL COMMENT 'Categoría de compra (categorias_proveedor)' AFTER referencia,
  ADD COLUMN contribuyente_id INT UNSIGNED DEFAULT NULL COMMENT 'Entidad fiscal a la que pertenece la compra' AFTER categoria_id,
  ADD COLUMN empresa_id INT UNSIGNED DEFAULT NULL COMMENT 'Negocio relacionado (NULL = general)' AFTER contribuyente_id,
  ADD COLUMN estado_cfdi ENUM('pendiente_cfdi','con_cfdi','no_facturada') NOT NULL DEFAULT 'pendiente_cfdi'
      COMMENT 'Documentación fiscal; NO prueba validez ante el SAT' AFTER total_esperado,
  ADD COLUMN iva DECIMAL(14,2) DEFAULT NULL COMMENT 'IVA capturado o leído del CFDI; NULL = no especificado' AFTER estado_cfdi,
  ADD COLUMN gasto_id INT UNSIGNED DEFAULT NULL COMMENT 'Único gasto contable de esta compra' AFTER iva,
  ADD UNIQUE KEY uq_oc_gasto (gasto_id),
  ADD KEY idx_oc_tipo (tipo),
  ADD KEY idx_oc_categoria (categoria_id),
  ADD KEY idx_oc_contribuyente (contribuyente_id),
  ADD CONSTRAINT fk_oc_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_proveedor (id) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_oc_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_oc_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_oc_gasto FOREIGN KEY (gasto_id) REFERENCES gastos (id) ON DELETE SET NULL ON UPDATE CASCADE;

UPDATE ordenes_compra SET contribuyente_id = (SELECT MIN(id) FROM contribuyentes WHERE activo = 1) WHERE contribuyente_id IS NULL;
ALTER TABLE ordenes_compra MODIFY COLUMN contribuyente_id INT UNSIGNED NOT NULL;

CREATE TABLE IF NOT EXISTS materiales (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(150) NOT NULL,
  unidad VARCHAR(20) NOT NULL DEFAULT 'pza',
  existencia DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Puede quedar negativa si se consume antes de registrar la compra',
  minimo DECIMAL(14,3) DEFAULT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_material_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Materias primas y materiales controlados en inventario; NO son productos para venta';

ALTER TABLE orden_compra_partidas
  MODIFY COLUMN producto_id INT UNSIGNED DEFAULT NULL COMMENT 'Producto comercial relacionado (opcional)',
  MODIFY COLUMN cantidad_solicitada DECIMAL(12,3) NOT NULL,
  ADD COLUMN material_id INT UNSIGNED DEFAULT NULL COMMENT 'Material inventariable (no es producto de venta)' AFTER producto_id,
  ADD COLUMN unidad VARCHAR(20) DEFAULT NULL AFTER descripcion,
  ADD COLUMN controla TINYINT(1) NOT NULL DEFAULT 1
      COMMENT '1 = lleva recepción e inventario; 0 = consumible/servicio sin existencias' AFTER unidad,
  ADD KEY fk_ocp_material (material_id),
  ADD CONSTRAINT fk_ocp_material FOREIGN KEY (material_id) REFERENCES materiales (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE recepcion_partidas
  MODIFY COLUMN producto_id INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN cantidad_recibida DECIMAL(12,3) NOT NULL,
  ADD COLUMN material_id INT UNSIGNED DEFAULT NULL AFTER producto_id,
  ADD COLUMN destino ENUM('stock','por_terminar','material') NOT NULL DEFAULT 'stock'
      COMMENT 'stock = existencia vendible (recepciones anteriores); por_terminar; material' AFTER material_id,
  ADD KEY fk_rp_material (material_id),
  ADD CONSTRAINT fk_rp_material FOREIGN KEY (material_id) REFERENCES materiales (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE productos
  ADD COLUMN stock_por_terminar INT NOT NULL DEFAULT 0
      COMMENT 'Semiterminados recibidos pendientes de terminar; NO cuentan como existencia vendible' AFTER stock;

CREATE TABLE IF NOT EXISTS movimientos_por_terminar (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrada','terminado','ajuste') NOT NULL COMMENT 'entrada = recepción; terminado = pasa a existencia vendible; ajuste = corrección',
  cantidad INT NOT NULL,
  saldo INT NOT NULL COMMENT 'Pendientes de terminar después del movimiento',
  recepcion_id INT UNSIGNED DEFAULT NULL,
  usuario_id INT UNSIGNED DEFAULT NULL,
  notas VARCHAR(255) DEFAULT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mpt_producto (producto_id),
  KEY idx_mpt_recepcion (recepcion_id),
  CONSTRAINT fk_mpt_producto FOREIGN KEY (producto_id) REFERENCES productos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_mpt_recepcion FOREIGN KEY (recepcion_id) REFERENCES recepciones (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_mpt_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movimientos_material (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  material_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrada','salida','ajuste') NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL COMMENT 'Siempre positiva; el signo lo define tipo',
  saldo DECIMAL(14,3) NOT NULL,
  recepcion_id INT UNSIGNED DEFAULT NULL,
  motivo VARCHAR(30) DEFAULT NULL COMMENT 'recepcion, consumo, ajuste, correccion',
  usuario_id INT UNSIGNED DEFAULT NULL,
  notas VARCHAR(255) DEFAULT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mm_material (material_id),
  KEY idx_mm_recepcion (recepcion_id),
  CONSTRAINT fk_mm_material FOREIGN KEY (material_id) REFERENCES materiales (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_mm_recepcion FOREIGN KEY (recepcion_id) REFERENCES recepciones (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_mm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pagos_compra (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  importe DECIMAL(14,2) NOT NULL,
  metodo VARCHAR(30) DEFAULT NULL,
  referencia VARCHAR(80) DEFAULT NULL,
  token CHAR(32) DEFAULT NULL COMMENT 'Un solo uso: reenviar el formulario no duplica el pago',
  estado ENUM('vigente','cancelado') NOT NULL DEFAULT 'vigente',
  motivo_cancelacion VARCHAR(255) DEFAULT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pago_compra_token (token),
  KEY idx_pago_compra_orden (orden_id),
  KEY idx_pago_compra_fecha (fecha),
  CONSTRAINT fk_pago_compra_orden FOREIGN KEY (orden_id) REFERENCES ordenes_compra (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pago_compra_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pagos a proveedores por compra; reducen el saldo y no generan gastos';

-- ===========================================================================
-- migracion_ventas_inventario.sql
-- Ventas, Inventario y Proveedores (sección 13)
--
-- Uso:
--   mysql -u root cotizaciones < mysql/migracion_ventas_inventario.sql
--
-- Antes de aplicarla genera un respaldo:
--   mysqldump -u root cotizaciones > respaldo.sql
--
-- ESTRUCTURA QUE AGREGA
--   productos.stock            existencia manual/automática por producto
--   movimientos_inventario     bitácora de cada subida o bajada de stock
--   ventas                     registro de entrega ligado a una cotización
--   proveedores                directorio de proveedores (datos fijos)
--   proveedor_entradas         reporte fotográfico de cada entrada
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- 1. PRODUCTOS — columna de existencias
--    El stock arranca en 0 y lo carga el administrador desde /admin/inventario.
--    Cada cotización marcada como "Vendido" lo descuenta automáticamente.
-- ---------------------------------------------------------------------------
ALTER TABLE productos
  ADD COLUMN stock INT NOT NULL DEFAULT 0
      COMMENT 'Existencias en almacén; puede quedar negativo si se vendió antes de cargarlo'
      AFTER precio;

-- ---------------------------------------------------------------------------
-- 2. MOVIMIENTOS_INVENTARIO — bitácora de cada cambio de existencias
--    Guarda el signo en "tipo" y la cantidad siempre en valor absoluto,
--    de modo que el historial sea legible y se pueda auditar.
--    "saldo" conserva el stock resultante en ese instante.
-- ---------------------------------------------------------------------------
CREATE TABLE movimientos_inventario (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_id   INT UNSIGNED NOT NULL,
  tipo          ENUM('entrada','salida','ajuste') NOT NULL
                COMMENT 'entrada = carga manual, salida = venta, ajuste = corrección',
  cantidad      INT NOT NULL COMMENT 'Siempre positiva; el signo lo define tipo',
  saldo         INT NOT NULL DEFAULT 0 COMMENT 'Existencias después del movimiento',
  cotizacion_id INT UNSIGNED DEFAULT NULL COMMENT 'Cotización que originó la salida',
  usuario_id    INT UNSIGNED DEFAULT NULL COMMENT 'Administrador que registró el movimiento',
  notas         VARCHAR(255) DEFAULT NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_movimiento_producto (producto_id),
  KEY idx_movimiento_cotizacion (cotizacion_id),
  KEY idx_movimiento_fecha (creado_en),
  CONSTRAINT fk_movimiento_producto
    FOREIGN KEY (producto_id)   REFERENCES productos (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_movimiento_cotizacion
    FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_movimiento_usuario
    FOREIGN KEY (usuario_id)    REFERENCES usuarios (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. VENTAS — registro de venta/entrega nacido de una cotización
--    Una sola venta por cotización (UNIQUE). "estado_previo" recuerda en qué
--    estado quedó la cotización antes de crear la venta: si se borra el
--    registro se devuelve a ese estado y el stock se revierte con él.
-- ---------------------------------------------------------------------------
CREATE TABLE ventas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cotizacion_id INT UNSIGNED NOT NULL COMMENT 'Llave foránea a la cotización origen',
  estado_previo ENUM('pendiente','vendido','cancelado') NOT NULL DEFAULT 'pendiente'
                COMMENT 'Estado de la cotización antes de crear la venta',
  fecha         DATE NOT NULL COMMENT 'Fecha de la entrega',
  ubicacion     VARCHAR(80) NOT NULL COMMENT 'Población de entrega',
  ubicacion_otra VARCHAR(120) DEFAULT NULL COMMENT 'Texto libre cuando ubicacion = Otro',
  importe       DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Importe cobrado (MXN)',
  foto          VARCHAR(150) DEFAULT NULL COMMENT 'Foto de entrega en public/image',
  notas         TEXT DEFAULT NULL COMMENT 'Notas adicionales de la venta',
  usuario_id    INT UNSIGNED NOT NULL COMMENT 'Admin que registró la venta',
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_venta_cotizacion (cotizacion_id),
  KEY idx_ventas_fecha (fecha),
  CONSTRAINT fk_venta_cotizacion
    FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_venta_usuario
    FOREIGN KEY (usuario_id)    REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. PROVEEDORES — directorio de quién nos surte
--    Los datos fiscales se capturan UNA VEZ y se reutilizan en cada entrada.
-- ---------------------------------------------------------------------------
CREATE TABLE proveedores (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(150) NOT NULL,
  rfc           VARCHAR(20)  DEFAULT NULL,
  telefono      VARCHAR(30)  DEFAULT NULL,
  ubicacion     VARCHAR(120) DEFAULT NULL,
  tipo_proveedor VARCHAR(40) NOT NULL DEFAULT 'Material'
                COMMENT 'Material, Producto, Servicio u Otro',
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_proveedores_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. PROVEEDOR_ENTRADAS — reporte fotográfico de cada entrega
--    Se borra en cascada con su proveedor: el aviso se muestra en el modal
--    de confirmación antes de eliminar.
-- ---------------------------------------------------------------------------
CREATE TABLE proveedor_entradas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proveedor_id  INT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL COMMENT 'Fecha de la entrega',
  numero_pedido VARCHAR(60)  DEFAULT NULL COMMENT 'Número de pedido o remisión',
  categoria     VARCHAR(80)  DEFAULT NULL COMMENT 'Categoría de los materiales',
  descripcion   TEXT         DEFAULT NULL COMMENT 'Descripción de lo recibido',
  foto          VARCHAR(150) DEFAULT NULL COMMENT 'Foto de la entrega en public/image',
  notas         TEXT         DEFAULT NULL COMMENT 'Notas adicionales',
  usuario_id    INT UNSIGNED NOT NULL COMMENT 'Admin que registró la entrada',
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_entrada_proveedor (proveedor_id),
  KEY idx_entrada_fecha (fecha),
  CONSTRAINT fk_entrada_proveedor
    FOREIGN KEY (proveedor_id) REFERENCES proveedores (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_entrada_usuario
    FOREIGN KEY (usuario_id)   REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

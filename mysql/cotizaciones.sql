-- ===========================================================================
-- cotizaciones.sql
-- Base de datos de la aplicación de cotizaciones (MySQL 8 / utf8mb4)
--
-- Uso:
--   mysql -u root -p < mysql/cotizaciones.sql
--
-- Variables de entorno esperadas por config/database.php:
--   DB_HOST=localhost  DB_USER=root  DB_PASSWORD=  DB_NAME=cotizaciones
--
-- ESTRUCTURA
--   usuarios            administradores que usan la aplicación (login)
--   empresas            los dos negocios que emiten cotizaciones
--   clientes            fichas de clientes (NO inician sesión)
--   productos           catálogo por negocio, con precio de lista e imagen
--   precios_cliente     precio especial recordado para un cliente puntual
--   cotizaciones        cabecera de cada cotización
--   cotizacion_detalles renglones (partidas) de cada cotización
--   notas               bitácora interna personal (Notas Personales)
--   movimientos_inventario bitácora de subidas y bajadas de stock
--   ventas              registro de venta/entrega ligado a una cotización
--   proveedores         directorio de proveedores
--   categorias_proveedor catálogo de categorías de compra (CRUD compartido)
--   proveedor_entradas  reporte fotográfico de cada entrada de material
--
-- Si vienes de una instalación anterior, en lugar de recrear la base aplica:
--   mysql -u root cotizaciones < mysql/migracion_ventas_inventario.sql
--   mysql -u root cotizaciones < mysql/migracion_entradas_monto.sql
--   mysql -u root cotizaciones < mysql/migracion_entradas_categoria.sql
--   mysql -u root cotizaciones < mysql/migracion_categorias_proveedor.sql
-- ===========================================================================

DROP DATABASE IF EXISTS cotizaciones;
CREATE DATABASE cotizaciones CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cotizaciones;

-- ---------------------------------------------------------------------------
-- 1. USUARIOS — administradores con acceso al panel
-- ---------------------------------------------------------------------------
CREATE TABLE usuarios (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(100) NOT NULL,
  email       VARCHAR(120) NOT NULL,
  contrasena  VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt (password_hash)',
  telefono    VARCHAR(20)  DEFAULT NULL,
  foto        VARCHAR(150) DEFAULT NULL COMMENT 'Foto de perfil redonda (archivo en public/image)',
  rol         TINYINT      NOT NULL DEFAULT 1 COMMENT '1 = administrador',
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. EMPRESAS — los dos negocios del proyecto
--    Los datos fiscales se completan desde el módulo "Empresas & Razón".
-- ---------------------------------------------------------------------------
CREATE TABLE empresas (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre             VARCHAR(150) NOT NULL,
  rfc                VARCHAR(20)  DEFAULT NULL,
  regimen            VARCHAR(60)  DEFAULT NULL COMMENT 'Régimen fiscal SAT',
  direccion          VARCHAR(255) DEFAULT NULL,
  ciudad             VARCHAR(100) DEFAULT NULL,
  estado             VARCHAR(100) DEFAULT NULL,
  cp                 VARCHAR(6)   DEFAULT NULL,
  telefono           VARCHAR(20)  DEFAULT NULL,
  email              VARCHAR(120) DEFAULT NULL,
  logo               VARCHAR(150) DEFAULT NULL COMMENT 'Archivo en public/image/',
  condiciones_envio   TEXT         DEFAULT NULL COMMENT 'Texto por defecto en las cotizaciones',
  condiciones_compra  TEXT         DEFAULT NULL COMMENT 'Texto por defecto en las cotizaciones',
  activa             TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_empresas_activa (activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. CLIENTES — fichas seleccionables en las cotizaciones (no inician sesión)
-- ---------------------------------------------------------------------------
CREATE TABLE clientes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(120) NOT NULL COMMENT 'Persona de contacto',
  nombre_empresa VARCHAR(150) DEFAULT NULL COMMENT 'Razón social o nombre del negocio',
  alias          VARCHAR(80)  DEFAULT NULL COMMENT 'Nombre corto para buscar rápido',
  rfc            VARCHAR(20)  DEFAULT NULL,
  telefono       VARCHAR(20)  DEFAULT NULL,
  email          VARCHAR(120) DEFAULT NULL,
  ubicacion      VARCHAR(255) DEFAULT NULL,
  foto           VARCHAR(150) DEFAULT NULL COMMENT 'Foto de perfil redonda (archivo en public/image)',
  vip            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Cliente frecuente',
  descuento_pct  DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Descuento global aplicado a sus cotizaciones',
  notas          TEXT         DEFAULT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_clientes_vip (vip),
  KEY idx_clientes_alias (alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. PRODUCTOS — catálogo de cada negocio
--    "precio" es el PRECIO DE LISTA: es fijo y de solo lectura en las
--    cotizaciones. Cualquier variante se guarda en precios_cliente.
-- ---------------------------------------------------------------------------
CREATE TABLE productos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id   INT UNSIGNED NOT NULL,
  nombre       VARCHAR(150) NOT NULL,
  sku          VARCHAR(40)  DEFAULT NULL,
  categoria    VARCHAR(80)  DEFAULT NULL,
  unidad       VARCHAR(20)  NOT NULL DEFAULT 'pza' COMMENT 'pza, juego, kg, m2… (aparece en la cotización)',
  descripcion  TEXT         DEFAULT NULL,
  precio       DECIMAL(12,2) NOT NULL COMMENT 'Precio de lista (MXN)',
  stock        INT          NOT NULL DEFAULT 0 COMMENT 'Existencias; carga manual en /admin/inventario y descuento automático por venta',
  imagen       VARCHAR(150) DEFAULT NULL COMMENT 'Archivo cuadrado en public/image/',
  activo       TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_productos_empresa (empresa_id),
  CONSTRAINT fk_productos_empresa
    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. PRECIOS_CLIENTE — precio especial recordado por cliente y producto
--    Se escribe sólo cuando el admin NO marca "No recordar este precio".
-- ---------------------------------------------------------------------------
CREATE TABLE precios_cliente (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id   INT UNSIGNED NOT NULL,
  producto_id  INT UNSIGNED NOT NULL,
  precio       DECIMAL(12,2) NOT NULL COMMENT 'Precio acordado (MXN)',
  creado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_precio_cliente_producto (cliente_id, producto_id),
  CONSTRAINT fk_precio_cliente
    FOREIGN KEY (cliente_id)  REFERENCES clientes (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_precio_producto
    FOREIGN KEY (producto_id) REFERENCES productos (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. COTIZACIONES — cabecera
-- ---------------------------------------------------------------------------
CREATE TABLE cotizaciones (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio               VARCHAR(20)  NOT NULL COMMENT 'Ej. FOL-2026-0001',
  empresa_id          INT UNSIGNED NOT NULL COMMENT 'Negocio emisor',
  cliente_id          INT UNSIGNED NOT NULL COMMENT 'Cliente destino',
  usuario_id          INT UNSIGNED NOT NULL COMMENT 'Admin que la creó',
  fecha               DATE         NOT NULL,
  vigencia_dias       TINYINT      NOT NULL DEFAULT 15,
  estado              ENUM('pendiente','vendido','cancelado') NOT NULL DEFAULT 'pendiente',
  subtotal            DECIMAL(14,2) NOT NULL DEFAULT 0,
  descuento           DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Descuento global aplicado',
  aplica_iva          TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'IVA 16% activo o no',
  iva                 DECIMAL(14,2) NOT NULL DEFAULT 0,
  total               DECIMAL(14,2) NOT NULL DEFAULT 0,
  condiciones_envio   TEXT         DEFAULT NULL COMMENT 'Indicaciones de entrega',
  condiciones_compra  TEXT         DEFAULT NULL COMMENT 'Indicaciones de compra',
  comentarios         TEXT         DEFAULT NULL COMMENT 'Comentarios adicionales',
  creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cotizacion_folio (folio),
  KEY idx_cotizaciones_cliente (cliente_id),
  KEY idx_cotizaciones_empresa (empresa_id),
  KEY idx_cotizaciones_fecha (fecha),
  CONSTRAINT fk_cotizacion_empresa
    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_cotizacion_cliente
    FOREIGN KEY (cliente_id) REFERENCES clientes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_cotizacion_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. COTIZACION_DETALLES — renglones (partidas)
--    "precio_unitario" guarda el precio EFECTIVAMENTE usado: el de lista o
--    el especial acordado con el cliente. Es el snapshot de esa cotización.
-- ---------------------------------------------------------------------------
CREATE TABLE cotizacion_detalles (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cotizacion_id   INT UNSIGNED NOT NULL,
  producto_id     INT UNSIGNED NOT NULL,
  cantidad        INT          NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(12,2) NOT NULL COMMENT 'Lista O precio especial',
  descuento_pct   DECIMAL(5,2)  NOT NULL DEFAULT 0 COMMENT 'Descuento del renglón',
  subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_detalle_cotizacion (cotizacion_id),
  CONSTRAINT fk_detalle_cotizacion
    FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_detalle_producto
    FOREIGN KEY (producto_id)   REFERENCES productos (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 8. NOTAS — sección única "Notas Personales" (bitácora de equipo)
--    UNA SOLA sección, compartida por todos los administradores: lo que
--    redacta uno lo ven y pueden gestionar los demás. usuario_id sólo
--    conserva el AUTOR de la nota (para trazabilidad), no filtra el acceso.
--    Por eso la FK es RESTRICT: borrar al autor no debe borrar notas de
--    todos ni permitirlo en silencio.
-- ---------------------------------------------------------------------------
CREATE TABLE notas (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id        INT UNSIGNED NOT NULL COMMENT 'Autor de la nota (bitácora compartida)',
  titulo            VARCHAR(200) NOT NULL,
  cuerpo            TEXT         NOT NULL,
  categoria         ENUM('recordatorio','cotizar','cambios','revisar','otro') NOT NULL DEFAULT 'otro',
  referencia        VARCHAR(255) DEFAULT NULL COMMENT 'Cliente, contacto o catálogo asociado',
  fecha_recordatorio VARCHAR(80)  DEFAULT NULL COMMENT 'Etiqueta de vencimiento mostrada en la tarjeta',
  fijada            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Fijada arriba del tablero',
  completada        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Hecha / archivada',
  creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notas_usuario (usuario_id),
  KEY idx_notas_categoria (categoria),
  KEY idx_notas_fijada (fijada),
  CONSTRAINT fk_nota_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 9. MOVIMIENTOS_INVENTARIO — bitácora del control de almacén
--    El stock (columna stock de productos) lo carga el administrador a mano y
--    lo descuenta el sistema cada vez que una cotización pasa a "Vendido".
--    Cada cambio deja rastro en la bitácora para poder auditar quién subió o
--    bajó las existencias.
-- ---------------------------------------------------------------------------
CREATE TABLE movimientos_inventario (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_id   INT UNSIGNED NOT NULL,
  tipo          ENUM('entrada','salida','ajuste') NOT NULL
                COMMENT 'entrada = carga manual, salida = venta, ajuste = corrección',
  cantidad      INT NOT NULL COMMENT 'Siempre positiva; el signo lo define tipo',
  saldo         INT NOT NULL DEFAULT 0 COMMENT 'Existencias después del movimiento',
  cotizacion_id INT UNSIGNED DEFAULT NULL COMMENT 'Cotización que originó la salida',
  venta_id      INT UNSIGNED DEFAULT NULL COMMENT 'Venta directa que originó el movimiento',
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
-- 10. VENTAS / VENTA_DETALLES — ventas directas (sin cotización)
--     Misma forma que cotizaciones: al registrarlas descuentan el inventario
--     (que puede quedar negativo). Ver mysql/migracion_ventas_directas.sql.
-- ---------------------------------------------------------------------------
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

-- La FK de movimientos_inventario.venta_id va aquí porque ventas se crea después.
ALTER TABLE movimientos_inventario
  ADD KEY idx_movimiento_venta (venta_id),
  ADD CONSTRAINT fk_movimiento_venta
    FOREIGN KEY (venta_id) REFERENCES ventas (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- ---------------------------------------------------------------------------
-- 11. CATEGORIAS_PROVEEDOR — catálogo de categorías de compra
--     Una sola tabla compartida por TODOS los proveedores: lo que se
--     clasifica es el tipo de compra (Acero, Empaque, Servicio…), y ese
--     concepto es de la empresa, no de quien surte.
--     NO hay clave foránea desde proveedor_entradas.categoria porque esa
--     columna admite el 0 ("Sin categoría") y el 0 no es una fila de aquí;
--     la protección contra borrados indebidos se hace en PHP (ver
--     CategoriasProveedor::eliminar()).
-- ---------------------------------------------------------------------------
CREATE TABLE categorias_proveedor (
  id        TINYINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '0 = sin categoría (no existe como fila)',
  nombre    VARCHAR(80) NOT NULL COMMENT 'Nombre visible de la categoría de compra',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categorias_proveedor_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 12. PROVEEDORES — directorio de quién nos surte
--     Los datos fiscales se capturan UNA VEZ y se reutilizan en cada entrada.
-- ---------------------------------------------------------------------------
CREATE TABLE proveedores (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(150) NOT NULL,
  rfc            VARCHAR(20)  DEFAULT NULL,
  telefono       VARCHAR(30)  DEFAULT NULL,
  ubicacion      VARCHAR(120) DEFAULT NULL,
  tipo_proveedor VARCHAR(40)  NOT NULL DEFAULT 'Material'
                 COMMENT 'Material, Producto, Servicio u Otro',
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_proveedores_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 13. PROVEEDOR_ENTRADAS — reporte fotográfico de cada entrega
--     Se borra en cascada con su proveedor: el aviso se muestra en el modal
--     de confirmación antes de eliminar.
-- ---------------------------------------------------------------------------
CREATE TABLE proveedor_entradas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proveedor_id  INT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL COMMENT 'Fecha de la entrega',
  numero_pedido VARCHAR(60)  DEFAULT NULL COMMENT 'Número de pedido o remisión',
  categoria     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Id en categorias_proveedor: 0 = sin categoría',
  monto         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Importe del pedido en pesos mexicanos (MXN)',
  pagado        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = pedido liquidado, 0 = pendiente de pago',
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

-- ===========================================================================
-- DATOS INICIALES
-- ===========================================================================

-- Los dos negocios. RFC, domicilio y régimen se completan desde el CRUD.
INSERT INTO empresas (nombre, rfc, regimen, direccion, ciudad, estado, cp, telefono, email, condiciones_envio, condiciones_compra) VALUES
('Soluciones en Acero', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
 'Los tiempos de entrega se confirman al momento de recibir la orden de compra.',
 'Los precios están expresados en pesos mexicanos (MXN) y pueden modificarse sin previo aviso.'),
('Canastillas de la Baja', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
 'La entrega se coordina con el cliente de acuerdo a la disponibilidad de inventario.',
 'Los precios están expresados en pesos mexicanos (MXN) y pueden modificarse sin previo aviso.');

-- Usuario administrador inicial.
-- Sin contraseña: el instalador la asigna (ver GUIA.md, sección 2 "Primer usuario administrador").
INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES
('Administrador', 'admin@cotizaciones.local',
 '',
 '6120000000', 1);

-- Catálogo de categorías de compra. Los ids van EXPLÍCITOS porque
-- proveedor_entradas.categoria guarda ese número: cambiarlos reescribiría
-- el significado de las entradas ya capturadas.
INSERT INTO categorias_proveedor (id, nombre) VALUES
(1, 'Acero'),
(2, 'Canastillas'),
(3, 'Empaque'),
(4, 'Mano de obra'),
(5, 'Servicio'),
(6, 'Otro');

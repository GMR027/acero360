-- ===========================================================================
-- 006_contabilidad.sql — Etapa 5: contabilidad, documentos y archivo mensual
--
--   periodos_contables   candado por contribuyente/año/mes (cierre en la Etapa 6)
--   ingresos             un registro contable por venta, generado automáticamente
--   cobros               pagos recibidos de cada ingreso (parciales o totales)
--   categorias_gasto, gastos, gasto_reparto   egresos (un gasto compartido se cuenta UNA vez)
--   documentos           PDF / imágenes / XML ligados a un ingreso o a un gasto
--
-- Nada contable se borra físicamente: se cancela con motivo y queda en auditoría.
-- ===========================================================================

CREATE TABLE periodos_contables (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id INT UNSIGNED NOT NULL,
  anio             SMALLINT UNSIGNED NOT NULL,
  mes              TINYINT UNSIGNED NOT NULL,
  estado           ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
  cerrado_en       DATETIME DEFAULT NULL,
  cerrado_por      INT UNSIGNED DEFAULT NULL,
  resumen          JSON DEFAULT NULL COMMENT 'Fotografía del cierre (Etapa 6)',
  PRIMARY KEY (id),
  UNIQUE KEY uq_periodo (contribuyente_id, anio, mes),
  CONSTRAINT fk_periodo_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ingresos (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id          INT UNSIGNED DEFAULT NULL COMMENT 'Venta de origen (única)',
  contribuyente_id  INT UNSIGNED NOT NULL,
  empresa_id        INT UNSIGNED NOT NULL COMMENT 'Negocio de origen',
  cliente_id        INT UNSIGNED NOT NULL,
  fecha             DATE         NOT NULL COMMENT 'Fecha de operación',
  folio             VARCHAR(20)  NOT NULL COMMENT 'Folio de la venta',
  concepto          VARCHAR(255) NOT NULL,
  subtotal          DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Antes de descuentos e impuestos',
  descuento         DECIMAL(14,2) NOT NULL DEFAULT 0,
  iva               DECIMAL(14,2) NOT NULL DEFAULT 0,
  otros_impuestos   DECIMAL(14,2) NOT NULL DEFAULT 0,
  total             DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado            ENUM('vigente','cancelado') NOT NULL DEFAULT 'vigente',
  estado_facturacion ENUM('sin_clasificar','facturada','no_facturada') NOT NULL DEFAULT 'sin_clasificar'
                    COMMENT 'Dato explícito: una venta sin factura adjunta NO es automáticamente «no facturada»',
  factura_folio     VARCHAR(40)  DEFAULT NULL,
  uuid_fiscal       CHAR(36)     DEFAULT NULL,
  fecha_facturacion DATE         DEFAULT NULL,
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ingreso_venta (venta_id),
  KEY idx_ingreso_fecha (contribuyente_id, fecha),
  KEY idx_ingreso_empresa (empresa_id),
  KEY idx_ingreso_uuid (uuid_fiscal),
  CONSTRAINT fk_ingreso_venta FOREIGN KEY (venta_id) REFERENCES ventas (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ingreso_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ingreso_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ingreso_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cobros (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ingreso_id          INT UNSIGNED NOT NULL,
  fecha               DATE NOT NULL,
  importe             DECIMAL(14,2) NOT NULL,
  metodo              VARCHAR(30)  DEFAULT NULL,
  referencia          VARCHAR(80)  DEFAULT NULL,
  estado              ENUM('vigente','cancelado') NOT NULL DEFAULT 'vigente',
  motivo_cancelacion  VARCHAR(255) DEFAULT NULL,
  usuario_id          INT UNSIGNED NOT NULL,
  creado_en           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cobro_ingreso (ingreso_id),
  KEY idx_cobro_fecha (fecha),
  CONSTRAINT fk_cobro_ingreso FOREIGN KEY (ingreso_id) REFERENCES ingresos (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_cobro_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias_gasto (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(80) NOT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categoria_gasto (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categorias_gasto (nombre) VALUES
  ('Material y acero'), ('Insumos de taller'), ('Servicios'), ('Renta'), ('Combustible y transporte'),
  ('Mantenimiento'), ('Honorarios'), ('Impuestos y derechos'), ('Sueldos'), ('Otros');

CREATE TABLE gastos (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id   INT UNSIGNED NOT NULL,
  fecha              DATE NOT NULL,
  proveedor_id       INT UNSIGNED DEFAULT NULL,
  beneficiario       VARCHAR(150) NOT NULL COMMENT 'Proveedor o beneficiario (texto libre si no está en el directorio)',
  concepto           VARCHAR(255) NOT NULL,
  categoria_id       INT UNSIGNED DEFAULT NULL,
  naturaleza         ENUM('gasto','compra','insumo','servicio','activo','impuesto','otro') NOT NULL DEFAULT 'gasto',
  empresa_id         INT UNSIGNED DEFAULT NULL COMMENT 'Negocio relacionado (NULL si es compartido o general)',
  compartido         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se registra UNA vez; el reparto no cambia el total',
  subtotal           DECIMAL(14,2) NOT NULL,
  iva_tasa           DECIMAL(5,2)  NOT NULL DEFAULT 16.00,
  iva                DECIMAL(14,2) NOT NULL DEFAULT 0,
  total              DECIMAL(14,2) NOT NULL,
  estado_pago        ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
  fecha_pago         DATE DEFAULT NULL,
  metodo_pago        VARCHAR(30) DEFAULT NULL,
  comprobante_fiscal TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Indicador: tiene CFDI/comprobante fiscal',
  uuid_fiscal        CHAR(36) DEFAULT NULL,
  revision_fiscal    ENUM('por_validar','validado','no_deducible') NOT NULL DEFAULT 'por_validar'
                     COMMENT 'Deducibilidad/acreditamiento: nunca se asume por tener un adjunto',
  observaciones      TEXT DEFAULT NULL,
  estado             ENUM('vigente','cancelado') NOT NULL DEFAULT 'vigente',
  motivo_cancelacion VARCHAR(255) DEFAULT NULL,
  cancelado_en       DATETIME DEFAULT NULL,
  cancelado_por      INT UNSIGNED DEFAULT NULL,
  usuario_id         INT UNSIGNED NOT NULL,
  creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gasto_fecha (contribuyente_id, fecha),
  KEY idx_gasto_empresa (empresa_id),
  KEY idx_gasto_uuid (uuid_fiscal),
  CONSTRAINT fk_gasto_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_gasto_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_gasto_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_gasto (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_gasto_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_gasto_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gasto_reparto (
  gasto_id    INT UNSIGNED NOT NULL,
  empresa_id  INT UNSIGNED NOT NULL,
  porcentaje  DECIMAL(5,2) NOT NULL,
  PRIMARY KEY (gasto_id, empresa_id),
  CONSTRAINT fk_reparto_gasto FOREIGN KEY (gasto_id) REFERENCES gastos (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_reparto_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documentos (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id  INT UNSIGNED NOT NULL,
  entidad           ENUM('ingreso','gasto') NOT NULL,
  entidad_id        INT UNSIGNED NOT NULL,
  tipo              ENUM('factura_pdf','xml','comprobante_pago','ticket','imagen','otro') NOT NULL DEFAULT 'otro',
  nombre_original   VARCHAR(255) NOT NULL,
  archivo           VARCHAR(160) NOT NULL COMMENT 'Ruta relativa dentro del almacén (nombre generado por el sistema)',
  mime              VARCHAR(80)  NOT NULL,
  tamano            INT UNSIGNED NOT NULL,
  sha256            CHAR(64)     NOT NULL,
  uuid_fiscal       CHAR(36)     DEFAULT NULL COMMENT 'Solo si se leyó de un CFDI XML',
  estado_validacion ENUM('no_aplica','cfdi_con_timbre','cfdi_sin_timbre','xml_no_cfdi') NOT NULL DEFAULT 'no_aplica'
                    COMMENT 'Revisión documental; NO es una verificación ante el SAT',
  datos_cfdi        JSON DEFAULT NULL,
  activo            TINYINT(1) NOT NULL DEFAULT 1,
  reemplaza_id      INT UNSIGNED DEFAULT NULL,
  motivo_baja       VARCHAR(255) DEFAULT NULL,
  baja_en           DATETIME DEFAULT NULL,
  baja_por          INT UNSIGNED DEFAULT NULL,
  subido_por        INT UNSIGNED NOT NULL,
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_doc_entidad (entidad, entidad_id),
  KEY idx_doc_uuid (uuid_fiscal),
  KEY idx_doc_sha (entidad, entidad_id, sha256),
  CONSTRAINT fk_doc_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_doc_usuario FOREIGN KEY (subido_por) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Respaldo de datos: cada venta ya registrada recibe su ingreso.
INSERT INTO ingresos
  (venta_id, contribuyente_id, empresa_id, cliente_id, fecha, folio, concepto,
   subtotal, descuento, iva, total, estado)
SELECT v.id, COALESCE(e.contribuyente_id, (SELECT MIN(id) FROM contribuyentes)), v.empresa_id, v.cliente_id,
       v.fecha, v.folio, CONCAT('Venta ', v.folio),
       v.subtotal, v.descuento, v.iva, v.total,
       IF(v.estado = 'registrada', 'vigente', 'cancelado')
  FROM ventas v JOIN empresas e ON e.id = v.empresa_id
 WHERE NOT EXISTS (SELECT 1 FROM ingresos i WHERE i.venta_id = v.id);

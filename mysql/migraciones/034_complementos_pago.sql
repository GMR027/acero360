-- ===========================================================================
-- 034_complementos_pago.sql — Complementos de pago (CFDI tipo «P») recibidos
-- de proveedores, vinculados a las facturas originales y a los pagos ya
-- registrados en pagos_compra.
--
--   complementos_pago    el complemento en sí: UUID único (no se carga dos veces),
--                        fecha de pago, total y quién lo registró.
--   complemento_doctos   cada factura relacionada (DoctoRelacionado): UUID de la
--                        factura, parcialidad, saldo anterior e importe pagado,
--                        resuelta contra su compra/gasto y con su diferencia.
--   complemento_pagos    vínculo N:M con los pagos de pagos_compra que el
--                        complemento respalda: así NUNCA se registra un segundo
--                        pago por un movimiento que ya existe.
--
-- El destino «complemento» entra al ENUM de lecturas_documentales (se carga con
-- el mismo flujo de lectura de las fases 2 y 3) y «complemento» como entidad
-- confirmada de una lectura.
-- ===========================================================================
ALTER TABLE lecturas_documentales
  MODIFY destino ENUM('gasto','venta','compra','complemento') NOT NULL,
  MODIFY entidad ENUM('ingreso','gasto','orden_compra','complemento') DEFAULT NULL;

CREATE TABLE complementos_pago (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id   INT UNSIGNED NOT NULL,
  lectura_id         INT UNSIGNED DEFAULT NULL COMMENT 'Lectura documental que lo originó',
  uuid               CHAR(36)     NOT NULL COMMENT 'UUID del complemento (timbre)',
  folio_comercial    VARCHAR(40)  DEFAULT NULL COMMENT 'Serie+Folio del complemento',
  fecha_pago         DATE         NOT NULL COMMENT 'FechaPago del complemento',
  total              DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  moneda             CHAR(3)      NOT NULL DEFAULT 'MXN',
  emisor_rfc         VARCHAR(13)  NOT NULL,
  receptor_rfc       VARCHAR(13)  NOT NULL,
  estado             ENUM('vigente','cancelado') NOT NULL DEFAULT 'vigente',
  motivo_cancelacion VARCHAR(255) DEFAULT NULL,
  usuario_id         INT UNSIGNED NOT NULL,
  creado_en          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_complemento_uuid (uuid),
  KEY idx_complemento_estado (estado, fecha_pago),
  KEY idx_complemento_lectura (lectura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE complemento_doctos (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  complemento_id    INT UNSIGNED NOT NULL,
  documento_uuid    CHAR(36)     NOT NULL COMMENT 'UUID de la factura original relacionada',
  serie             VARCHAR(10)  DEFAULT NULL,
  folio             VARCHAR(20)  DEFAULT NULL,
  moneda_dr         CHAR(3)      NOT NULL DEFAULT 'MXN',
  num_parcialidad   VARCHAR(4)   DEFAULT NULL,
  saldo_anterior    DECIMAL(14,2) DEFAULT NULL,
  importe_pagado    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  orden_id          INT UNSIGNED DEFAULT NULL COMMENT 'Compra resuelta por el UUID de la factura',
  gasto_id          INT UNSIGNED DEFAULT NULL COMMENT 'Gasto contable de esa compra',
  estado            ENUM('pendiente','vinculado','sin_factura','sin_pago','discrepancia')
                    NOT NULL DEFAULT 'pendiente',
  diferencia        DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Importe pagado − pagos ya registrados',
  datos             JSON DEFAULT NULL COMMENT 'Atributos crudos del DoctoRelacionado',
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_docto_complemento (complemento_id),
  KEY idx_docto_uuid (documento_uuid),
  KEY idx_docto_orden (orden_id),
  CONSTRAINT fk_docto_complemento FOREIGN KEY (complemento_id) REFERENCES complementos_pago (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_docto_orden FOREIGN KEY (orden_id) REFERENCES ordenes_compra (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_docto_gasto FOREIGN KEY (gasto_id) REFERENCES gastos (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE complemento_pagos (
  complemento_docto_id INT UNSIGNED NOT NULL,
  pago_id              INT UNSIGNED NOT NULL,
  PRIMARY KEY (complemento_docto_id, pago_id),
  KEY idx_cp_pago (pago_id),
  CONSTRAINT fk_cp_docto FOREIGN KEY (complemento_docto_id) REFERENCES complemento_doctos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cp_pago FOREIGN KEY (pago_id) REFERENCES pagos_compra (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

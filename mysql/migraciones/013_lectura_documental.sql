-- ===========================================================================
-- 013_lectura_documental.sql — lectura inteligente de facturas y comprobantes
--   lecturas_documentales   archivo original + datos leídos + datos confirmados (quién y cuándo)
--   gastos.*                folio comercial (≠ UUID), moneda, forma/método de pago SAT, RFC de la factura
-- El folio comercial (Serie+Folio) y el UUID fiscal son datos distintos y se guardan por separado.
-- ===========================================================================
CREATE TABLE lecturas_documentales (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id  INT UNSIGNED NOT NULL,
  destino           ENUM('gasto','venta') NOT NULL,
  nombre_original   VARCHAR(255) NOT NULL,
  archivo           VARCHAR(160) NOT NULL COMMENT 'Ruta relativa en el almacén (carpeta lecturas/)',
  mime              VARCHAR(80)  NOT NULL,
  sha256            CHAR(64)     NOT NULL,
  fuente            ENUM('xml','pdf_texto','ocr','manual') NOT NULL,
  datos_leidos      JSON DEFAULT NULL COMMENT 'Campos extraídos con su confianza',
  avisos            JSON DEFAULT NULL,
  datos_confirmados JSON DEFAULT NULL COMMENT 'Lo que la persona dejó tras revisar',
  estado            ENUM('pendiente','confirmada','descartada') NOT NULL DEFAULT 'pendiente',
  entidad           ENUM('ingreso','gasto') DEFAULT NULL,
  entidad_id        INT UNSIGNED DEFAULT NULL,
  creado_por        INT UNSIGNED NOT NULL,
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmado_por    INT UNSIGNED DEFAULT NULL,
  confirmado_en     DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_lectura_estado (estado, creado_en),
  KEY idx_lectura_sha (sha256),
  CONSTRAINT fk_lectura_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE gastos
  ADD COLUMN folio_comercial  VARCHAR(40) DEFAULT NULL COMMENT 'Serie+Folio de la factura; NO es el UUID' AFTER uuid_fiscal,
  ADD COLUMN moneda           CHAR(3) NOT NULL DEFAULT 'MXN' AFTER folio_comercial,
  ADD COLUMN forma_pago_sat   VARCHAR(3) DEFAULT NULL COMMENT 'Clave SAT (01, 03, 99…)' AFTER moneda,
  ADD COLUMN metodo_pago_sat  CHAR(3) DEFAULT NULL COMMENT 'PUE o PPD' AFTER forma_pago_sat,
  ADD COLUMN rfc_emisor       VARCHAR(13) DEFAULT NULL AFTER metodo_pago_sat,
  ADD COLUMN rfc_receptor     VARCHAR(13) DEFAULT NULL AFTER rfc_emisor,
  ADD COLUMN lectura_id       INT UNSIGNED DEFAULT NULL COMMENT 'Lectura documental que lo originó' AFTER rfc_receptor;

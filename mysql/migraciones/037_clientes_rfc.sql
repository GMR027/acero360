-- ===========================================================================
-- 037_clientes_rfc.sql — RFCs de facturación por cliente (FASE 9).
--
-- Un cliente puede tener varios RFC (p. ej. su persona física y su empresa).
-- `clientes.rfc` sigue siendo el RFC ACTIVO con el que se factura (compatibilidad
-- con todo lo existente); esta tabla registra TODOS los RFC conocidos del
-- cliente, cada uno con su razón social y sus datos fiscales (CP, régimen y uso
-- de CFDI), para que la facturación y la venta inteligente los reconozcan.
-- ===========================================================================

CREATE TABLE clientes_rfc (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id     INT UNSIGNED NOT NULL,
  rfc            VARCHAR(20)  NOT NULL,
  razon_social   VARCHAR(150) DEFAULT NULL,
  codigo_postal  CHAR(5)      DEFAULT NULL,
  regimen_fiscal VARCHAR(10)  DEFAULT NULL,
  uso_cfdi       CHAR(4)      DEFAULT NULL,
  es_principal   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'RFC activo para facturar (coincide con clientes.rfc)',
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clientes_rfc_rfc (rfc),
  KEY idx_clientes_rfc_cliente (cliente_id),
  CONSTRAINT fk_clientes_rfc_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los RFC que ya tienen ficha quedan registrados como principal. Uno por RFC:
-- si hubiera duplicados históricos (la validación que lo impide es posterior),
-- se queda el cliente más antiguo.
INSERT INTO clientes_rfc (cliente_id, rfc, razon_social, codigo_postal, regimen_fiscal, uso_cfdi, es_principal)
SELECT c.id,
       UPPER(REPLACE(REPLACE(TRIM(c.rfc), ' ', ''), '-', '')),
       NULLIF(TRIM(COALESCE(NULLIF(c.nombre_empresa, ''), c.nombre)), ''),
       NULLIF(TRIM(COALESCE(c.codigo_postal, '')), ''),
       NULLIF(TRIM(COALESCE(c.regimen_fiscal, '')), ''),
       NULLIF(TRIM(COALESCE(c.uso_cfdi, '')), ''),
       1
  FROM clientes c
  JOIN (SELECT MIN(id) AS id
          FROM clientes
         WHERE COALESCE(TRIM(rfc), '') <> ''
         GROUP BY UPPER(REPLACE(REPLACE(TRIM(rfc), ' ', ''), '-', ''))) u ON u.id = c.id;

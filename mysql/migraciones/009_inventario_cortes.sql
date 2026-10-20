-- ===========================================================================
-- 009_inventario_cortes.sql — fotografía mensual del inventario
-- Una fotografía (corte) guarda las existencias de cada producto en un momento dado para compararlas
-- después con las actuales. Generar o consultar una fotografía NUNCA modifica el inventario.
-- ===========================================================================
CREATE TABLE inventario_cortes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  anio          SMALLINT UNSIGNED NOT NULL,
  mes           TINYINT UNSIGNED NOT NULL,
  generado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Cuándo se tomó (si es posterior al cierre del mes, se indica)',
  generado_por  INT UNSIGNED DEFAULT NULL COMMENT 'NULL = automática (script)',
  productos     INT UNSIGNED NOT NULL DEFAULT 0,
  piezas        BIGINT NOT NULL DEFAULT 0,
  valor_costo   DECIMAL(16,2) NOT NULL DEFAULT 0,
  valor_precio  DECIMAL(16,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_corte_periodo (anio, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventario_corte_detalles (
  corte_id     INT UNSIGNED NOT NULL,
  producto_id  INT UNSIGNED NOT NULL,
  sku          VARCHAR(40)  DEFAULT NULL,
  nombre       VARCHAR(150) NOT NULL,
  stock        INT NOT NULL,
  costo_actual DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio       DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (corte_id, producto_id),
  CONSTRAINT fk_corte_detalle FOREIGN KEY (corte_id) REFERENCES inventario_cortes (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

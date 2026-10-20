-- ===========================================================================
-- 010_resumen_fiscal.sql — resumen anual de impuestos (estimación simple) y tipos de gasto
--   impuesto_real_mensual  lo que el contador declaró de verdad cada mes (para comparar con la estimación)
--   configuracion          tasa de ISR de la estimación simple (editable en Fiscal → Datos fiscales)
--   categorias_gasto       tipos de gasto de uso común (solo se agregan los que no existan)
-- ===========================================================================
CREATE TABLE impuesto_real_mensual (
  contribuyente_id INT UNSIGNED NOT NULL,
  anio             SMALLINT UNSIGNED NOT NULL,
  mes              TINYINT UNSIGNED NOT NULL,
  importe          DECIMAL(14,2) NOT NULL COMMENT 'IVA + ISR declarado; negativo = a favor',
  actualizado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (contribuyente_id, anio, mes),
  CONSTRAINT fk_real_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion (clave, valor) VALUES ('isr_tasa_simple', '32');

INSERT IGNORE INTO categorias_gasto (nombre, activa) VALUES
  ('Luz, agua y gas', 1),
  ('Teléfono e internet', 1),
  ('Ferretería y materiales', 1),
  ('Papelería y oficina', 1),
  ('Alimentos y despensa', 1),
  ('Seguros', 1),
  ('Donativos', 1),
  ('Retiros del propietario', 1);

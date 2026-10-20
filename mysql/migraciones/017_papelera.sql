-- ===========================================================================
-- 017_papelera.sql — papelera de ventas y cotizaciones eliminadas
--   papelera   copia completa (JSON) de lo eliminado; se puede restaurar durante 90 días
--              y después se borra definitivamente (scripts/purgar_papelera.php, cron diario).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS papelera (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo           ENUM('venta','cotizacion') NOT NULL,
  referencia_id  INT UNSIGNED NOT NULL COMMENT 'id que tenía el registro original',
  etiqueta       VARCHAR(160) NOT NULL COMMENT 'Folio y cliente, para reconocerlo en la lista',
  total          DECIMAL(14,2) DEFAULT NULL,
  datos          LONGTEXT NOT NULL COMMENT 'JSON: encabezado y renglones originales',
  eliminado_por  INT UNSIGNED DEFAULT NULL,
  eliminado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vence_en       DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_papelera_tipo (tipo, eliminado_en),
  KEY idx_papelera_vence (vence_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

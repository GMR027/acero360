-- Versiones de SheetFlow y enlaces públicos de solo lectura.
CREATE TABLE IF NOT EXISTS sheetflow_versiones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hoja_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(150) NOT NULL DEFAULT '',
    datos LONGTEXT NOT NULL,
    creado_por INT DEFAULT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hoja_fecha (hoja_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sheetflow_hojas ADD COLUMN token_publico CHAR(32) NULL UNIQUE;

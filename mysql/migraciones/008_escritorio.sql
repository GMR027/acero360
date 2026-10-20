-- ===========================================================================
-- 008_escritorio.sql — Etapa 9: versiones publicadas de la aplicación de escritorio (.deb)
-- Los archivos viven en <almacén de documentos>/escritorio (fuera de public/); aquí solo el registro.
-- ===========================================================================
CREATE TABLE paquetes_escritorio (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version        VARCHAR(30)  NOT NULL,
  arquitectura   VARCHAR(20)  NOT NULL,
  archivo        VARCHAR(160) NOT NULL COMMENT 'Ruta relativa dentro del almacén',
  nombre_archivo VARCHAR(160) NOT NULL COMMENT 'Nombre con el que se descarga',
  tamano         BIGINT UNSIGNED NOT NULL,
  sha256         CHAR(64)     NOT NULL,
  notas          VARCHAR(500) DEFAULT NULL,
  estado         ENUM('publicada','retirada') NOT NULL DEFAULT 'publicada',
  motivo_retiro  VARCHAR(255) DEFAULT NULL,
  publicada_por  INT UNSIGNED DEFAULT NULL,
  publicada_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retirada_por   INT UNSIGNED DEFAULT NULL,
  retirada_en    DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_paquete_version (version, arquitectura),
  KEY idx_paquete_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

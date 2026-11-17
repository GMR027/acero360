-- ===========================================================================
-- 050_android.sql — Aplicación Android (Tauri 2): versiones publicadas del APK
-- y dispositivos que la usan.
--
-- • paquetes_android: igual que paquetes_escritorio. El APK vive en
--   <almacén de documentos>/android (fuera de public/); aquí solo el registro.
--   version_code es el número entero que Android usa para decidir si una
--   instalación es más nueva (debe crecer en cada publicación).
-- • dispositivos_android: lo que la app reporta al entrar (versión instalada,
--   modelo y versión de Android tomados del navegador). Sirve para ver qué
--   teléfonos siguen con una versión vieja. No guarda ubicación ni datos
--   personales más allá del usuario que inició sesión.
-- ===========================================================================
CREATE TABLE paquetes_android (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version        VARCHAR(30)  NOT NULL,
  version_code   INT UNSIGNED NOT NULL,
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
  UNIQUE KEY uq_android_version (version),
  KEY idx_android_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dispositivos_android (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  instalacion      CHAR(36)     NOT NULL COMMENT 'Identificador aleatorio que genera la app al instalarse',
  usuario_id       INT UNSIGNED DEFAULT NULL,
  version_app      VARCHAR(30)  NOT NULL,
  modelo           VARCHAR(80)  DEFAULT NULL,
  version_android  VARCHAR(20)  DEFAULT NULL,
  primera_vez      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultima_vez       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dispositivo_instalacion (instalacion),
  KEY idx_dispositivo_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

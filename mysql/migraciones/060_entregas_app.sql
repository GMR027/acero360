-- ===========================================================================
-- 060_entregas_app.sql — App Android «Acero Entregas»
--
-- La app es la parte móvil del módulo de entregas de la misma plataforma:
-- usa los mismos usuarios, las mismas ventas y las mismas reglas. Aquí solo
-- se agrega lo necesario para autenticar la app, registrar evidencias y
-- conservar visitas fallidas.
--
--   tokens_entregas     sesiones de la app (token hasheado, dispositivo, expiración)
--   entregas            nombre de quien recibe, origen y firma opcional
--   entrega_incidencias token de idempotencia y origen
--   intentos_entrega    visitas en las que NO se pudo entregar
--   intento_fotos       fotos de una visita fallida (con EXIF leído antes de comprimir)
--   paquetes_android    separa el APK de Entregas del APK de Acero 360
-- ===========================================================================

CREATE TABLE tokens_entregas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id    INT UNSIGNED NOT NULL,
  token_hash    CHAR(64)     NOT NULL COMMENT 'SHA-256 del token; el token en claro nunca se guarda',
  huella        CHAR(64)     NOT NULL COMMENT 'SHA-256 del hash de contraseña para invalidar al cambiarla',
  dispositivo   VARCHAR(120) DEFAULT NULL COMMENT 'Nombre o modelo del teléfono',
  device_id     VARCHAR(80)  DEFAULT NULL COMMENT 'Identificador aleatorio de la instalación',
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso    DATETIME     DEFAULT NULL,
  expira_en     DATETIME     NOT NULL,
  revocado_en   DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_entrega_hash (token_hash),
  KEY idx_token_entrega_usuario (usuario_id),
  KEY idx_token_entrega_expira (expira_en),
  CONSTRAINT fk_token_entrega_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE entregas
  ADD COLUMN recibio_nombre VARCHAR(120) DEFAULT NULL
      COMMENT 'Nombre de quien recibió; opcional' AFTER notas,
  ADD COLUMN firma_archivo VARCHAR(150) DEFAULT NULL
      COMMENT 'Firma del cliente en public/image; opcional' AFTER recibio_nombre,
  ADD COLUMN origen ENUM('web','app') NOT NULL DEFAULT 'web' AFTER usuario_id;

ALTER TABLE entrega_incidencias
  ADD COLUMN token CHAR(32) DEFAULT NULL
      COMMENT 'Token de un solo uso de la app para no duplicar al reintentar' AFTER entrega_id,
  ADD COLUMN origen ENUM('web','app') NOT NULL DEFAULT 'web' AFTER usuario_id,
  ADD UNIQUE KEY uq_incidencia_token (token);

CREATE TABLE intentos_entrega (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id       INT UNSIGNED NOT NULL,
  fecha          DATETIME     NOT NULL,
  motivo         ENUM('cliente_ausente','direccion_incorrecta','rechazo_cliente','sin_acceso','otro') NOT NULL,
  notas          VARCHAR(500) DEFAULT NULL,
  ubicacion_maps VARCHAR(500) DEFAULT NULL,
  ubicacion_lat  DECIMAL(10,7) DEFAULT NULL,
  ubicacion_lon  DECIMAL(10,7) DEFAULT NULL,
  token          CHAR(32)     NOT NULL COMMENT 'Token de un solo uso de la app',
  usuario_id     INT UNSIGNED NOT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_intento_token (token),
  KEY idx_intento_venta (venta_id),
  KEY idx_intento_fecha (fecha),
  CONSTRAINT fk_intento_venta FOREIGN KEY (venta_id) REFERENCES ventas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_intento_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE intento_fotos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  intento_id INT UNSIGNED NOT NULL,
  archivo    VARCHAR(150) NOT NULL COMMENT 'Nombre del archivo en public/image',
  tomada_en  DATETIME     DEFAULT NULL COMMENT 'Fecha y hora de captura (EXIF o app)',
  latitud    DECIMAL(10,7) DEFAULT NULL COMMENT 'Latitud GPS en grados decimales',
  longitud   DECIMAL(10,7) DEFAULT NULL COMMENT 'Longitud GPS en grados decimales',
  usuario_id INT UNSIGNED NOT NULL,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_intento_foto_intento (intento_id),
  CONSTRAINT fk_intento_foto_intento FOREIGN KEY (intento_id) REFERENCES intentos_entrega (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_intento_foto_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE paquetes_android
  ADD COLUMN aplicacion ENUM('acero360','entregas') NOT NULL DEFAULT 'acero360' AFTER id,
  DROP INDEX uq_android_version,
  ADD UNIQUE KEY uq_android_app_version (aplicacion, version);

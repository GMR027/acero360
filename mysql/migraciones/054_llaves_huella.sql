-- ===========================================================================
-- 054_llaves_huella.sql — Inicio de sesión con huella en la app Android.
--
-- La sesión de PHP se pierde cuando Android cierra la app (su cookie no tiene
-- caducidad). Con el desbloqueo con huella activo, el servidor entrega a esa
-- instalación una llave aleatoria; la app la guarda en su almacén privado y,
-- tras confirmar la huella, la cambia por una sesión nueva.
--
-- • Solo se guarda el SHA-256 de la llave, nunca la llave.
-- • Va ligada a la instalación (cookie acero360_android) y a la contraseña
--   vigente: si la contraseña cambia, la llave deja de servir.
-- • Caduca a los 90 días sin uso; se revoca al quitar la huella en la app.
-- ===========================================================================

CREATE TABLE llaves_huella_android (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id        INT UNSIGNED NOT NULL,
  instalacion       CHAR(36)     NOT NULL COMMENT 'Id de instalación de la app (cookie acero360_android)',
  llave_hash        CHAR(64)     NOT NULL COMMENT 'SHA-256 de la llave que guarda la app',
  firma_contrasena  CHAR(64)     NOT NULL COMMENT 'SHA-256 del hash de la contraseña al emitirla',
  creada_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usada_en          DATETIME     DEFAULT NULL,
  expira_en         DATETIME     NOT NULL,
  revocada_en       DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_llave_huella (llave_hash),
  KEY idx_llave_huella_instalacion (instalacion, usuario_id),
  CONSTRAINT fk_llave_huella_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

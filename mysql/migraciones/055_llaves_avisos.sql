-- ===========================================================================
-- 055_llaves_avisos.sql — Notificaciones del Centro de seguimiento en la app
-- Android.
--
-- La app revisa cada ~15 minutos, en segundo plano, si hay avisos nuevos y
-- los muestra como notificación del teléfono (con su tono). En segundo plano
-- no hay sesión de PHP, así que al activar las notificaciones el servidor
-- entrega a esa instalación una llave aleatoria que SOLO sirve para leer los
-- avisos sin leer (GET /app/android/avisos.json); no abre sesión ni permite
-- cambiar nada.
--
-- Mismas reglas que llaves_huella_android (054):
-- • Solo se guarda el SHA-256 de la llave, nunca la llave.
-- • Va ligada a la instalación y a la contraseña vigente: si la contraseña
--   cambia, la llave deja de servir.
-- • Caduca a los 90 días sin uso; se revoca al apagar las notificaciones.
-- ===========================================================================

CREATE TABLE llaves_avisos_android (
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
  UNIQUE KEY uq_llave_avisos (llave_hash),
  KEY idx_llave_avisos_instalacion (instalacion, usuario_id),
  CONSTRAINT fk_llave_avisos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 028_calendario.sql — Herramienta «Calendario»: recordatorios y eventos
--
-- Calendario mensual del panel (grupo «Herramientas personales»). Al hacer
-- clic sobre un día se captura un recordatorio o evento con su fecha, nombre,
-- descripción opcional, negocio opcional, nivel de importancia y hora.
--
-- Como el resto de herramientas personales, es COMPARTIDA: cualquier
-- administrador ve y gestiona todo; `usuario_id` sólo registra quién lo
-- capturó (para trazabilidad), no filtra el acceso.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS calendario_eventos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL COMMENT 'Admin que capturó el recordatorio o evento',
    fecha DATE NOT NULL COMMENT 'Día del calendario al que pertenece',
    hora TIME DEFAULT NULL COMMENT 'Hora del evento (NULL = todo el día)',
    nombre VARCHAR(200) NOT NULL COMMENT 'Nombre del recordatorio o evento',
    descripcion TEXT DEFAULT NULL COMMENT 'Detalles (opcional)',
    empresa_id INT UNSIGNED DEFAULT NULL COMMENT 'Negocio asociado (opcional)',
    importancia ENUM('bajo','medio','alto') NOT NULL DEFAULT 'medio',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_calendario_fecha (fecha, hora),
    KEY idx_calendario_empresa (empresa_id),
    CONSTRAINT fk_calendario_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_calendario_empresa
        FOREIGN KEY (empresa_id) REFERENCES empresas (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

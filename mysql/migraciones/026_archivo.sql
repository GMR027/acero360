-- ===========================================================================
-- 026_archivo.sql — Herramienta «Archivo»: carpetas virtuales con documentos
--
-- Almacena lo que sube el usuario organizado en carpetas: ofimática (Office,
-- LibreOffice, OnlyOffice), PDF, imágenes, comprimidos y modelos BIM/CAD
-- (Rhinoceros, Revit, SketchUp, AutoCAD, etc.).
--
-- Como el resto de herramientas personales, es COMPARTIDA: cualquier
-- administrador ve y gestiona todo; `creado_por` sólo registra quién subió.
--
-- Los bytes viven FUERA de public/ (storage/archivo/…, bloqueado por .htaccess
-- y por el RewriteRule de la raíz) con nombre aleatorio: el nombre original
-- sólo se guarda como dato y manda a la hora de descargar.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS archivo_carpetas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    carpeta_padre_id INT DEFAULT NULL,                   -- NULL = en la raíz
    creado_por INT DEFAULT NULL,
    creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_padre (carpeta_padre_id, nombre),
    CONSTRAINT fk_archivo_carpeta_padre FOREIGN KEY (carpeta_padre_id)
        REFERENCES archivo_carpetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archivo_archivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    carpeta_id INT DEFAULT NULL,                         -- NULL = sin carpeta (raíz)
    nombre VARCHAR(255) NOT NULL,                        -- nombre original que manda al descargar
    archivo VARCHAR(80) NOT NULL,                        -- nombre aleatorio en disco
    mime VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
    tamano BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NOT NULL DEFAULT '',
    descripcion VARCHAR(300) NOT NULL DEFAULT '',
    subido_por INT UNSIGNED DEFAULT NULL,
    subido TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_carpeta (carpeta_id, subido),
    KEY idx_sha (sha256),
    CONSTRAINT fk_archivo_carpeta FOREIGN KEY (carpeta_id)
        REFERENCES archivo_carpetas(id) ON DELETE SET NULL,
    CONSTRAINT fk_archivo_usuario FOREIGN KEY (subido_por)
        REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

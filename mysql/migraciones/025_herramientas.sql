-- ===========================================================================
-- 025_herramientas.sql — Herramientas personales: Redactor, SheetFlow y Editor
--
-- Las tres secciones son COMPARTIDAS, como Notas: cualquier administrador ve
-- y edita todo. `creado_por` sólo registra quién lo escribió.
-- ===========================================================================

/* ---------------------------------------------------------------------------
 * Redactor — documentos de texto (HTML saneado en servidor)
 * ------------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS redactor_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    fecha DATE NOT NULL,
    departamento VARCHAR(100) NOT NULL DEFAULT '',        -- carpeta
    idioma VARCHAR(8) NOT NULL DEFAULT 'es',              -- es, en o es+en
    papel VARCHAR(10) NOT NULL DEFAULT 'carta',           -- carta, a4, oficio, tabloide
    orientacion VARCHAR(10) NOT NULL DEFAULT 'vertical',
    margen_sup DECIMAL(4,2) NOT NULL DEFAULT 2.54,
    margen_inf DECIMAL(4,2) NOT NULL DEFAULT 2.54,
    margen_izq DECIMAL(4,2) NOT NULL DEFAULT 2.54,
    margen_der DECIMAL(4,2) NOT NULL DEFAULT 2.54,
    numerar TINYINT(1) NOT NULL DEFAULT 1,
    encabezado TINYINT(1) NOT NULL DEFAULT 0,
    rastrear TINYINT(1) NOT NULL DEFAULT 0,
    marca_agua VARCHAR(40) NOT NULL DEFAULT '',
    encabezado_texto VARCHAR(200) NOT NULL DEFAULT '',
    pie_texto VARCHAR(200) NOT NULL DEFAULT '',
    contenido LONGTEXT,
    comentarios LONGTEXT NULL,
    creado_por INT DEFAULT NULL,
    creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_departamento (departamento, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redactor_versiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento_id INT NOT NULL,
    etiqueta VARCHAR(80) NOT NULL DEFAULT '',
    usuario VARCHAR(100) NOT NULL DEFAULT '',
    contenido LONGTEXT,
    creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_documento (documento_id, creado),
    CONSTRAINT fk_redactor_version FOREIGN KEY (documento_id) REFERENCES redactor_documentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redactor_palabras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    palabra VARCHAR(60) NOT NULL,
    UNIQUE KEY uq_palabra (palabra)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redactor_plantillas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(200) NOT NULL DEFAULT '',
    papel VARCHAR(10) NOT NULL DEFAULT 'carta',
    orientacion VARCHAR(10) NOT NULL DEFAULT 'vertical',
    margen_sup DECIMAL(4,2) NOT NULL DEFAULT 2.54,
    margen_inf DECIMAL(4,2) NOT NULL DEFAULT 2.54,
    margen_izq DECIMAL(4,2) NOT NULL DEFAULT 2.54,
    margen_der DECIMAL(4,2) NOT NULL DEFAULT 2.54,
    contenido LONGTEXT,
    creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redactor_firmas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(60) NOT NULL,
    archivo VARCHAR(40) NOT NULL,
    creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_usuario (usuario_id),
    CONSTRAINT fk_redactor_firma FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redactor_sellos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento_id INT NOT NULL,
    usuario_id INT UNSIGNED DEFAULT NULL,
    nombre VARCHAR(100) NOT NULL,
    cargo VARCHAR(100) NOT NULL DEFAULT '',
    huella CHAR(64) NOT NULL,
    firmado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_doc (documento_id),
    CONSTRAINT fk_redactor_sello FOREIGN KEY (documento_id) REFERENCES redactor_documentos(id) ON DELETE CASCADE,
    CONSTRAINT fk_redactor_sello_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ---------------------------------------------------------------------------
 * SheetFlow — tablas reutilizables (datos JSON saneados en servidor)
 * ------------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS sheetflow_hojas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    fecha DATE NOT NULL,
    departamento VARCHAR(100) NOT NULL DEFAULT '',
    descripcion VARCHAR(300) NOT NULL DEFAULT '',
    datos LONGTEXT,
    creado_por INT DEFAULT NULL,
    creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_departamento (departamento, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ---------------------------------------------------------------------------
 * Editor — documentos con láminas libres (Konva) exportables a PDF/PNG
 * ------------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS editor_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    ancho INT NOT NULL DEFAULT 1240,                     -- px del lienzo (a 96 dpi)
    alto INT NOT NULL DEFAULT 1754,
    orientacion VARCHAR(10) NOT NULL DEFAULT 'vertical',
    fondo VARCHAR(30) NOT NULL DEFAULT 'liso',            -- liso, cuadricula, puntos, imagen
    fondo_color VARCHAR(7) NOT NULL DEFAULT '#ffffff',
    fondo_imagen VARCHAR(100) DEFAULT NULL,
    fondo_config TEXT,
    creado_por INT DEFAULT NULL,
    creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_actualizado (actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS editor_laminas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento_id INT NOT NULL,
    orden INT NOT NULL DEFAULT 1,
    nombre VARCHAR(60) NOT NULL DEFAULT '',
    marcas_json LONGTEXT,
    marcas_png VARCHAR(100) DEFAULT NULL,
    creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_documento (documento_id, orden),
    CONSTRAINT fk_editor_lamina FOREIGN KEY (documento_id) REFERENCES editor_documentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

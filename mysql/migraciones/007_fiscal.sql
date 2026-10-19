-- ===========================================================================
-- 007_fiscal.sql — Etapa 6: configuración fiscal, catálogo versionado, motor,
--                  retenciones, ajustes, declaraciones y cierre mensual
--
--   catalogo_fiscal       reglas y tarifas VERSIONADAS por ejercicio e impuesto
--                         (borrador → validada → activa → reemplazada; nunca se pisan)
--   configuracion_fiscal  historial de la configuración del contribuyente
--   retenciones           ISR/IVA que los clientes le retuvieron
--   ajustes_fiscales      saldo a favor de IVA anterior, PTU pagada, pérdidas por amortizar, etc.
--   declaraciones         importes DECLARADOS y PAGADOS realmente (para comparar con el motor)
--   calculos_fiscales     fotografía de un cálculo con la versión de reglas que se usó
--   periodos_historial    cierres y reaperturas (quién, cuándo, por qué)
--
-- El resultado del motor es una ESTIMACIÓN administrativa; no sustituye al contador.
-- ===========================================================================

CREATE TABLE catalogo_fiscal (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo                 VARCHAR(60)  NOT NULL COMMENT 'isr_pagos_provisionales_art106 | iva_tasas | reglas_plataforma',
  ejercicio            SMALLINT UNSIGNED NOT NULL,
  version              INT UNSIGNED NOT NULL,
  nombre               VARCHAR(200) NOT NULL,
  fuente_documento     VARCHAR(255) NOT NULL,
  fuente_url           VARCHAR(500) NOT NULL,
  fuente_publicacion   VARCHAR(200) DEFAULT NULL,
  sha256_fuente        CHAR(64)     DEFAULT NULL COMMENT 'Huella del documento oficial del que se extrajo',
  vigencia_desde       DATE         NOT NULL,
  vigencia_hasta       DATE         NOT NULL,
  datos                JSON         NOT NULL,
  sha256_datos         CHAR(64)     NOT NULL,
  estado               ENUM('borrador','validada','activa','reemplazada') NOT NULL DEFAULT 'borrador',
  resultado_validacion JSON         DEFAULT NULL,
  creado_por           INT UNSIGNED NOT NULL,
  creado_en            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  validada_en          DATETIME     DEFAULT NULL,
  activada_por         INT UNSIGNED DEFAULT NULL,
  activada_en          DATETIME     DEFAULT NULL,
  motivo_activacion    VARCHAR(255) DEFAULT NULL,
  reemplaza_id         INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catalogo_version (tipo, ejercicio, version),
  KEY idx_catalogo_activa (tipo, ejercicio, estado),
  CONSTRAINT fk_catalogo_creador FOREIGN KEY (creado_por) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configuracion_fiscal (
  id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id          INT UNSIGNED NOT NULL,
  vigente_desde             DATE NOT NULL,
  vigente_hasta             DATE DEFAULT NULL,
  pais                      VARCHAR(40)  NOT NULL DEFAULT 'México',
  tipo_persona              ENUM('fisica','moral') NOT NULL DEFAULT 'fisica',
  regimen                   VARCHAR(120) NOT NULL,
  base_ingresos             ENUM('flujo_efectivo') NOT NULL DEFAULT 'flujo_efectivo'
                            COMMENT 'ISR e IVA se causan al cobrar / deducen al pagar (único método soportado hoy)',
  confirmada_con_contador   TINYINT(1) NOT NULL DEFAULT 0,
  motivo_cambio             VARCHAR(255) DEFAULT NULL,
  usuario_id                INT UNSIGNED NOT NULL,
  creado_en                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cfg_fiscal (contribuyente_id, vigente_desde),
  CONSTRAINT fk_cfg_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_cfg_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion_fiscal (contribuyente_id, vigente_desde, tipo_persona, regimen, confirmada_con_contador, motivo_cambio, usuario_id)
SELECT c.id, '2026-01-01', 'fisica', 'Actividades Empresariales y Profesionales', 0,
       'Configuración inicial (pendiente de confirmar con el contador)', (SELECT MIN(id) FROM usuarios)
  FROM contribuyentes c;

CREATE TABLE retenciones (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id   INT UNSIGNED NOT NULL,
  fecha              DATE NOT NULL,
  impuesto           ENUM('ISR','IVA') NOT NULL,
  importe            DECIMAL(14,2) NOT NULL,
  retenedor          VARCHAR(150) NOT NULL COMMENT 'Quién retuvo (cliente)',
  ingreso_id         INT UNSIGNED DEFAULT NULL,
  nota               VARCHAR(255) DEFAULT NULL,
  estado             ENUM('vigente','cancelado') NOT NULL DEFAULT 'vigente',
  motivo_cancelacion VARCHAR(255) DEFAULT NULL,
  usuario_id         INT UNSIGNED NOT NULL,
  creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ret_fecha (contribuyente_id, fecha),
  CONSTRAINT fk_ret_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ret_ingreso FOREIGN KEY (ingreso_id) REFERENCES ingresos (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ret_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ajustes_fiscales (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id   INT UNSIGNED NOT NULL,
  anio               SMALLINT UNSIGNED NOT NULL,
  mes                TINYINT UNSIGNED NOT NULL,
  concepto           ENUM('saldo_favor_iva_anterior','otros_ajustes_iva','ptu_pagada','perdidas_por_amortizar','otros_acreditamientos_isr') NOT NULL,
  importe            DECIMAL(14,2) NOT NULL,
  nota               VARCHAR(255) DEFAULT NULL,
  estado             ENUM('vigente','cancelado') NOT NULL DEFAULT 'vigente',
  motivo_cancelacion VARCHAR(255) DEFAULT NULL,
  usuario_id         INT UNSIGNED NOT NULL,
  creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ajuste_periodo (contribuyente_id, anio, mes),
  CONSTRAINT fk_ajuste_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ajuste_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE declaraciones (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id   INT UNSIGNED NOT NULL,
  anio               SMALLINT UNSIGNED NOT NULL,
  mes                TINYINT UNSIGNED NOT NULL,
  impuesto           ENUM('ISR','IVA') NOT NULL,
  importe_declarado  DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Impuesto a cargo declarado ante el SAT',
  saldo_favor        DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Saldo a favor declarado (IVA)',
  importe_pagado     DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Efectivamente pagado',
  fecha_presentacion DATE DEFAULT NULL,
  fecha_pago         DATE DEFAULT NULL,
  referencia         VARCHAR(80) DEFAULT NULL COMMENT 'Número de operación / línea de captura',
  nota               VARCHAR(255) DEFAULT NULL,
  usuario_id         INT UNSIGNED NOT NULL,
  creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_declaracion (contribuyente_id, anio, mes, impuesto),
  CONSTRAINT fk_decl_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_decl_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calculos_fiscales (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contribuyente_id   INT UNSIGNED NOT NULL,
  anio               SMALLINT UNSIGNED NOT NULL,
  mes                TINYINT UNSIGNED NOT NULL,
  tipo               ENUM('guardado','cierre') NOT NULL DEFAULT 'guardado',
  catalogo_isr_id    INT UNSIGNED DEFAULT NULL,
  catalogo_iva_id    INT UNSIGNED DEFAULT NULL,
  catalogo_reglas_id INT UNSIGNED DEFAULT NULL,
  entradas           JSON NOT NULL,
  resultado          JSON NOT NULL,
  sha256             CHAR(64) NOT NULL,
  usuario_id         INT UNSIGNED NOT NULL,
  creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_calculo_periodo (contribuyente_id, anio, mes),
  CONSTRAINT fk_calculo_contribuyente FOREIGN KEY (contribuyente_id) REFERENCES contribuyentes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_calculo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE periodos_historial (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  periodo_id     INT UNSIGNED NOT NULL,
  accion         ENUM('cierre','reapertura') NOT NULL,
  motivo         VARCHAR(255) DEFAULT NULL,
  usuario_id     INT UNSIGNED NOT NULL,
  resumen_sha256 CHAR(64) DEFAULT NULL,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ph_periodo (periodo_id),
  CONSTRAINT fk_ph_periodo FOREIGN KEY (periodo_id) REFERENCES periodos_contables (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ph_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 048_pagos_gasto.sql — Abonos (pagos parciales o totales) de un gasto suelto
--
-- Hasta ahora un gasto que no venía de una compra solo podía marcarse como
-- «pagado» de una vez. Los gastos con factura PPD necesitan registrar cada
-- parcialidad y, después, vincular el complemento de pago que la respalda.
--
-- Reglas:
--   • Un abono reduce el saldo del gasto; NO crea otro gasto ni otro movimiento.
--   • gastos.estado_pago sigue siendo la fuente de verdad para impuestos:
--     solo pasa a «pagado» cuando la suma de abonos cubre el total.
--   • Un abono no se borra: se cancela con motivo y queda en la bitácora.
--   • El token de un solo uso impide duplicar el abono al reenviar el formulario.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS pagos_gasto (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gasto_id           INT UNSIGNED NOT NULL,
  fecha              DATE NOT NULL,
  importe            DECIMAL(14,2) NOT NULL,
  metodo             VARCHAR(30) DEFAULT NULL,
  referencia         VARCHAR(80) DEFAULT NULL,
  token              CHAR(32) DEFAULT NULL COMMENT 'Un solo uso: reenviar el formulario no duplica el abono',
  estado             ENUM('vigente','cancelado') NOT NULL DEFAULT 'vigente',
  motivo_cancelacion VARCHAR(255) DEFAULT NULL,
  usuario_id         INT UNSIGNED NOT NULL,
  creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pago_gasto_token (token),
  KEY idx_pago_gasto_gasto (gasto_id),
  KEY idx_pago_gasto_fecha (fecha),
  CONSTRAINT fk_pago_gasto_gasto FOREIGN KEY (gasto_id) REFERENCES gastos (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pago_gasto_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Abonos a un gasto suelto; reducen el saldo y no crean movimientos';

-- Vínculo entre un complemento de pago (CFDI tipo P) y el abono que respalda.
-- Se separa de complemento_pagos porque aquella tabla apunta a pagos_compra.
CREATE TABLE IF NOT EXISTS complemento_pagos_gasto (
  complemento_docto_id INT UNSIGNED NOT NULL,
  pago_gasto_id        INT UNSIGNED NOT NULL,
  PRIMARY KEY (complemento_docto_id, pago_gasto_id),
  KEY idx_cpg_gasto_pago (pago_gasto_id),
  CONSTRAINT fk_cpg_gasto_docto FOREIGN KEY (complemento_docto_id) REFERENCES complemento_doctos (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cpg_gasto_pago FOREIGN KEY (pago_gasto_id) REFERENCES pagos_gasto (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pagos de gasto que un complemento de pago respalda';

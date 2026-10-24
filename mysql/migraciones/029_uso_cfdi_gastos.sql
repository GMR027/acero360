-- ===========================================================================
-- 029_uso_cfdi_gastos.sql — Uso del CFDI y método de pago en los gastos
--
-- El formulario de captura de gasto ya registra forma de pago, UUID y los datos
-- del emisor, pero el Uso del CFDI (atributo UsoCFDI del receptor) sólo existía
-- si el XML se leía automáticamente: no se podía capturar ni corregir a mano, y
-- el método de pago (PUE / PPD) sólo admitía los dos valores del CFDI leído.
--
-- Ahora:
--   · `gastos.uso_cfdi`    guarda el uso del CFDI elegido (catálogo SAT, 4 caracteres).
--   · `gastos.metodo_pago_sat` admite además «POR» (por definir): la persona sabe
--     que la factura se pagará, pero todavía no si en una sola exhibición o en
--     parcialidades. El centro de seguimiento avisa de ambas situaciones.
-- ===========================================================================

ALTER TABLE gastos
    ADD COLUMN uso_cfdi CHAR(4) DEFAULT NULL COMMENT 'Uso del CFDI del receptor (catálogo SAT)' AFTER metodo_pago_sat,
    MODIFY COLUMN metodo_pago_sat CHAR(3) DEFAULT NULL COMMENT 'PUE, PPD o POR (por definir)';

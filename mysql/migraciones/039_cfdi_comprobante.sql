-- ===========================================================================
-- 039_cfdi_comprobante.sql — Tipo de comprobante del CFDI y uso del CFDI de
--                            la venta, autocompletados desde el documento
--
-- El botón «Registro compra inteligente» ya guardaba forma y método de pago en
-- el gasto, pero el TIPO DE COMPROPANTE (c_TipoDeComprobante: I, E, N, P, T)
-- no existía en ninguna pantalla ni en la base, y en la venta ninguno de los
-- cuatro datos del CFDI leído llegaba a «Facturación y cobro».
--
--   · `gastos.tipo_cfdi`        tipo del comprobante que respalda el gasto.
--   · `ingresos.uso_cfdi`       uso del CFDI declarado en la factura EMITIDA
--                               que originó la venta (si se leyó con el botón).
--   · `ingresos.tipo_cfdi`      tipo de ese comprobante.
--
-- Las tres columnas son NULAS: no rompen los registros existentes ni las
-- migraciones anteriores; si no se llenan, la pantalla simplemente no
-- autocompleta y la captura sigue siendo manual.
-- ===========================================================================

ALTER TABLE gastos
    ADD COLUMN tipo_cfdi CHAR(1) DEFAULT NULL COMMENT 'Tipo de comprobante (c_TipoDeComprobante: I, E, N, P, T)' AFTER uso_cfdi;

ALTER TABLE ingresos
    ADD COLUMN uso_cfdi  VARCHAR(4) DEFAULT NULL COMMENT 'Uso del CFDI del CFDI emitido (catálogo SAT)'   AFTER forma_pago,
    ADD COLUMN tipo_cfdi CHAR(1)    DEFAULT NULL COMMENT 'Tipo de comprobante del CFDI emitido (I, E, N, P, T)' AFTER uso_cfdi;

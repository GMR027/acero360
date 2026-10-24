-- ===========================================================================
-- 035_ventas_validacion.sql — Validación previa a facturar una venta (FASE 5).
--
--   clientes        datos fiscales del receptor para el CFDI que se emitirá:
--                   código postal fiscal, régimen fiscal y uso del CFDI.
--                   Hoy no existían en ningún lado: sin ellos no se puede
--                   completar un CFDI 4.0 válido (RfcReceptor,
--                   RegimenFiscalReceptor, UsoCFDI, DomicilioFiscalReceptor).
--
--   ingresos        método (PUE/PPD) y forma de pago declarados para el CFDI
--                   de la venta. Se validan contra los cobros reales y las
--                   condiciones de la operación antes de facturar.
--
-- Ambos bloques son COLUMNAS NULAS: no rompen datos existentes ni las
-- migraciones anteriores; si no se llenan, la validación previa lo reporta
-- como error obligatorio al intentar facturar.
-- ===========================================================================

ALTER TABLE clientes
  ADD COLUMN codigo_postal CHAR(5)     DEFAULT NULL COMMENT 'Código postal fiscal del receptor (DomicilioFiscalReceptor)' AFTER rfc,
  ADD COLUMN regimen_fiscal VARCHAR(10) DEFAULT NULL COMMENT 'Clave del catálogo SAT del régimen del receptor'        AFTER codigo_postal,
  ADD COLUMN uso_cfdi      VARCHAR(4)  DEFAULT NULL COMMENT 'Uso del CFDI por defecto del receptor (catálogo SAT)'     AFTER regimen_fiscal;

ALTER TABLE ingresos
  ADD COLUMN metodo_pago VARCHAR(4) DEFAULT NULL COMMENT 'Método de pago del CFDI: PUE o PPD'        AFTER fecha_facturacion,
  ADD COLUMN forma_pago  VARCHAR(3) DEFAULT NULL COMMENT 'Clave SAT de la forma de pago del CFDI'    AFTER metodo_pago;

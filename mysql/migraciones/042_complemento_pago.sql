-- ===========================================================================
-- 042_complemento_pago.sql — Tipo de documento propio para los complementos
-- de pago (CFDI tipo «P») que los proveedores entregan al recibir el pago de
-- una factura PPD.
--
-- Se separa de «comprobante_pago» para que el apartado «Complemento de pago»
-- del detalle del gasto no mezcle los comprobantes bancarios o recibos que ya
-- se hayan adjuntado con ese tipo.
-- ===========================================================================

ALTER TABLE documentos
    MODIFY tipo ENUM('factura_pdf','xml','comprobante_pago','complemento_pago','ticket','imagen','otro')
        NOT NULL DEFAULT 'otro';

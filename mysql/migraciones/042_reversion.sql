-- Reversión de 042_complemento_pago.sql — devuelve los complementos adjuntos
-- al tipo genérico «otro» y retira el valor del ENUM de documentos.

UPDATE documentos SET tipo = 'otro' WHERE tipo = 'complemento_pago';

ALTER TABLE documentos
    MODIFY tipo ENUM('factura_pdf','xml','comprobante_pago','ticket','imagen','otro')
        NOT NULL DEFAULT 'otro';

DELETE FROM schema_migraciones WHERE nombre = '042_complemento_pago.sql';

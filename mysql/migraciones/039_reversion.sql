-- Reversión de 039_cfdi_comprobante.sql — quita los tres campos nuevos del
-- tipo de comprobante y del uso del CFDI de la venta.
ALTER TABLE gastos
    DROP COLUMN tipo_cfdi;

ALTER TABLE ingresos
    DROP COLUMN uso_cfdi,
    DROP COLUMN tipo_cfdi;

-- Quita el registro para que `scripts/migrar.php` vuelva a aplicar la migración (patrón de 001-027).
DELETE FROM schema_migraciones WHERE nombre = '039_cfdi_comprobante.sql';

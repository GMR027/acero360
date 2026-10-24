-- Reversión de 029_uso_cfdi_gastos.sql — quita el uso del CFDI y vuelve el
-- método de pago a los dos valores originales (PUE / PPD).
ALTER TABLE gastos
    DROP COLUMN uso_cfdi,
    MODIFY COLUMN metodo_pago_sat CHAR(3) DEFAULT NULL COMMENT 'PUE o PPD';
-- Quita el registro para que `scripts/migrar.php` vuelva a aplicar la migración (patrón de 001-027).
DELETE FROM schema_migraciones WHERE nombre = '029_uso_cfdi_gastos.sql';

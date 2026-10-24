-- Reversión de 035_ventas_validacion.sql
ALTER TABLE clientes
  DROP COLUMN codigo_postal,
  DROP COLUMN regimen_fiscal,
  DROP COLUMN uso_cfdi;

ALTER TABLE ingresos
  DROP COLUMN metodo_pago,
  DROP COLUMN forma_pago;
-- Quita el registro para que `scripts/migrar.php` vuelva a aplicar la migración (patrón de 001-027).
DELETE FROM schema_migraciones WHERE nombre = '035_ventas_validacion.sql';

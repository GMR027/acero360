ALTER TABLE gastos DROP COLUMN beneficiario_tipo;
DELETE FROM schema_migraciones WHERE nombre = '014_beneficiario_gasto.sql';

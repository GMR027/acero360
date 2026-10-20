ALTER TABLE gastos DROP INDEX uq_gasto_uuid_vigente, DROP COLUMN uuid_vigente;
ALTER TABLE ingresos DROP INDEX uq_ingreso_uuid;
DELETE FROM schema_migraciones WHERE nombre = '018_uuid_unico.sql';

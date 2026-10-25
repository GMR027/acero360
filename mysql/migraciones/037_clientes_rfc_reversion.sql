-- Reversión de 037_clientes_rfc.sql — el registro de RFCs por cliente se elimina;
-- clientes.rfc (el RFC activo) nunca se tocó, así que las fichas quedan igual.
DROP TABLE IF EXISTS clientes_rfc;
DELETE FROM schema_migraciones WHERE nombre = '037_clientes_rfc.sql';

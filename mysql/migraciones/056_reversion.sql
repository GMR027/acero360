-- Reversión de 056_distribuidores.sql (las fachadas subidas quedan en public/image sin uso).
DROP TABLE IF EXISTS distribuidores;
DELETE FROM schema_migraciones WHERE nombre = '056_distribuidores.sql';

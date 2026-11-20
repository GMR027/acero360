-- Reversión de 058_redactor_membrete_bloques.sql — quita el diseño por
-- bloques del membrete; las columnas antiguas siguen funcionando.

ALTER TABLE redactor_documentos
    DROP COLUMN membrete;

DELETE FROM schema_migraciones WHERE nombre = '058_redactor_membrete_bloques.sql';

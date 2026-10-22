-- Reversión de 025_herramientas.sql (borra TODOS los documentos de Redactor, SheetFlow y Editor).
DROP TABLE IF EXISTS editor_laminas;
DROP TABLE IF EXISTS editor_documentos;
DROP TABLE IF EXISTS sheetflow_hojas;
DROP TABLE IF EXISTS redactor_sellos;
DROP TABLE IF EXISTS redactor_firmas;
DROP TABLE IF EXISTS redactor_plantillas;
DROP TABLE IF EXISTS redactor_palabras;
DROP TABLE IF EXISTS redactor_versiones;
DROP TABLE IF EXISTS redactor_documentos;
DELETE FROM schema_migraciones WHERE nombre = '025_herramientas.sql';

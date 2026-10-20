ALTER TABLE empresas DROP COLUMN logo_alto_pdf;
DELETE FROM schema_migraciones WHERE nombre = '016_logo_pdf.sql';

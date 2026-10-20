ALTER TABLE gastos DROP COLUMN folio_comercial, DROP COLUMN moneda, DROP COLUMN forma_pago_sat, DROP COLUMN metodo_pago_sat,
  DROP COLUMN rfc_emisor, DROP COLUMN rfc_receptor, DROP COLUMN lectura_id;
DROP TABLE IF EXISTS lecturas_documentales;
DELETE FROM schema_migraciones WHERE nombre = '013_lectura_documental.sql';

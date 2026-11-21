-- Reversión de 059_corregir_condiciones_canastillas.sql — restaura el texto
-- original de los documentos que se corrigieron y elimina la copia temporal.

UPDATE ventas v
  JOIN condiciones_envio_respaldo_059 r
    ON r.tabla = 'ventas' AND r.registro_id = v.id
   SET v.condiciones_envio = r.condiciones_envio;

UPDATE cotizaciones c
  JOIN condiciones_envio_respaldo_059 r
    ON r.tabla = 'cotizaciones' AND r.registro_id = c.id
   SET c.condiciones_envio = r.condiciones_envio;

UPDATE pedidos p
  JOIN condiciones_envio_respaldo_059 r
    ON r.tabla = 'pedidos' AND r.registro_id = p.id
   SET p.condiciones_envio = r.condiciones_envio;

DROP TABLE IF EXISTS condiciones_envio_respaldo_059;

DELETE FROM schema_migraciones WHERE nombre = '059_corregir_condiciones_canastillas.sql';

DROP TABLE impuesto_real_mensual;
DELETE FROM configuracion WHERE clave = 'isr_tasa_simple';
DELETE FROM categorias_gasto WHERE nombre IN ('Luz, agua y gas','Teléfono e internet','Ferretería y materiales','Papelería y oficina','Alimentos y despensa','Seguros','Donativos','Retiros del propietario')
  AND id NOT IN (SELECT categoria_id FROM gastos WHERE categoria_id IS NOT NULL);
DELETE FROM schema_migraciones WHERE nombre = '010_resumen_fiscal.sql';

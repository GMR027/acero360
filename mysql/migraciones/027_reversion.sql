-- Reversión de 027_categorias_adicionales.sql
-- Borra las categorías nuevas sólo si ningún gasto las está usando.
DELETE FROM categorias_gasto
 WHERE nombre IN ('Gastos bancarios y comisiones', 'Flete y maniobras', 'Seguridad y capacitación')
   AND id NOT IN (SELECT categoria_id FROM gastos WHERE categoria_id IS NOT NULL);
DELETE FROM schema_migraciones WHERE nombre = '027_categorias_adicionales.sql';

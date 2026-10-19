-- Reversión de 006 (borra ingresos, cobros, gastos y la referencia a documentos; los archivos en disco NO se borran).
DROP TABLE documentos, gasto_reparto, gastos, categorias_gasto, cobros, ingresos, periodos_contables;
DELETE FROM schema_migraciones WHERE nombre = '006_contabilidad.sql';

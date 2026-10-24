-- ===========================================================================
-- 030_papelera_gastos.sql — la papelera también recibe gastos eliminados
--
-- Hasta ahora la papelera sólo guardaba ventas y cotizaciones. Un gasto
-- eliminado (Gastos::eliminar) manda aquí su copia completa para poder
-- restaurarlo durante 90 días, igual que el resto.
--
-- Restaurar un gasto devuelve sus datos y su reparto entre negocios, pero NO
-- los archivos adjuntos: al eliminarlo se borran del disco.
-- ===========================================================================

ALTER TABLE papelera
    MODIFY COLUMN tipo ENUM('venta', 'cotizacion', 'gasto') NOT NULL;

-- Reversión de 030_papelera_gastos.sql — la papelera vuelve a guardar sólo
-- ventas y cotizaciones.
--
-- Las copias de gastos ya guardadas se eliminan antes: al volver al ENUM de
-- dos valores MySQL rechazaría las filas «gasto», y restaurarlas como venta o
-- cotización no tendría sentido (son datos de otra tabla).
DELETE FROM papelera WHERE tipo = 'gasto';

ALTER TABLE papelera
    MODIFY COLUMN tipo ENUM('venta', 'cotizacion') NOT NULL;

-- ===========================================================================
-- 031_indice_recepciones.sql — índice de fecha en recepciones
--
-- La herramienta «Calendario» marca en cada casilla las recepciones de
-- proveedor del día (EventosCalendario::marcadoresNegocio). Esa consulta
-- filtra por rango de fechas, y la tabla sólo tenía índices en orden_id y
-- token: con muchos registros la búsqueda sería un barrido completo.
--
-- El índice en `fecha` también apoya cualquier listado mensual de recepciones.
-- No cambia datos ni estructura de columnas.
-- ===========================================================================

ALTER TABLE recepciones
    ADD INDEX idx_recepcion_fecha (fecha);

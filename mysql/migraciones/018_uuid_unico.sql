-- aplica-si: SELECT (SELECT COUNT(*) FROM (SELECT uuid_fiscal FROM ingresos WHERE uuid_fiscal IS NOT NULL GROUP BY uuid_fiscal HAVING COUNT(*) > 1) a) + (SELECT COUNT(*) FROM (SELECT uuid_fiscal FROM gastos WHERE uuid_fiscal IS NOT NULL AND estado = 'vigente' GROUP BY uuid_fiscal HAVING COUNT(*) > 1) b) = 0
-- ===========================================================================
-- 018_uuid_unico.sql — la base de datos misma rechaza un folio fiscal (UUID) repetido
--   (solo se aplica si hoy no hay UUID repetidos; si los hay, se depuran primero y se vuelve a intentar).
--   ingresos.uuid_fiscal   único entre todas las ventas
--   gastos.uuid_vigente    columna calculada: el UUID solo cuenta mientras el gasto está vigente
--                          (un gasto cancelado puede volver a capturarse)
-- ===========================================================================
ALTER TABLE ingresos ADD UNIQUE KEY uq_ingreso_uuid (uuid_fiscal);

ALTER TABLE gastos
  ADD COLUMN uuid_vigente CHAR(36) GENERATED ALWAYS AS (IF(estado = 'vigente', uuid_fiscal, NULL)) VIRTUAL,
  ADD UNIQUE KEY uq_gasto_uuid_vigente (uuid_vigente);

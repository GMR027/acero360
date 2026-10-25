-- ===========================================================================
-- 038_reversion.sql — Revierte 038_seguimiento_entregas.sql
-- ===========================================================================

DROP TABLE IF EXISTS entrega_incidencia_renglones;
DROP TABLE IF EXISTS entrega_incidencias;

ALTER TABLE ventas
  DROP COLUMN entrega_estado,
  DROP COLUMN entrega_observaciones,
  DROP COLUMN entrega_fecha;

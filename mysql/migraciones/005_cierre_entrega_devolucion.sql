-- ===========================================================================
-- 005_cierre_entrega_devolucion.sql
-- Una devolución de cliente CIERRA la entrega de la venta: lo que faltaba por
-- entregar deja de estar pendiente. La venta sigue registrada (es ingreso); su
-- ajuste económico se maneja en contabilidad.
-- ===========================================================================
ALTER TABLE ventas
  ADD COLUMN entrega_cerrada_en DATETIME DEFAULT NULL
      COMMENT 'Fecha en que se cerró la entrega (p. ej. por una devolución)',
  ADD COLUMN entrega_cerrada_motivo VARCHAR(120) DEFAULT NULL;

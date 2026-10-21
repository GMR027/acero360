-- ===========================================================================
-- 022_iva_compras.sql — IVA de la compra calculado desde la base gravable y la tasa fiscal vigente
--   ordenes_compra.iva_tasa          tasa (%) con la que se calculó el IVA de la compra (NULL = IVA capturado a mano o no especificado)
--   orden_compra_partidas.aplica_iva 1 = la partida es base gravable de IVA. Las partidas existentes quedan en 0:
--                                    su IVA (si lo hay) se capturó a mano y no se reinterpreta.
-- ordenes_compra.iva sigue siendo el importe vigente (calculado o corregido a mano contra la factura).
-- ===========================================================================
ALTER TABLE ordenes_compra
  ADD COLUMN iva_tasa DECIMAL(5,2) DEFAULT NULL COMMENT 'Tasa de IVA usada para calcular el IVA (NULL = manual)' AFTER iva;
ALTER TABLE orden_compra_partidas
  ADD COLUMN aplica_iva TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = base gravable de IVA' AFTER importe_esperado;

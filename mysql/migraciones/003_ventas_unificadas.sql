-- ===========================================================================
-- 003_ventas_unificadas.sql — Etapa 3
--
-- `ventas` pasa a ser la ÚNICA fuente de verdad de lo vendido:
--   • venta directa            → ventas.cotizacion_id IS NULL
--   • cotización marcada vendida → una fila en ventas con cotizacion_id = id
-- UNIQUE(cotizacion_id) garantiza máx. 1 venta por cotización: no puede
-- contabilizarse ni descontar inventario dos veces. El stock siempre sale
-- (y regresa) ligado a ventas.id vía movimientos_inventario.venta_id.
-- ===========================================================================

ALTER TABLE ventas
  ADD COLUMN cotizacion_id INT UNSIGNED DEFAULT NULL
      COMMENT 'Cotización de origen (NULL = venta directa)' AFTER id,
  ADD UNIQUE KEY uq_venta_cotizacion (cotizacion_id),
  ADD CONSTRAINT fk_venta_cotizacion
    FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- Respaldo de datos: cada cotización YA vendida recibe su venta.
INSERT INTO ventas
  (cotizacion_id, folio, empresa_id, cliente_id, usuario_id, fecha, estado,
   subtotal, descuento, aplica_iva, iva, total,
   condiciones_envio, condiciones_compra, comentarios, creado_en)
SELECT c.id,
       CONCAT('VEN-', YEAR(c.fecha), '-',
              LPAD(COALESCE(m.maximo, 0)
                   + ROW_NUMBER() OVER (PARTITION BY YEAR(c.fecha) ORDER BY c.fecha, c.id), 4, '0')),
       c.empresa_id, c.cliente_id, c.usuario_id, c.fecha, 'registrada',
       c.subtotal, c.descuento, c.aplica_iva, c.iva, c.total,
       c.condiciones_envio, c.condiciones_compra, c.comentarios, c.creado_en
  FROM cotizaciones c
  LEFT JOIN (SELECT SUBSTRING(folio, 5, 4) AS anio,
                    MAX(CAST(SUBSTRING(folio, 10) AS UNSIGNED)) AS maximo
               FROM ventas GROUP BY SUBSTRING(folio, 5, 4)) m
         ON m.anio = YEAR(c.fecha)
 WHERE c.estado = 'vendido'
   AND NOT EXISTS (SELECT 1 FROM ventas v WHERE v.cotizacion_id = c.id);

INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio_unitario, descuento_pct, subtotal)
SELECT v.id, d.producto_id, d.cantidad, d.precio_unitario, d.descuento_pct, d.subtotal
  FROM cotizacion_detalles d
  JOIN ventas v ON v.cotizacion_id = d.cotizacion_id
 WHERE NOT EXISTS (SELECT 1 FROM venta_detalles x WHERE x.venta_id = v.id);

-- Los movimientos de esas cotizaciones pasan a colgar de la venta.
UPDATE movimientos_inventario m
  JOIN ventas v ON v.cotizacion_id = m.cotizacion_id
   SET m.venta_id = v.id
 WHERE m.venta_id IS NULL AND m.cotizacion_id IS NOT NULL;

UPDATE movimientos_inventario SET motivo = 'venta'
 WHERE motivo IS NULL AND tipo = 'salida' AND (venta_id IS NOT NULL OR cotizacion_id IS NOT NULL);

-- ===========================================================================
-- 038_seguimiento_entregas.sql — Módulo de seguimiento de entregas
--
--   El seguimiento va un paso después de la «Pantalla de entrega»: cuando la
--   venta se entrega (total o parcialmente) pueden surgir incidencias
--   (producto faltante, dañado o defectuoso, incompleto, cambio/sustitución o
--   devolución) y correcciones a la factura emitida (se cancela y se sube la
--   factura correcta).
--
-- Cambios en la tabla `ventas`:
--   • entrega_fecha          fecha de entrega (prometida u acordada)
--   • entrega_observaciones  observaciones del seguimiento de entrega
--   • entrega_estado         estado general del seguimiento, mantenido en
--                            código cada vez que se entrega, se devuelve o se
--                            registra/cierra una incidencia
--
-- Nuevas tablas:
--   entrega_incidencias           una incidencia de entrega de una venta
--   entrega_incidencia_renglones  los productos afectados, qué cantidad regresó
--                                 al inventario y cuánta cantidad se le debe
--                                 al cliente en reemplazo
--
-- Al registrarse una incidencia el producto regresa al inventario (movimiento
-- de entrada con motivo «devolucion», ligado a la venta) y se anota en
-- `venta_devoluciones`, así el cálculo de piezas pendientes de entrega queda
-- consistente (ver Entregas::pendientesDeVentas) sin que cambie la venta ni
-- su importe: el ajuste económico lo resuelve contabilidad al corregir la
-- factura.
-- ===========================================================================

ALTER TABLE ventas
  ADD COLUMN entrega_fecha DATE DEFAULT NULL
      COMMENT 'Fecha de entrega (prometida o acordada)',
  ADD COLUMN entrega_observaciones TEXT DEFAULT NULL
      COMMENT 'Observaciones del seguimiento de entrega',
  ADD COLUMN entrega_estado
      ENUM('pendiente','entregado','entregado_con_incidencias','entregado_incidencias_resueltas')
      NOT NULL DEFAULT 'pendiente'
      COMMENT 'Estado general del seguimiento de la entrega',
  ADD KEY idx_ventas_entrega_estado (entrega_estado);

CREATE TABLE entrega_incidencias (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id              INT UNSIGNED NOT NULL,
  entrega_id            INT UNSIGNED DEFAULT NULL COMMENT 'Entrega (pantalla de entrega) en la que ocurrió; opcional',
  motivo                ENUM('producto_faltante','producto_danado','producto_incompleto','cambio_sustitucion','devolucion')
                        NOT NULL,
  estado                ENUM('abierta','resuelta','cancelada') NOT NULL DEFAULT 'abierta',
  notas                 VARCHAR(400) DEFAULT NULL,
  factura_requerida     TINYINT(1) NOT NULL DEFAULT 0
                        COMMENT 'Hay que cancelar la factura actual y subir la correcta',
  factura_documento_id  INT UNSIGNED DEFAULT NULL COMMENT 'Documento (XML/PDF) de la factura correcta',
  resolucion            VARCHAR(400) DEFAULT NULL,
  resuelta_en           DATETIME DEFAULT NULL,
  resuelta_por          INT UNSIGNED DEFAULT NULL,
  usuario_id            INT UNSIGNED NOT NULL,
  creado_en             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ei_venta (venta_id),
  KEY idx_ei_entrega (entrega_id),
  KEY idx_ei_estado (estado),
  KEY idx_ei_factura_documento (factura_documento_id),
  CONSTRAINT fk_ei_venta FOREIGN KEY (venta_id) REFERENCES ventas (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ei_entrega FOREIGN KEY (entrega_id) REFERENCES entregas (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ei_factura_documento FOREIGN KEY (factura_documento_id) REFERENCES documentos (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ei_resuelta_por FOREIGN KEY (resuelta_por) REFERENCES usuarios (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ei_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE entrega_incidencia_renglones (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  incidencia_id   INT UNSIGNED NOT NULL,
  producto_id     INT UNSIGNED NOT NULL,
  cantidad        INT NOT NULL COMMENT 'Piezas que regresaron al inventario (o no se entregarán)',
  requiere_cambio TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se le debe al cliente un reemplazo (descuento al entregarse)',
  descripcion     VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_eir_incidencia (incidencia_id),
  CONSTRAINT fk_eir_incidencia FOREIGN KEY (incidencia_id) REFERENCES entrega_incidencias (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_eir_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado inicial para ventas previas a esta migración: se imita la regla que
-- mantiene el código (Entregas::pendientesDeVentas + DevolucionesInventario):
--   con incidencias abiertas            → entregado_con_incidencias  (no debería ocurrir; las tablas están vacías)
--   algo pendiente de entregar          → pendiente
--   nada pendiente (incluida la entrega cerrada por devolución) → entregado
UPDATE ventas v
LEFT JOIN (
  SELECT va.venta_id,
         SUM(GREATEST(0,
             GREATEST(0, va.vendido - va.entregado)
             - GREATEST(0, va.devuelto - va.entregado))) AS pendiente
    FROM (
      SELECT vd.venta_id, vd.producto_id,
             COALESCE((SELECT SUM(c.cantidad) FROM venta_detalles c
                        WHERE c.venta_id = vd.venta_id AND c.producto_id = vd.producto_id), 0) AS vendido,
             COALESCE((SELECT SUM(er.cantidad) FROM entrega_renglones er
                        JOIN entregas en ON en.id = er.entrega_id
                       WHERE en.venta_id = vd.venta_id AND er.producto_id = vd.producto_id), 0) AS entregado,
             COALESCE((SELECT SUM(dv.cantidad_devuelta) FROM venta_devoluciones dv
                       WHERE dv.venta_id = vd.venta_id AND dv.producto_id = vd.producto_id), 0) AS devuelto
        FROM venta_detalles vd
       WHERE vd.venta_id IN (SELECT id FROM ventas WHERE estado = 'registrada')
       GROUP BY vd.venta_id, vd.producto_id
    ) va
   GROUP BY va.venta_id
) p ON p.venta_id = v.id
LEFT JOIN (
  SELECT venta_id, SUM(1) AS abiertas FROM entrega_incidencias WHERE estado = 'abierta' GROUP BY venta_id
) i ON i.venta_id = v.id
LEFT JOIN (
  SELECT venta_id, SUM(1) AS con_incidencia FROM entrega_incidencias WHERE estado <> 'cancelada' GROUP BY venta_id
) r ON r.venta_id = v.id
SET v.entrega_estado = CASE
    WHEN v.entrega_cerrada_en IS NULL AND IFNULL(p.pendiente, 0) > 0 THEN 'pendiente'
    WHEN i.venta_id IS NOT NULL THEN 'entregado_con_incidencias'
    WHEN r.venta_id IS NOT NULL THEN 'entregado_incidencias_resueltas'
    ELSE 'entregado'
END
WHERE v.estado = 'registrada';

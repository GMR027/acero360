-- ===========================================================================
-- 012_facturacion_comercial.sql — clasificación comercial de facturación y trazabilidad de la conversión
--   ventas.convertida_en / convertida_por   cuándo y quién convirtió la cotización en venta
--   ingresos.facturacion_*                  quién y cuándo fijó el estado, con qué CFDI, y si fue confirmación manual
--   estado_facturacion (clave interna 'sin_clasificar') se muestra ahora como «Pendiente de facturar»
-- La regla de IVA solo es la clasificación inicial: NO prueba que exista un CFDI emitido.
-- ===========================================================================
ALTER TABLE ventas
  ADD COLUMN convertida_en  DATETIME DEFAULT NULL COMMENT 'Cuándo la cotización pasó a venta' AFTER cotizacion_id,
  ADD COLUMN convertida_por INT UNSIGNED DEFAULT NULL COMMENT 'Usuario que hizo la conversión' AFTER convertida_en;

UPDATE ventas SET convertida_en = creado_en, convertida_por = usuario_id WHERE cotizacion_id IS NOT NULL AND convertida_en IS NULL;

ALTER TABLE ingresos
  ADD COLUMN facturacion_origen ENUM('regla_iva','usuario') NOT NULL DEFAULT 'usuario'
      COMMENT 'regla_iva = clasificación inicial automática; usuario = alguien la fijó o confirmó' AFTER fecha_facturacion,
  ADD COLUMN facturacion_manual TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = marcada como facturada sin CFDI verificable (confirmación manual)' AFTER facturacion_origen,
  ADD COLUMN factura_documento_id INT UNSIGNED DEFAULT NULL COMMENT 'CFDI adjunto que respalda la factura' AFTER facturacion_manual,
  ADD COLUMN facturacion_usuario_id INT UNSIGNED DEFAULT NULL AFTER factura_documento_id,
  ADD COLUMN facturacion_en DATETIME DEFAULT NULL AFTER facturacion_usuario_id;

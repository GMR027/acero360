-- ===========================================================================
-- 032_compras_inteligentes.sql — «Compras Inteligentes»: leer el XML de una
-- factura recibida y relacionarla con una compra (o iniciar una).
--
-- La lectura documental (lecturas_documentales) ya guarda el original, su
-- huella y los datos leídos; se le amplía el destino para aceptar «compra» y
-- la entidad para poder quedar confirmada CONTRA UNA ORDEN DE COMPRA (antes
-- solo podía quedar contra un ingreso o un gasto).
--
-- El XML recién se registra en la compra (y por tanto en contabilidad) cuando
-- la persona lo decide: analizar solo no escribe nada.
-- ===========================================================================
ALTER TABLE lecturas_documentales
  MODIFY destino ENUM('gasto','venta','compra') NOT NULL,
  MODIFY entidad ENUM('ingreso','gasto','orden_compra') DEFAULT NULL;

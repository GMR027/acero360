-- ===========================================================================
-- 036_complementos_clientes.sql — FASE 6: complementos de pago del lado de
-- CLIENTES (ventas), es decir los que respaldan cobros de ingresos.
--
--   lecturas_documentales.destino  nuevo valor 'complemento_venta' para que
--                                  la lectura de este lado no se mezcle con
--                                  los complementos de proveedores.
--   complementos_pago.lado         'proveedor' | 'cliente': el mismo encabezado
--                                  sirve a los dos lados y el lado evita que
--                                  una factura de venta se registre como
--                                  complemento de compra (y viceversa).
--   complemento_doctos.ingreso_id  cada documento relacionado puede apuntar a
--                                  un INGRESO (factura de venta) además de a
--                                  la compra; así no se crea venta ni ingreso
--                                  nuevos: sólo se relacionan los existentes.
--   complemento_cobros             vínculo N:M documento ↔ cobro: el mismo
--                                  cobro puede estar respaldado por dos
--                                  complementos y NUNCA se duplica un cobro
--                                  ya registrado (regla de la fase).
-- ===========================================================================

ALTER TABLE lecturas_documentales
  MODIFY destino ENUM('gasto','venta','compra','complemento','complemento_venta') NOT NULL;

ALTER TABLE complementos_pago
  ADD COLUMN lado ENUM('proveedor','cliente') NOT NULL DEFAULT 'proveedor' COMMENT 'A qué lado del negocio pertenece: compras o ventas' AFTER contribuyente_id,
  ADD KEY idx_complemento_lado (lado, estado);

ALTER TABLE complemento_doctos
  ADD COLUMN ingreso_id INT UNSIGNED DEFAULT NULL COMMENT 'Ingreso (venta) cuya factura relaciona este DoctoRelacionado' AFTER orden_id,
  ADD KEY idx_docto_ingreso (ingreso_id),
  ADD CONSTRAINT fk_docto_ingreso FOREIGN KEY (ingreso_id) REFERENCES ingresos (id) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE complemento_cobros (
  complemento_docto_id INT UNSIGNED NOT NULL,
  cobro_id             INT UNSIGNED NOT NULL,
  PRIMARY KEY (complemento_docto_id, cobro_id),
  KEY idx_ccobro_cobro (cobro_id),
  CONSTRAINT fk_ccobro_docto FOREIGN KEY (complemento_docto_id) REFERENCES complemento_doctos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ccobro_cobro FOREIGN KEY (cobro_id) REFERENCES cobros (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

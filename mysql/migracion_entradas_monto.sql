-- ===========================================================================
-- migracion_entradas_monto.sql
--
-- Agrega el importe de cada pedido a proveedor_entradas y la marca de pago.
--
--   monto   — costo del pedido (pesos mexicanos). Sin él no es posible armar
--             el "Balance general" del proveedor ni sumar lo que se debe.
--   pagado  — 1 cuando el pedido ya se liquidó. Es lo que permite separar
--             "lo que ya se pagó" de "lo que sigue pendiente" con un clic.
--
-- Reglas:
--   * monto es NOT NULL DEFAULT 0 (no NULL): así SUM() no necesita COALESCE
--     y las entradas viejas sin dato cuentan como $0.00 en lugar de romper
--     la suma.
--   * pagado arranca en 0: por defecto toda entrada está POR PAGAR. Es el
--     estado más prudente: asumir lo contrario haría que el contador de
--     pendientes mintiera hacia abajo.
--
-- La ejecución es idempotente gracias al procedimiento almacenado.
-- ===========================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS migrar_entradas_monto $$
CREATE PROCEDURE migrar_entradas_monto()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'proveedor_entradas'
           AND COLUMN_NAME  = 'monto'
    ) THEN
        ALTER TABLE proveedor_entradas
            ADD COLUMN monto DECIMAL(12,2) NOT NULL DEFAULT 0.00
                COMMENT 'Importe del pedido en pesos mexicanos (MXN)'
                AFTER categoria;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'proveedor_entradas'
           AND COLUMN_NAME  = 'pagado'
    ) THEN
        ALTER TABLE proveedor_entradas
            ADD COLUMN pagado TINYINT(1) NOT NULL DEFAULT 0
                COMMENT '1 = pedido liquidado, 0 = pendiente de pago'
                AFTER monto;
    END IF;
END $$
DELIMITER ;

CALL migrar_entradas_monto();
DROP PROCEDURE IF EXISTS migrar_entradas_monto;

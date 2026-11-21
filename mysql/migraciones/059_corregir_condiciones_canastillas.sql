-- ===========================================================================
-- 059_corregir_condiciones_canastillas.sql — Condiciones de envío cruzadas
--
-- El formulario de cotizaciones arrancaba con el primer negocio en orden
-- alfabético (Canastillas de la Baja) y, al cambiar a Soluciones en Acero,
-- no reemplazaba las condiciones por una comparación de saltos de línea.
-- Varias ventas y cotizaciones de Soluciones en Acero quedaron con el texto
-- de Canastillas de la Baja.
--
-- Sólo se corrigen los documentos cuyo texto es EXACTAMENTE el de la empresa
-- 2; los textos personalizados no se tocan. Antes de cambiar nada se guarda
-- una copia en condiciones_envio_respaldo_059.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS condiciones_envio_respaldo_059 (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tabla VARCHAR(20) NOT NULL,
    registro_id INT UNSIGNED NOT NULL,
    condiciones_envio TEXT NOT NULL,
    respaldado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_respaldo_059 (tabla, registro_id)
) ENGINE=InnoDB;

INSERT INTO condiciones_envio_respaldo_059 (tabla, registro_id, condiciones_envio)
SELECT 'ventas', v.id, v.condiciones_envio
  FROM ventas v
  JOIN empresas e1 ON e1.id = 1
  JOIN empresas e2 ON e2.id = 2
 WHERE v.empresa_id = 1
   AND v.condiciones_envio = e2.condiciones_envio
ON DUPLICATE KEY UPDATE condiciones_envio = VALUES(condiciones_envio), respaldado_en = NOW();

INSERT INTO condiciones_envio_respaldo_059 (tabla, registro_id, condiciones_envio)
SELECT 'cotizaciones', c.id, c.condiciones_envio
  FROM cotizaciones c
  JOIN empresas e1 ON e1.id = 1
  JOIN empresas e2 ON e2.id = 2
 WHERE c.empresa_id = 1
   AND c.condiciones_envio = e2.condiciones_envio
ON DUPLICATE KEY UPDATE condiciones_envio = VALUES(condiciones_envio), respaldado_en = NOW();

INSERT INTO condiciones_envio_respaldo_059 (tabla, registro_id, condiciones_envio)
SELECT 'pedidos', p.id, p.condiciones_envio
  FROM pedidos p
  JOIN empresas e1 ON e1.id = 1
  JOIN empresas e2 ON e2.id = 2
 WHERE p.empresa_id = 1
   AND p.condiciones_envio = e2.condiciones_envio
ON DUPLICATE KEY UPDATE condiciones_envio = VALUES(condiciones_envio), respaldado_en = NOW();

UPDATE ventas v
  JOIN empresas e1 ON e1.id = 1
  JOIN empresas e2 ON e2.id = 2
   SET v.condiciones_envio = e1.condiciones_envio
 WHERE v.empresa_id = 1
   AND v.condiciones_envio = e2.condiciones_envio;

UPDATE cotizaciones c
  JOIN empresas e1 ON e1.id = 1
  JOIN empresas e2 ON e2.id = 2
   SET c.condiciones_envio = e1.condiciones_envio
 WHERE c.empresa_id = 1
   AND c.condiciones_envio = e2.condiciones_envio;

UPDATE pedidos p
  JOIN empresas e1 ON e1.id = 1
  JOIN empresas e2 ON e2.id = 2
   SET p.condiciones_envio = e1.condiciones_envio
 WHERE p.empresa_id = 1
   AND p.condiciones_envio = e2.condiciones_envio;

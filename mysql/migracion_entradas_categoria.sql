-- ===========================================================================
-- migracion_entradas_categoria.sql
--
-- Cambia proveedor_entradas.categoria de TEXTO LIBRE a IDENTIFICADOR ENTERO
-- que apunta a una lista fija de categorías de compra.
--
--   0 → Sin categoría (registros antiguos o capturas incompletas)
--   1 → Acero
--   2 → Canastillas
--   3 → Empaque
--   4 → Mano de obra
--   5 → Servicio
--   6 → Otro
--
-- La lista vive hoy en la tabla `categorias_proveedor` (CRUD en
-- /admin/proveedores/categorias): es la ÚNICA fuente de verdad (formulario,
-- filtro, tablas y PDF la leen desde ahí). Esta migración sólo hace la
-- conversión de la columna; crear categorías nuevas es una operación de
-- usuario, no de base de datos.
--
-- POR QUÉ ENTERO Y NO TEXTO:
--   * Un campo libre produce "acero", "Acero" y "mat." → tres categorías que
--     en realidad son una, y un filtro que no cuadra con lo que se capturó.
--   * Con el id, filtrar es `categoria = 1` (índice, comparación entera) en
--     vez de `LIKE '%acero%'` sobre texto con acentos y mayúsculas.
--   * Renombrar "Mano de obra" por "Mano de obra y servicios" se hace en un
--     arreglo, sin `UPDATE` de cientos de filas.
--
-- SEGURO DE EJECUTAR VARIAS VECES: sólo convierte si la columna todavía es
-- VARCHAR (si ya es TINYINT no se vuelve a tocar, de modo que una segunda
-- pasada NUNCA pone en 0 las categorías ya elegidas).
-- ===========================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS migrar_entradas_categoria $$
CREATE PROCEDURE migrar_entradas_categoria()
BEGIN
    DECLARE tipo_actual VARCHAR(64);

    SELECT COLUMN_TYPE INTO tipo_actual
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'proveedor_entradas'
       AND COLUMN_NAME  = 'categoria';

    IF tipo_actual IS NOT NULL AND tipo_actual LIKE 'varchar%' THEN
        -- 1) Los textos que ya coinciden con la lista pasan a su id.
        --    Cualquier otro texto queda en 0 ("Sin categoría"): el texto no se
        --    puede guardar en un entero, y adivinarlo sería peor que dejarlo
        --    sin clasificar. Se reasigna desde la nueva lista desplegable.
        UPDATE proveedor_entradas
           SET categoria = CASE LOWER(TRIM(COALESCE(categoria, '')))
                             WHEN 'acero'        THEN '1'
                             WHEN 'canastillas'  THEN '2'
                             WHEN 'empaque'      THEN '3'
                             WHEN 'mano de obra' THEN '4'
                             WHEN 'servicio'     THEN '5'
                             WHEN 'otro'         THEN '6'
                             ELSE '0'
                           END;

        -- 2) Conversión del tipo. NOT NULL + DEFAULT 0: así SUM() y los
        --    filtros no tienen que lidiar con NULL y toda fila tiene un
        --    valor legible ("Sin categoría").
        ALTER TABLE proveedor_entradas
            MODIFY COLUMN categoria TINYINT UNSIGNED NOT NULL DEFAULT 0
                COMMENT 'Id en categorias_proveedor: 0 = sin categoría';
    END IF;
END $$
DELIMITER ;

CALL migrar_entradas_categoria();
DROP PROCEDURE IF EXISTS migrar_entradas_categoria;

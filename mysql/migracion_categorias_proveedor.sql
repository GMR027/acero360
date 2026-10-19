-- ===========================================================================
-- migracion_categorias_proveedor.sql
--
-- Convierte la lista fija de categorías de compra (que vivía en PHP como
-- una constante: ProveedorEntradas::CATEGORIAS) en una TABLA con su propio
-- CRUD: categorias_proveedor, manejada desde /admin/proveedores/categorias.
--
-- La lista sigue siendo ÚNICA para todos los proveedores (no es un catálogo
-- por proveedor): lo que se gestiona es el catálogo de "qué tipo de compra
-- estoy documentando", y ese concepto es de la empresa, no de quien surte.
--
-- Los ids 1..6 se insertan EXPLÍCITOS para que no cambie el significado de
-- ninguna fila ya escrita en proveedor_entradas.categoria.
--
-- POR QUÉ NO HAY FOREIGN KEY sobre proveedor_entradas.categoria:
--   * esa columna admite el 0 = "Sin categoría", y el 0 no es una fila de
--     esta tabla (el auto_increment empieza en 1): la clave foránea
--     rechazaría toda entrada sin clasificar.
--   * la protección equivalente se hace en PHP, ANTES de borrar:
--     CategoriasProveedor::eliminar() consulta cuántas entradas usan la
--     categoría y se niega si la respuesta es mayor que cero.
--   * se pierde la garantía a nivel de base de datos sólo en una carrera
--     entre dos administradores en la misma décima de segundo, que en una
--     aplicación de uso interno es un riesgo asumible frente al coste de
--     permitir NULL.
--
-- SEGURO DE EJECUTAR VARIAS VECES:
--   * CREATE TABLE IF NOT EXISTS
--   * la siembra sólo corre si la tabla está vacía (nunca duplica ni pisa).
-- ===========================================================================

CREATE TABLE IF NOT EXISTS categorias_proveedor (
    id        TINYINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '0 = sin categoría (no existe como fila)',
    nombre    VARCHAR(80)      NOT NULL COMMENT 'Nombre visible de la categoría de compra',
    creado_en DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Sólo el nombre debe repetirse en todo el catálogo: con la clave única
    -- "acero" y "Acero" no pueden convivir (la colación ya ignora mayúsculas).
    UNIQUE KEY uq_categorias_proveedor_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de categorías de compra, compartido por todos los proveedores';

DELIMITER $$

DROP PROCEDURE IF EXISTS sembrar_categorias_proveedor $$
CREATE PROCEDURE sembrar_categorias_proveedor()
BEGIN
    DECLARE total_filas INT DEFAULT 0;

    SELECT COUNT(*) INTO total_filas FROM categorias_proveedor;

    -- Sólo si la tabla está vacía: si el usuario ya creó o renombró las
    -- suyas, una segunda pasada no tiene nada que hacer.
    IF total_filas = 0 THEN
        INSERT INTO categorias_proveedor (id, nombre) VALUES
            (1, 'Acero'),
            (2, 'Canastillas'),
            (3, 'Empaque'),
            (4, 'Mano de obra'),
            (5, 'Servicio'),
            (6, 'Otro');
        -- Con ids explícitos, MySQL ya deja el auto_increment en 7 para la
        -- próxima categoría que se cree desde el CRUD.
    END IF;
END $$
DELIMITER ;

CALL sembrar_categorias_proveedor();
DROP PROCEDURE IF EXISTS sembrar_categorias_proveedor;

-- Reversión de 061_proyectos_entrega.sql — quita el vínculo y las tablas.
-- Las ventas y entregas se conservan; sólo se pierde la relación con el proyecto.

ALTER TABLE ventas
  DROP FOREIGN KEY fk_venta_proyecto,
  DROP INDEX idx_ventas_proyecto,
  DROP COLUMN proyecto_id;

DROP TABLE IF EXISTS proyecto_entrega_partidas;
DROP TABLE IF EXISTS proyectos_entrega;

DELETE FROM schema_migraciones WHERE nombre = '061_proyectos_entrega.sql';

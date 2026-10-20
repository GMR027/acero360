-- ===========================================================================
-- 011_seguimiento.sql — Centro de seguimiento (notificaciones)
-- Los avisos se calculan en vivo desde ventas, ingresos, gastos, entregas e inventario; NO se guardan.
-- Esta tabla solo recuerda qué avisos ya leyó cada usuario (clave del aviso + huella de su estado:
-- si la operación cambia, la huella cambia y el aviso vuelve a aparecer como nuevo).
-- Marcar como leído nunca toca la operación comercial.
-- ===========================================================================
CREATE TABLE seguimiento_lecturas (
  usuario_id INT UNSIGNED NOT NULL,
  clave      VARCHAR(60)  NOT NULL COMMENT 'tipo:id del registro de origen, p. ej. cobro:15',
  huella     VARCHAR(60)  NOT NULL COMMENT 'Estado del aviso cuando se leyó',
  leida_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, clave),
  CONSTRAINT fk_seg_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

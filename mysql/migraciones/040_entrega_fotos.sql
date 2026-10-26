-- ===========================================================================
-- 040_entrega_fotos.sql — Fotografías del acta de entrega de una venta
--
-- El formulario de entrega (página suelta /admin/entregas/entregar y el modal
-- «Registrar entrega» del seguimiento) admite adjuntar una o varias fotos de
-- lo entregado: quién recibe, el material en sitio, el estado en que llegó.
--
-- Las fotos cuelgan de `entregas` (no de `ventas`): una venta puede tener
-- varias entregas parciales y cada una lleva las suyas.
--
-- El archivo en disco NO vive aquí: se guarda en public/image con nombre
-- generado por el servidor (modo «informe» de la clase Imagenes: se reduce a
-- 1600 px conservando la proporción) y esta tabla sólo registra a qué entrega
-- pertenece, quién lo subió y cuándo. Al borrar la entrega sus fotos quedan
-- fuera del registro; el código retira los archivos.
-- ===========================================================================

CREATE TABLE entrega_fotos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entrega_id INT UNSIGNED NOT NULL,
  archivo    VARCHAR(150) NOT NULL COMMENT 'Nombre del archivo en public/image',
  usuario_id INT UNSIGNED NOT NULL COMMENT 'Quién subió la fotografía',
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ef_entrega (entrega_id),
  CONSTRAINT fk_ef_entrega FOREIGN KEY (entrega_id) REFERENCES entregas (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ef_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 053_catalogo_web.sql — Catálogo público del sitio de Soluciones en Acero.
--
-- El sitio público (otra aplicación sobre esta base, ver GUIA.md) muestra los
-- productos y categorías desde aquí, en vez de tenerlos copiados en archivos.
--
-- • productos.publicado_web: el producto sale en el catálogo público. Se
--   activa a mano desde el formulario del producto. Se marcan de inicio los
--   productos activos de «Soluciones en Acero» (salvo la categoría de pruebas).
-- • categorias_producto.slug: identificador para la URL (/productos/<slug>).
--   Se genera al crear la categoría y NO cambia al renombrarla.
-- • categorias_producto.descripcion / imagen / orden: texto, portada y orden
--   en el sitio. Sin imagen, el sitio usa la del primer producto.
-- ===========================================================================

ALTER TABLE productos
    ADD COLUMN publicado_web TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = aparece en el catálogo público del sitio' AFTER activo;

UPDATE productos p
  JOIN empresas e ON e.id = p.empresa_id
   SET p.publicado_web = 1
 WHERE e.nombre = 'Soluciones en Acero'
   AND p.activo = 1
   AND (p.categoria IS NULL OR p.categoria <> 'Pruebas de empleado');

ALTER TABLE categorias_producto
    ADD COLUMN slug VARCHAR(90) DEFAULT NULL
        COMMENT 'Identificador para la URL del sitio público' AFTER nombre,
    ADD COLUMN descripcion VARCHAR(255) DEFAULT NULL
        COMMENT 'Texto de la categoría en el sitio público' AFTER slug,
    ADD COLUMN imagen VARCHAR(255) DEFAULT NULL
        COMMENT 'Portada en el sitio (archivo en public/image)' AFTER descripcion,
    ADD COLUMN orden SMALLINT NOT NULL DEFAULT 0
        COMMENT 'Orden en el sitio (menor primero)' AFTER imagen;

-- Slug: minúsculas, sin acentos, guiones en lugar de espacios y símbolos.
UPDATE categorias_producto
   SET slug = TRIM(BOTH '-' FROM REGEXP_REPLACE(
       REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(nombre),
           'á', 'a'), 'é', 'e'), 'í', 'i'), 'ó', 'o'), 'ú', 'u'), 'ü', 'u'), 'ñ', 'n'),
       '[^a-z0-9]+', '-'));

UPDATE categorias_producto SET slug = CONCAT('categoria-', id) WHERE slug IS NULL OR slug = '';

-- Si dos nombres dan el mismo slug, a partir del segundo se le agrega el id.
UPDATE categorias_producto c
  JOIN (SELECT slug, MIN(id) AS primero FROM categorias_producto GROUP BY slug HAVING COUNT(*) > 1) d
    ON d.slug = c.slug AND c.id <> d.primero
   SET c.slug = CONCAT(c.slug, '-', c.id);

ALTER TABLE categorias_producto ADD UNIQUE KEY uq_categorias_producto_slug (slug);

-- Textos y orden iniciales de las líneas de Soluciones en Acero (si existen).
UPDATE categorias_producto SET orden = 1, descripcion = COALESCE(descripcion,
       'Marco y contramarco de ángulo con armado de Varillatec. Para instalaciones eléctricas, sanitarias y cisternas.')
 WHERE slug = 'registros-de-angulo';
UPDATE categorias_producto SET orden = 2, descripcion = COALESCE(descripcion,
       'Solera de 2" para cargas mayores. La opción para cisternas y tráfico pesado.')
 WHERE slug = 'registros-de-solera';
UPDATE categorias_producto SET orden = 3, descripcion = COALESCE(descripcion,
       'Tapas sencillas y reforzadas de 60x60 y 70x70 cm para cisternas, incluidas Rotoplas.')
 WHERE slug = 'tapas-metalicas';
UPDATE categorias_producto SET orden = 4, descripcion = COALESCE(descripcion,
       'Base de lámina con soportes de ángulo para boilers y bombas hidráulicas.')
 WHERE slug = 'bases';

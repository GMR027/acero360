<?php
/**
 * views/admin/productos/pdf.php — "Exportar catálogo de productos".
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Genera un único PDF con una tabla por negocio (imagen, clave, nombre y
 * costo) y la leyenda fiscal al pie. Se abre en una pestaña nueva.
 *
 * Exporta el catálogo COMPLETO: no respeta los filtros de la pantalla porque
 * el botón vive en la barra de acciones, fuera del formulario de búsqueda.
 */

soloAdmin();

\App\Documentos::descargarCatalogo();

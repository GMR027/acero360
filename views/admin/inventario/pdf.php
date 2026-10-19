<?php
/**
 * views/admin/inventario/pdf.php — "Exportar Inventario".
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Genera un único PDF con una tabla por empresa activa (encabezado con
 * fecha de exportación, logotipo y nombre) y los totales de cantidad e
 * importe al pie de cada tabla. Se abre en una pestaña nueva.
 */

soloAdmin();

\App\Documentos::descargarInventario();

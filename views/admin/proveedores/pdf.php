<?php
/**
 * views/admin/proveedores/pdf.php — "Exportar datos".
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Genera un único PDF con las entradas de TODOS los proveedores durante el
 * año activo: una sección por proveedor con sus entregas, montos y estado
 * de pago, más un resumen consolidado. Se abre en una pestaña nueva.
 */

soloAdmin();

\App\Documentos::descargarBalanceProveedores((int) date('Y'));

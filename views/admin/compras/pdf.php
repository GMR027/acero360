<?php
/**
 * views/admin/compras/pdf.php — PDF de la orden o del reporte de cierre.
 * Solo lectura: no cambia inventario ni estados.
 */

soloAdmin();

\App\Documentos::descargarOrdenCompra((int) ($_GET['id'] ?? 0), (string) ($_GET['tipo'] ?? 'orden'));

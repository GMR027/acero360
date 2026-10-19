<?php
/**
 * views/admin/ventas/pdf.php — Exportación de una venta directa a PDF.
 *
 * Ruta protegida por el middleware "soloAdmin". Usa la misma plantilla
 * que la cotización (templates/pdfCotizaciones.php).
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? 0);
$venta = \App\Ventas::buscarID($id);

if (!$venta) {
    paginaNoEncontrada();
}

\App\Documentos::descargar($venta, \App\Ventas::detalles($id));

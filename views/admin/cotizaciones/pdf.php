<?php
/**
 * views/admin/cotizaciones/pdf.php — Exportación de la cotización a PDF.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Se abre en una pestaña nueva desde el detalle; el PDF se genera con dompdf
 * a partir de templates/pdfCotizaciones.php (la misma plantilla que adjunta
 * el correo, para que ambos documentos sean idénticos).
 */

soloAdmin();

$id          = (int) ($_GET['id'] ?? 0);
$cotizacion  = \App\Cotizaciones::buscarID($id);

if (!$cotizacion) {
    // Igual que el resto del panel: un folio inexistente es una 404.
    paginaNoEncontrada();
}

$detalles = \App\Cotizaciones::detalles($id);

\App\Documentos::descargar($cotizacion, $detalles);

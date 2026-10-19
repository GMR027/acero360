<?php
/**
 * views/admin/cotizaciones/eliminar.php — Baja de una cotización.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST:
 * nunca se elimina con un simple enlace, siempre con confirmación modal.
 *
 * Los precios especiales del cliente NO se borran: son acuerdos comerciales
 * que siguen vigentes aunque se retire la cotización.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id   = (int) ($_POST['id'] ?? 0);
$previa = \App\Cotizaciones::buscarID($id);

if (!$previa) {
    redirigir('/admin/cotizaciones', 'error', 'La cotización seleccionada ya no existe.');
}

$folio = $previa->folio;

if (\App\Cotizaciones::eliminar($id)) {
    redirigir('/admin/cotizaciones', 'exito', 'Cotización ' . $folio . ' eliminada.');
}

redirigir(
    '/admin/cotizaciones',
    'error',
    \App\Cotizaciones::getErrores()[0] ?? 'No fue posible eliminar la cotización.'
);

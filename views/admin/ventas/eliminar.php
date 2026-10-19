<?php
/**
 * views/admin/ventas/eliminar.php — Baja de una venta directa.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 * Si la venta seguía registrada, el inventario recupera sus piezas.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id     = (int) ($_POST['id'] ?? 0);
$previa = \App\Ventas::buscarID($id);

if (!$previa) {
    redirigir('/admin/ventas', 'error', 'La venta seleccionada ya no existe.');
}

$folio = $previa->folio;

if (\App\Ventas::eliminar($id, (int) ($_SESSION['id'] ?? 0))) {
    redirigir('/admin/ventas', 'exito', 'Venta ' . $folio . ' eliminada.');
}

redirigir(
    '/admin/ventas',
    'error',
    \App\Ventas::getErrores()[0] ?? 'No fue posible eliminar la venta.'
);

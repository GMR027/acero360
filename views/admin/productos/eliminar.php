<?php
/**
 * views/admin/productos/eliminar.php — Baja de un producto.
 *
 * Ruta accesible sólo por POST (declarada así en index.php).
 * Si el producto ya se usó en una cotización, MySQL impide el borrado y se
 * recomienda desactivarlo en su lugar.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$producto = \App\Productos::buscarID((int) ($_POST['id'] ?? 0));

if (!$producto) {
    redirigir('/admin/productos', 'error', 'El producto seleccionado ya no existe.');
}

$nombre = $producto->nombre;

if ($producto->eliminar()) {
    redirigir('/admin/productos', 'exito', "Se eliminó \"{$nombre}\" del catálogo.");
}

$errores = \App\Productos::getErrores();
redirigir('/admin/productos', 'error', $errores[0] ?? 'No fue posible eliminar el producto.');

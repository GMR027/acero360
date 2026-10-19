<?php
/**
 * views/admin/proveedores/categorias/eliminar.php — Baja de categorías.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 *
 * A diferencia de los proveedores, AQUÍ no hay cascada: no existe clave
 * foránea (la columna `categoria` admite el 0, que no es una fila del
 * catálogo), así que CategoriasProveedor::eliminar() es quien decide. Si la
 * categoría está en uso se niega y el motivo viaja en una alerta de error.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id = (int) ($_POST['id'] ?? 0);

$categoria = \App\CategoriasProveedor::buscarID($id);

if (!$categoria) {
    redirigir('/admin/proveedores/categorias', 'error', 'La categoría seleccionada ya no existe.');
}

$nombre = $categoria->nombre;

if ($categoria->eliminar()) {
    redirigir('/admin/proveedores/categorias', 'exito', 'Categoría «' . $nombre . '» eliminada.');
}

$errores = \App\CategoriasProveedor::getErrores();
redirigir('/admin/proveedores/categorias', 'error', $errores[0] ?? 'No fue posible eliminar la categoría.');

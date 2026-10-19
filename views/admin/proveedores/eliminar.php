<?php
/**
 * views/admin/proveedores/eliminar.php — Baja de un proveedor.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 * Las entradas asociadas se eliminan en cascada (el modal de confirmación
 * lo advierte antes de que el usuario acepte).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id = (int) ($_POST['id'] ?? 0);

$proveedor = \App\Proveedores::buscarID($id);

if (!$proveedor) {
    redirigir('/admin/proveedores', 'error', 'El proveedor seleccionado ya no existe.');
}

$nombre = $proveedor->nombre;

if ($proveedor->eliminar()) {
    redirigir('/admin/proveedores', 'exito', 'Proveedor «' . $nombre . '» eliminado junto con sus entradas.');
}

$errores = \App\Proveedores::getErrores();
redirigir('/admin/proveedores', 'error', $errores[0] ?? 'No fue posible eliminar el proveedor.');

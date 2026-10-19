<?php
/**
 * views/admin/proveedores/entradas/eliminar.php — Baja de una entrada.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 * La fotografía asociada se borra del disco tras confirmar la escritura.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id = (int) ($_POST['id'] ?? 0);

$entrada = \App\ProveedorEntradas::buscarID($id);

if (!$entrada) {
    redirigir('/admin/proveedores/entradas', 'error', 'La entrada seleccionada ya no existe.');
}

$proveedorId = (int) $entrada->proveedor_id;

if ($entrada->eliminar()) {
    redirigir(
        '/admin/proveedores/entradas?proveedor=' . $proveedorId,
        'exito',
        'Entrada eliminada correctamente.'
    );
}

$errores = \App\ProveedorEntradas::getErrores();
redirigir(
    '/admin/proveedores/entradas?proveedor=' . $proveedorId,
    'error',
    $errores[0] ?? 'No fue posible eliminar la entrada.'
);

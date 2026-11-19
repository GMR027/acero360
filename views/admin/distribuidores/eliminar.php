<?php
/** views/admin/distribuidores/eliminar.php — Baja de un distribuidor (y de su fachada). Sólo POST. */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$distribuidor = \App\Distribuidores::buscarID((int) ($_POST['id'] ?? 0));
if (!$distribuidor) {
    redirigir('/admin/distribuidores', 'error', 'El distribuidor seleccionado ya no existe.');
}

if ($distribuidor->eliminar()) {
    redirigir('/admin/distribuidores', 'exito', 'Distribuidor «' . $distribuidor->nombre . '» eliminado.');
}

$errores = \App\Distribuidores::getErrores();
redirigir('/admin/distribuidores', 'error', $errores[0] ?? 'No fue posible eliminar el distribuidor.');

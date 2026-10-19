<?php
/**
 * views/admin/empresas/eliminar.php — Baja de un negocio.
 *
 * Ruta accesible sólo por POST (declarada así en index.php).
 * MySQL impide el borrado si el negocio ya tiene productos o cotizaciones.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$empresa = \App\Empresas::buscarID((int) ($_POST['id'] ?? 0));

if (!$empresa) {
    redirigir('/admin/empresas', 'error', 'El negocio seleccionado ya no existe.');
}

$nombre = $empresa->nombre;

if ($empresa->eliminar()) {
    redirigir('/admin/empresas', 'exito', "Se eliminó el negocio \"{$nombre}\".");
}

$errores = \App\Empresas::getErrores();
redirigir('/admin/empresas', 'error', $errores[0] ?? 'No fue posible eliminar el negocio.');

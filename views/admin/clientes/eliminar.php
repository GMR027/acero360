<?php
/**
 * views/admin/clientes/eliminar.php — Baja de un cliente.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST
 * (declarada así en index.php), así que no puede borrarse con un enlace.
 *
 * La confirmación visual la hace el modal de src/js/modulos/modal.js a
 * partir de los atributos data-confirmacion del formulario; aquí sólo se
 * ejecuta la operación.
 */

soloAdmin();

// Un GET a esta ruta no tiene sentido: se trata como página no encontrada.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$cliente = \App\Clientes::buscarID($id);

if (!$cliente) {
    redirigir('/admin/clientes', 'error', 'El cliente seleccionado ya no existe.');
}

$nombre = $cliente->nombre;

if ($cliente->eliminar()) {
    redirigir('/admin/clientes', 'exito', "Se eliminó la ficha de \"{$nombre}\".");
}

// Si no se pudo borrar (tiene cotizaciones), se devuelve el motivo.
$errores = \App\Clientes::getErrores();
redirigir('/admin/clientes', 'error', $errores[0] ?? 'No fue posible eliminar el cliente.');

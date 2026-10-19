<?php
/**
 * views/admin/usuarios/eliminar.php — Baja de un administrador.
 *
 * Ruta accesible sólo por POST (declarada así en index.php).
 * Protecciones aplicadas por App\Usuarios::eliminar():
 *   - no puedes borrarte a ti mismo
 *   - debe quedar al menos un administrador
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$usuario = \App\Usuarios::buscarID($id);

if (!$usuario) {
    redirigir('/admin/usuarios', 'error', 'El usuario seleccionado ya no existe.');
}

$nombre    = $usuario->nombre;
$sesionId  = (int) ($_SESSION['id'] ?? 0);

if ($usuario->eliminar($sesionId)) {
    redirigir('/admin/usuarios', 'exito', "Se eliminó el acceso de \"{$nombre}\".");
}

$errores = \App\Usuarios::getErrores();
redirigir('/admin/usuarios', 'error', $errores[0] ?? 'No fue posible eliminar el usuario.');

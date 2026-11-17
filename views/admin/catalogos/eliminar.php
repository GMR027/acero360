<?php
/** views/admin/catalogos/eliminar.php — Baja en un catálogo de texto ($catalogo). Sólo POST. */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$c        = \App\CatalogoTexto::config($catalogo);
$registro = \App\CatalogoTexto::buscarID($catalogo, (int) ($_POST['id'] ?? 0));

if (!$registro) {
    redirigir($c['ruta'], 'error', 'La ' . $c['singular'] . ' seleccionada ya no existe.');
}

if ($registro->eliminar()) {
    redirigir($c['ruta'], 'exito', ucfirst($c['singular']) . ' «' . $registro->nombre . '» eliminada.');
}

$errores = \App\CatalogoTexto::getErrores();
redirigir($c['ruta'], 'error', $errores[0] ?? 'No fue posible eliminarla.');

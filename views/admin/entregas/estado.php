<?php
/**
 * views/admin/entregas/estado.php — Cambia el estado de una preparación (POST).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$estado  = (string) ($_POST['estado'] ?? '');
$destino = '/admin/entregas/detalle?id=' . $id;

if (!\App\Entregas::buscarPreparacion($id)) {
    redirigir('/admin/entregas', 'error', 'La preparación ya no existe.');
}

if (\App\Entregas::cambiarEstado($id, $estado, (int) ($_SESSION['id'] ?? 0))) {
    redirigir($destino, 'exito', 'Estado actualizado: ' . (\App\Entregas::ESTADOS[$estado]['etiqueta'] ?? $estado) . '.');
}

redirigir($destino, 'error', \App\Entregas::getErrores()[0] ?? 'No fue posible cambiar el estado.');

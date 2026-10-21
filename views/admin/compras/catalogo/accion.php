<?php
/**
 * views/admin/compras/catalogo/accion.php — Activar/desactivar y relacionar con productos propios (solo POST).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$accion  = (string) ($_POST['accion'] ?? '');
$usuario = (int) ($_SESSION['id'] ?? 0);
$destino = (string) ($_POST['retorno'] ?? '') === 'lista' ? '/admin/compras/catalogo' : '/admin/compras/catalogo/detalle?id=' . $id;

if (!\App\ProductosProveedor::buscarID($id)) {
    redirigir('/admin/compras/catalogo', 'error', 'El producto ya no existe.');
}

$producto = (int) ($_POST['producto'] ?? 0);
$ok = match ($accion) {
    'desactivar'   => \App\ProductosProveedor::cambiarEstado($id, false, $usuario),
    'activar'      => \App\ProductosProveedor::cambiarEstado($id, true, $usuario),
    'relacionar'   => \App\ProductosProveedor::relacionar($id, $producto, $usuario),
    'desrelacionar' => \App\ProductosProveedor::desrelacionar($id, $producto, $usuario),
    default        => false,
};
$mensajes = [
    'desactivar' => 'Producto desactivado: ya no se ofrece en compras nuevas.',
    'activar' => 'Producto reactivado.',
    'relacionar' => 'Relación guardada.',
    'desrelacionar' => 'Relación quitada.',
];

if ($ok) {
    redirigir($destino, 'exito', $mensajes[$accion]);
}
redirigir($destino, 'error', \App\ProductosProveedor::getErrores()[0] ?? 'Acción no reconocida.');

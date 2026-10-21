<?php
/**
 * views/admin/compras/accion.php — cerrar, reabrir, cancelar o relacionar el producto de una compra (solo POST).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$accion  = (string) ($_POST['accion'] ?? '');
$usuario = (int) ($_SESSION['id'] ?? 0);
$destino = '/admin/compras/detalle?id=' . $id;

if (!\App\OrdenesCompra::buscarID($id)) {
    redirigir('/admin/compras/pedidos', 'error', 'La compra ya no existe.');
}

$mensajes = [
    'cerrar'   => 'Compra cerrada. Descarga el reporte comprado vs. recibido para revisarlo antes del pago.',
    'reabrir'  => 'Compra reabierta. El motivo quedó registrado en la bitácora.',
    'cancelar' => 'Compra cancelada.',
    'relacionar' => 'Producto relacionado. Ya se puede recibir esa partida.',
];

$ok = match ($accion) {
    'cerrar'   => \App\OrdenesCompra::cerrar($id, (string) ($_POST['motivo'] ?? ''), $usuario),
    'reabrir'  => \App\OrdenesCompra::reabrir($id, (string) ($_POST['motivo'] ?? ''), $usuario),
    'cancelar' => \App\OrdenesCompra::cancelar($id, $usuario),
    'relacionar' => \App\OrdenesCompra::relacionarProducto((int) ($_POST['partida'] ?? 0), (int) ($_POST['producto_id'] ?? 0), $usuario),
    default    => false,
};

if ($ok) {
    redirigir($destino, 'exito', $mensajes[$accion]);
}

redirigir($destino, 'error', \App\OrdenesCompra::getErrores()[0] ?? 'Acción no reconocida.');

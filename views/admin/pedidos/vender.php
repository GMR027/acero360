<?php
/** views/admin/pedidos/vender.php — Acción POST sobre un pedido (ver App\Pedidos). */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$pedido = \App\Pedidos::buscarID((int) ($_POST['id'] ?? 0));
if (!$pedido) {
    redirigir('/admin/pedidos', 'error', 'El pedido ya no existe.');
}

$usuarioId = (int) ($_SESSION['id'] ?? 0);
$volver = !empty($_POST['volver']) ? '/admin/pedidos/detalle?id=' . (int) $pedido->id : '/admin/pedidos?empresa=' . (int) $pedido->empresa_id;

if (\App\Pedidos::marcarVenta((int) $pedido->id, $usuarioId)) {
    redirigir($volver, 'exito', 'Pedido ' . $pedido->folio . ' marcado como venta.');
}

$errores = \App\Pedidos::getErrores();
redirigir($volver, 'error', implode(' ', $errores) ?: 'No fue posible completar la acción.');

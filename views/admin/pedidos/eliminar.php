<?php
/**
 * views/admin/pedidos/eliminar.php — Baja de un pedido sin venta vinculada.
 *
 * Sólo POST. El pedido desaparece también del portal del cliente; la
 * cotización generada, si existe, se conserva.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$pedido = \App\Pedidos::buscarID((int) ($_POST['id'] ?? 0));
if (!$pedido) {
    redirigir('/admin/pedidos', 'error', 'El pedido ya no existe.');
}

$folio = $pedido->folio;
$cotizacion = trim((string) $pedido->cotizacion_folio);

if (\App\Pedidos::eliminar((int) $pedido->id)) {
    $mensaje = 'Pedido ' . $folio . ' eliminado. También dejó de aparecer en el portal del cliente.';
    if ($cotizacion !== '') {
        $mensaje .= ' La cotización ' . $cotizacion . ' se conserva.';
    }
    redirigir('/admin/pedidos?empresa=' . (int) $pedido->empresa_id, 'exito', $mensaje);
}

$errores = \App\Pedidos::getErrores();
redirigir(
    '/admin/pedidos/detalle?id=' . (int) $pedido->id,
    'error',
    implode(' ', $errores) ?: 'No fue posible eliminar el pedido.'
);

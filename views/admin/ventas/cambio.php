<?php
/**
 * views/admin/ventas/cambio.php — marca como entregado (total o parcial) el cambio de una pieza devuelta (solo POST).
 * Al entregarlo se descuenta la pieza del inventario.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$destino = '/admin/ventas/detalle?id=' . $id . '#cambios';

if (!consumirToken($_POST['token'] ?? null)) {
    redirigir($destino, 'error', 'Este formulario ya se envió; la entrega no se registró dos veces.');
}

if (\App\Inventario::entregarCambio(
    $id, (int) ($_POST['producto_id'] ?? 0), (int) ($_POST['cantidad'] ?? 0),
    (string) ($_POST['fecha'] ?? date('Y-m-d')), (int) ($_SESSION['id'] ?? 0)
)) {
    redirigir($destino, 'exito', 'Cambio entregado: las piezas se descontaron del inventario.');
}

redirigir($destino, 'error', \App\Inventario::getErrores()[0] ?? 'No fue posible registrar la entrega del cambio.');

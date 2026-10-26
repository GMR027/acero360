<?php
/**
 * views/admin/ventas/cambio.php — marca como entregado (total o parcial) el cambio de una pieza devuelta (solo POST).
 * Es una NOTIFICACIÓN: salda la deuda con el cliente y NO modifica el inventario.
 * Con `volver=seguimiento` (modal del hub) el destino es el seguimiento de la venta.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id        = (int) ($_POST['id'] ?? 0);
// El único formulario de cambio vive en el hub de Operación Comercial; el
// destino por defecto es el seguimiento de la venta (el detalle ya no lo trae).
$destino   = '/admin/entregas-seguimiento/venta?id=' . $id . '#cambios';

if (!consumirToken($_POST['token'] ?? null)) {
    redirigir($destino, 'error', 'Este formulario ya se envió; la entrega no se registró dos veces.');
}

if (\App\Inventario::entregarCambio(
    $id, (int) ($_POST['producto_id'] ?? 0), (int) ($_POST['cantidad'] ?? 0),
    (string) ($_POST['fecha'] ?? date('Y-m-d')), (int) ($_SESSION['id'] ?? 0)
)) {
    redirigir($destino, 'exito', 'Cambio entregado: quedó saldado con el cliente (el inventario no cambia).');
}

redirigir($destino, 'error', \App\Inventario::getErrores()[0] ?? 'No fue posible registrar la entrega del cambio.');

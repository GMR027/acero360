<?php
/**
 * views/admin/compras/pago.php — Pagos a proveedores (solo POST).
 *
 *   accion=registrar  pago parcial o total; reduce el saldo y NO crea gastos (el token impide duplicarlo al reenviar)
 *   accion=cancelar   cancela un pago con motivo (no se borra)
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$accion  = (string) ($_POST['accion'] ?? '');
$usuario = (int) ($_SESSION['id'] ?? 0);
$destino = '/admin/compras/detalle?id=' . $id . '#pagos';

if (!\App\OrdenesCompra::buscarID($id)) {
    redirigir('/admin/compras/pedidos', 'error', 'La compra ya no existe.');
}

if ($accion === 'registrar') {
    if (\App\PagosCompra::registrar(
        $id,
        (string) ($_POST['fecha'] ?? ''),
        (string) ($_POST['importe'] ?? ''),
        (string) ($_POST['metodo'] ?? ''),
        (string) ($_POST['referencia'] ?? ''),
        (string) ($_POST['token'] ?? ''),
        $usuario
    )) {
        redirigir($destino, 'exito', 'Pago registrado. El saldo pendiente se actualizó.');
    }
    redirigir($destino, 'error', \App\PagosCompra::getErrores()[0] ?? 'No fue posible registrar el pago.');
}

if ($accion === 'cancelar') {
    if (\App\PagosCompra::cancelar((int) ($_POST['pago'] ?? 0), (string) ($_POST['motivo'] ?? ''), $usuario)) {
        redirigir($destino, 'exito', 'Pago cancelado. El saldo pendiente se actualizó.');
    }
    redirigir($destino, 'error', \App\PagosCompra::getErrores()[0] ?? 'No fue posible cancelar el pago.');
}

redirigir($destino, 'error', 'Acción no reconocida.');

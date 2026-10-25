<?php
/**
 * views/admin/ventas/inteligente-cliente.php — Alta rápida de cliente (FASE 9).
 *
 * Recibe el modal de «Registro Venta Inteligente» cuando el receptor de la
 * factura no existe como cliente: crea la ficha con los datos del documento
 * (RFC cotejado contra el XML) y REGRESA a la venta, que ya queda con el
 * cliente seleccionado. Nada se guarda si el RFC ya existía: se reutiliza.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
    redirigir('/admin/ventas', 'error', 'Usa el botón «Registrar cliente» de la venta inteligente.');
}

$lecturaId = (int) ($_POST['lectura'] ?? 0);
$destino = '/admin/ventas/crear?lectura=' . $lecturaId;
if ($lecturaId <= 0) {
    redirigir('/admin/ventas', 'error', 'Falta la factura de origen del cliente.');
}
if (!consumirToken($_POST['token'] ?? null)) {
    redirigir($destino, 'error', 'Este formulario ya se envió; no se guardó dos veces.');
}

$usuario = (int) ($_SESSION['id'] ?? 0);
$resultado = \App\Lecturas::altaClienteDesdeLectura($lecturaId, $_POST, $usuario);

if ($resultado['ok']) {
    if (!empty($resultado['ya_existia'])) {
        redirigir($destino, 'exito', 'Ese RFC ya tenía ficha de cliente: la venta quedó con ese cliente.');
    }
    redirigir($destino, 'exito', 'Cliente creado con los datos de la factura y seleccionado en la venta.');
}

redirigir($destino, 'error', implode(' · ', $resultado['errores']) ?: 'No fue posible registrar el cliente.');

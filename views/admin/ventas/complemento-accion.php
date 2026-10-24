<?php
/**
 * views/admin/ventas/complemento-accion.php — Acciones sobre complementos de pago de clientes (solo POST).
 *
 *   accion=registrar  confirma el complemento y relaciona cada factura con sus
 *                     cobros (sin crear ventas y sin duplicar cobros)
 *   accion=cancelar   cancela un complemento ya registrado (no se borra)
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}
if (!consumirToken($_POST['token'] ?? null)) {
    redirigir('/admin/ventas/complemento', 'error', 'Este formulario ya se envió; la acción no se repitió.');
}

$accion = (string) ($_POST['accion'] ?? '');
$usuario = (int) ($_SESSION['id'] ?? 0);

if ($accion === 'registrar') {
    $lecturaId = (int) ($_POST['lectura'] ?? 0);
    if ($lecturaId <= 0) {
        redirigir('/admin/ventas/complemento', 'error', 'No indicó qué complemento procesar.');
    }
    if (\App\ComplementosVenta::registrar($lecturaId, $_POST, $usuario)) {
        // registrar() deja su mensaje de éxito en errores: se muestra como éxito.
        $mensaje = implode(' · ', \App\ComplementosVenta::getErrores()) ?: 'Complemento registrado.';
        redirigir('/admin/ventas/complemento', 'exito', $mensaje);
    }
    redirigir('/admin/ventas/complemento/revision?id=' . $lecturaId, 'error',
        implode(' · ', \App\ComplementosVenta::getErrores()) ?: 'No fue posible registrar el complemento.');
}

if ($accion === 'cancelar') {
    $complementoId = (int) ($_POST['complemento'] ?? 0);
    $motivo = (string) ($_POST['motivo'] ?? '');
    if (\App\ComplementosVenta::cancelar($complementoId, $motivo, $usuario)) {
        redirigir('/admin/ventas/complemento', 'exito', 'Complemento cancelado: se desvincularon sus cobros.');
    }
    redirigir('/admin/ventas/complemento', 'error',
        implode(' · ', \App\ComplementosVenta::getErrores()) ?: 'No fue posible cancelar el complemento.');
}

redirigir('/admin/ventas/complemento', 'error', 'Acción no reconocida.');

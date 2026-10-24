<?php
/**
 * views/admin/compras/complemento-accion.php — Acciones sobre un complemento de pago (solo POST).
 *
 *   accion=registrar  confirma el complemento y vincula las facturas elegidas
 *   accion=cancelar   cancela un complemento ya registrado (no se borra)
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}
if (!consumirToken($_POST['token'] ?? null)) {
    redirigir('/admin/compras/complemento', 'error', 'Este formulario ya se envió; la acción no se repitió.');
}

$accion = (string) ($_POST['accion'] ?? '');
$usuario = (int) ($_SESSION['id'] ?? 0);

if ($accion === 'registrar') {
    $lecturaId = (int) ($_POST['lectura'] ?? 0);
    if ($lecturaId <= 0) {
        redirigir('/admin/compras/complemento', 'error', 'No indicó qué complemento procesar.');
    }
    if (\App\ComplementosPago::registrar($lecturaId, $_POST, $usuario)) {
        // registrar() deja su mensaje de éxito en errores; se muestra como éxito.
        $mensaje = implode(' · ', \App\ComplementosPago::getErrores()) ?: 'Complemento registrado.';
        redirigir('/admin/compras/complemento', 'exito', $mensaje);
    }
    redirigir('/admin/compras/complemento/revision?id=' . $lecturaId, 'error',
        implode(' · ', \App\ComplementosPago::getErrores()) ?: 'No fue posible registrar el complemento.');
}

if ($accion === 'cancelar') {
    $complementoId = (int) ($_POST['complemento'] ?? 0);
    $motivo = (string) ($_POST['motivo'] ?? '');
    if (\App\ComplementosPago::cancelar($complementoId, $motivo, $usuario)) {
        redirigir('/admin/compras/complemento', 'exito', 'Complemento cancelado y sus vínculos deshechos.');
    }
    redirigir('/admin/compras/complemento', 'error', implode(' · ', \App\ComplementosPago::getErrores()) ?: 'No fue posible cancelar.');
}

redirigir('/admin/compras/complemento', 'error', 'Acción no reconocida.');

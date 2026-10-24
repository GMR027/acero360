<?php
/**
 * views/admin/compras/inteligente-accion.php — Acciones de la decisión sobre una factura (solo POST).
 *
 *   accion=relacionar           adjunta el XML a la compra elegida (ComprasIntel::relacionar)
 *   accion=registrar_proveedor  da de alta al proveedor propuesto (confirmación explícita)
 *   accion=cancelar             descarta la lectura sin registrar nada
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}
if (!consumirToken($_POST['token'] ?? null)) {
    redirigir('/admin/compras/inteligente', 'error', 'Este formulario ya se envió; la acción no se repitió.');
}

$accion = (string) ($_POST['accion'] ?? '');
$lecturaId = (int) ($_POST['lectura'] ?? 0);
$usuario = (int) ($_SESSION['id'] ?? 0);
$vuelta = '/admin/compras/inteligente/revision?id=' . $lecturaId;

if ($lecturaId <= 0) {
    redirigir('/admin/compras/inteligente', 'error', 'No indicó qué factura procesar.');
}

if ($accion === 'relacionar') {
    $ordenId = (int) ($_POST['orden'] ?? 0);
    if (\App\ComprasIntel::relacionar($lecturaId, $ordenId, $usuario)) {
        redirigir('/admin/compras/detalle?id=' . $ordenId . '#factura', 'exito',
            'Factura adjuntada a la compra y a su gasto en contabilidad (sin duplicar importes).');
    }
    redirigir($vuelta, 'error', implode(' · ', \App\ComprasIntel::getErrores()));
}

if ($accion === 'registrar_proveedor') {
    $id = \App\ComprasIntel::registrarProveedor($lecturaId, $_POST, $usuario);
    if ($id !== null) {
        redirigir($vuelta, 'exito', 'Proveedor listo: ahora puedes relacionar la factura o iniciar una compra nueva.');
    }
    redirigir($vuelta, 'error', implode(' · ', \App\ComprasIntel::getErrores()));
}

if ($accion === 'cancelar') {
    if (\App\Lecturas::descartar($lecturaId, $usuario)) {
        redirigir('/admin/compras/inteligente', 'exito', 'Lectura cancelada: no se registró nada.');
    }
    redirigir($vuelta, 'error', 'Esta lectura ya no estaba pendiente.');
}

redirigir('/admin/compras/inteligente', 'error', 'Acción no reconocida.');

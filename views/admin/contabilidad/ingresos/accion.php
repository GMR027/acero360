<?php
/**
 * views/admin/contabilidad/ingresos/accion.php — facturación, cobros y cancelación de cobros (solo POST).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$accion  = (string) ($_POST['accion'] ?? '');
$usuario = (int) ($_SESSION['id'] ?? 0);
$destino = '/admin/contabilidad/ingresos/detalle?id=' . $id;

if (!\App\Contabilidad::buscarIngreso($id)) {
    redirigir('/admin/contabilidad/ingresos', 'error', 'El ingreso ya no existe.');
}

if ($accion === 'facturacion') {
    $ok = \App\Contabilidad::actualizarFacturacion(
        $id, (string) ($_POST['estado_facturacion'] ?? ''), (string) ($_POST['factura_folio'] ?? ''),
        (string) ($_POST['uuid_fiscal'] ?? ''), (string) ($_POST['fecha_facturacion'] ?? ''), $usuario
    );
    $mensaje = 'Facturación actualizada.';
} elseif ($accion === 'cobro') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($destino, 'error', 'Este formulario ya se envió; no se registró el cobro dos veces.');
    }
    $ok = \App\Contabilidad::registrarCobro(
        $id, (string) ($_POST['fecha'] ?? ''), str_replace(',', '.', (string) ($_POST['importe'] ?? '')),
        (string) ($_POST['metodo'] ?? ''), (string) ($_POST['referencia'] ?? ''), $usuario
    );
    $mensaje = 'Cobro registrado.';
} elseif ($accion === 'cobro_cancelar') {
    $ok = \App\Contabilidad::cancelarCobro((int) ($_POST['cobro_id'] ?? 0), (string) ($_POST['motivo'] ?? ''), $usuario);
    $mensaje = 'Cobro cancelado.';
} else {
    redirigir($destino, 'error', 'Acción no reconocida.');
}

if ($ok) {
    redirigir($destino, 'exito', $mensaje);
}

redirigir($destino, 'error', \App\Contabilidad::getErrores()[0] ?? 'No fue posible completar la operación.');

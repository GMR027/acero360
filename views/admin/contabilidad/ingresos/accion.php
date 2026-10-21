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

// Solo se acepta volver al detalle de una venta (ruta interna fija), nunca una URL arbitraria.
if (preg_match('#^/admin/ventas/detalle\?id=\d+$#', (string) ($_POST['volver'] ?? ''))) {
    $destino = $_POST['volver'];
}

if (preg_match('#^/admin/seguimiento(\?[a-z_=&]*)?$#', (string) ($_POST['volver'] ?? ''))) {
    $destino = $_POST['volver'];
}

// El conmutador de la lista de ingresos regresa a la misma lista (mismos filtros y página).
if (preg_match('#^/admin/contabilidad/ingresos(\?[A-Za-z0-9_=&%.+-]*)?$#', (string) ($_POST['volver'] ?? ''))) {
    $destino = $_POST['volver'];
}

if (!\App\Contabilidad::buscarIngreso($id)) {
    redirigir('/admin/contabilidad/ingresos', 'error', 'El ingreso ya no existe.');
}

if ($accion === 'facturacion') {
    // Los archivos se adjuntan primero para que un CFDI timbrado respalde la factura.
    $fallos = [];
    foreach (\App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null) as $archivo) {
        if (!\App\Archivos::subir('ingreso', $id, $archivo, 'auto', $usuario)) {
            $fallos[] = $archivo['name'] . ': ' . (\App\Archivos::getErrores()[0] ?? 'no se pudo adjuntar');
        }
    }
    if ($fallos) {
        redirigir($destino, 'error', implode(' · ', $fallos));
    }
    // Elegir «Facturada» ya es la confirmación: sin CFDI timbrado queda registrada como manual.
    $_POST['confirma_manual'] = '1';
    $ok = \App\Contabilidad::actualizarFacturacion(
        $id, (string) ($_POST['estado_facturacion'] ?? ''), (string) ($_POST['factura_folio'] ?? ''),
        (string) ($_POST['uuid_fiscal'] ?? ''), (string) ($_POST['fecha_facturacion'] ?? ''), $usuario,
        !empty($_POST['confirma_manual'])
    );
    $mensaje = 'Facturación actualizada.';
} elseif ($accion === 'facturacion_alterna') {
    // Conmutador de la lista: Pendiente de facturar ⇄ Facturada. Facturar sin CFDI adjunto queda marcado como confirmación manual.
    $ingreso = \App\Contabilidad::buscarIngreso($id);
    $nuevo   = ($ingreso['estado_facturacion'] ?? '') === 'facturada' ? 'sin_clasificar' : 'facturada';
    $ok = \App\Contabilidad::actualizarFacturacion($id, $nuevo, '', '', $nuevo === 'facturada' ? date('Y-m-d') : '', $usuario, $nuevo === 'facturada');
    $mensaje = $nuevo === 'facturada' ? 'Marcada como facturada.' : 'Marcada como pendiente de facturar.';
} elseif ($accion === 'cobro_alterna') {
    // Conmutador de la lista: Sin pagar ⇄ Pagada. Pagar registra un cobro por el saldo; revertir cancela los cobros vigentes.
    $ingreso = \App\Contabilidad::buscarIngreso($id);
    if (($ingreso['estado_cobro'] ?? '') === 'cobrado') {
        $ok = \App\Contabilidad::cancelarCobrosDeIngreso($id, 'Revertido desde la lista de ingresos', $usuario);
        $mensaje = 'Marcada como sin pagar: se cancelaron sus cobros.';
    } else {
        $ok = \App\Contabilidad::registrarCobro($id, date('Y-m-d'), (string) ($ingreso['saldo'] ?? 0), '', 'Marcada como pagada', $usuario);
        $mensaje = 'Marcada como pagada: se registró el cobro del saldo.';
    }
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

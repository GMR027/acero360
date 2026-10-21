<?php
/**
 * views/admin/compras/comprobante.php — Facturas y comprobantes de una compra (solo POST).
 *
 *   accion=adjuntar    sube uno o varios archivos (XML/PDF/foto); envía la compra a contabilidad UNA vez y los asocia a su gasto
 *   accion=enviar      envía la compra a contabilidad sin documento (reutiliza el gasto si ya existe)
 *   accion=estado_cfdi marca «CFDI pendiente» o «Sin factura» («Con CFDI» solo lo da un XML con timbre)
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$accion  = (string) ($_POST['accion'] ?? '');
$usuario = (int) ($_SESSION['id'] ?? 0);
$destino = '/admin/compras/detalle?id=' . $id;

if (!\App\OrdenesCompra::buscarID($id)) {
    redirigir('/admin/compras/pedidos', 'error', 'La compra ya no existe.');
}

if ($accion === 'adjuntar') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($destino . '#factura', 'error', 'Este formulario ya se envió; no se adjuntó el archivo dos veces.');
    }
    $archivos = \App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null);
    if (!$archivos) {
        redirigir($destino . '#factura', 'error', 'Elige al menos un archivo.');
    }
    $subidos = 0;
    $fallos = [];
    foreach ($archivos as $archivo) {
        if (\App\CompraContable::adjuntarComprobante($id, $archivo, 'auto', $usuario)) {
            $subidos++;
        } else {
            $fallos[] = $archivo['name'] . ': ' . (\App\CompraContable::getErrores()[0] ?? 'no se pudo adjuntar');
        }
    }
    if ($fallos) {
        redirigir($destino . '#factura', 'error', ($subidos ? "Se adjuntaron $subidos; " : '') . implode(' · ', $fallos));
    }
    redirigir($destino . '#factura', 'exito', $subidos === 1 ? 'Documento adjuntado a la compra y a su gasto en contabilidad.' : "$subidos documentos adjuntados.");
}

if ($accion === 'enviar') {
    $gastoId = \App\CompraContable::enviarAContabilidad($id, $usuario);
    if ($gastoId > 0) {
        redirigir($destino . '#factura', 'exito', 'La compra está en contabilidad (gasto #' . $gastoId . '). No se duplica aunque se envíe otra vez.');
    }
    redirigir($destino . '#factura', 'error', \App\CompraContable::getErrores()[0] ?? 'No fue posible enviarla a contabilidad.');
}

if ($accion === 'estado_cfdi') {
    if (\App\CompraContable::definirEstadoCfdi($id, (string) ($_POST['estado_cfdi'] ?? ''), $usuario)) {
        redirigir($destino . '#factura', 'exito', 'Estado de la factura actualizado.');
    }
    redirigir($destino . '#factura', 'error', \App\CompraContable::getErrores()[0] ?? 'No fue posible cambiar el estado.');
}

redirigir($destino, 'error', 'Acción no reconocida.');

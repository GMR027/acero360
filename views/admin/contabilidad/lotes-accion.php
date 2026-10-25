<?php
/**
 * views/admin/contabilidad/lotes-accion.php — Acciones de la carga por lotes (FASE 9).
 *
 * Cada botón de la bandeja pasa por aquí: registrar gasto, gasto + compra,
 * relacionar a una compra existente, alta de proveedor, alta de cliente de una
 * factura emitida, registrar todo el lote limpio o descartar. Todo reutiliza las
 * vías de escritura existentes (nada nuevo se inventa aquí).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
    redirigir('/admin/contabilidad/lotes', 'error', 'Usa los botones de la carga por lotes.');
}
if (!consumirToken($_POST['token'] ?? null)) {
    redirigir('/admin/contabilidad/lotes', 'error', 'Este formulario ya se envió: la acción no se repitió.');
}

$usuario = (int) ($_SESSION['id'] ?? 0);
$accion = (string) ($_POST['accion'] ?? '');
$lecturaId = (int) ($_POST['lectura'] ?? 0);
$destino = '/admin/contabilidad/lotes?carril=' . (($_POST['carril'] ?? 'gastos') === 'ventas' ? 'ventas' : 'gastos');

if ($accion === 'gasto_solo') {
    $r = \App\CargaLotes::registrarGasto($lecturaId, !empty($_POST['pagado']), $usuario);
    if ($r['ok']) {
        redirigir('/admin/contabilidad/gastos/detalle?id=' . (int) $r['gasto_id'], 'exito',
            'Gasto registrado con su factura' . (!empty($_POST['pagado']) ? ' (marcado pagado por la regla PUE + Contado)' : '') . '.');
    }
    redirigir($destino, 'error', implode(' · ', $r['errores']));
}

if ($accion === 'compra_nueva') {
    $r = \App\CargaLotes::registrarCompra($lecturaId, $usuario);
    if ($r['ok']) {
        $mensaje = 'Compra y su gasto registrados con el XML adjunto (una sola vez).';
        if (!empty($r['proveedor_nuevo'])) {
            $mensaje .= ' El proveedor se dio de alta con el RFC de la factura.';
        }
        redirigir('/admin/compras/detalle?id=' . (int) $r['orden_id'] . '#factura', 'exito', $mensaje);
    }
    if (!empty($r['orden_id'])) {
        redirigir('/admin/compras/detalle?id=' . (int) $r['orden_id'] . '#factura', 'error',
            'La compra quedó creada pero: ' . implode(' · ', $r['errores']));
    }
    redirigir($destino, 'error', implode(' · ', $r['errores']));
}

if ($accion === 'relacionar') {
    $ordenId = (int) ($_POST['orden'] ?? 0);
    if (\App\ComprasIntel::relacionar($lecturaId, $ordenId, $usuario)) {
        redirigir('/admin/compras/detalle?id=' . $ordenId . '#factura', 'exito',
            'Factura adjuntada a la compra y a su gasto en contabilidad (sin duplicar importes).');
    }
    redirigir('/admin/compras/inteligente/revision?id=' . $lecturaId, 'error', implode(' · ', \App\ComprasIntel::getErrores()));
}

if ($accion === 'alta_proveedor') {
    $r = \App\CargaLotes::altaProveedor($lecturaId, $usuario);
    if ($r['ok']) {
        redirigir($destino, 'exito', 'Proveedor dado de alta con el RFC de la factura.');
    }
    redirigir($destino, 'error', implode(' · ', $r['errores']));
}

if ($accion === 'alta_cliente') {
    $r = \App\Lecturas::altaClienteDesdeLectura($lecturaId, $_POST, $usuario);
    if ($r['ok']) {
        redirigir($destino, 'exito', !empty($r['ya_existia'])
            ? 'Ese RFC ya tenía ficha: la venta se puede crear con ese cliente.'
            : 'Cliente creado con los datos de la factura: ya puedes crear la venta.');
    }
    redirigir($destino, 'error', implode(' · ', $r['errores']) ?: 'No fue posible registrar el cliente.');
}

if ($accion === 'todo') {
    $r = \App\CargaLotes::registrarTodo($usuario);
    $mensaje = 'Se registraron ' . $r['registrados'] . ' gasto(s) con su factura.';
    if ($r['errores']) {
        $mensaje .= ' No se pudieron registrar: ' . implode(' · ', array_keys($r['errores'])) . '.';
    }
    redirigir('/admin/contabilidad/lotes?carril=gastos', $r['registrados'] > 0 ? 'exito' : 'error', $mensaje);
}

if ($accion === 'descartar') {
    if (\App\CargaLotes::descartar($lecturaId, $usuario)) {
        redirigir($destino, 'exito', 'Documento descartado: no se registró nada (queda la bitácora).');
    }
    redirigir($destino, 'error', implode(' · ', \App\CargaLotes::getErrores()));
}

redirigir($destino, 'error', 'Acción no reconocida.');

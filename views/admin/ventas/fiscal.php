<?php
/**
 * views/admin/ventas/fiscal.php — Facturación de una venta (solo POST).
 *
 * Es la única puerta de escritura de la facturación de una venta:
 *   1. Completa los datos fiscales del receptor (con búsqueda por RFC normalizado
 *      para no duplicar clientes).
 *   2. Declara método y forma de pago, uso y tipo de comprobante.
 *   3. Adjunta el documento (XML/PDF/foto) y vuelve a validar TODO: si queda un
 *      solo error obligatorio, NO se factura.
 *   4. Marca la venta como facturada en el mismo envío. Sin CFDI adjunto exige
 *      la confirmación manual explícita (queda registrada en la auditoría).
 *
 * Las acciones «no_facturada» y «pendiente» sólo cambian la clasificación, sin
 * capturar datos fiscales ni documentos.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$ventaId = (int) ($_POST['venta'] ?? 0);
$usuario = (int) ($_SESSION['id'] ?? 0);
$accion  = (string) ($_POST['accion'] ?? 'emitir');

/* Regreso sólo a rutas internas conocidas; nunca a una URL arbitraria. */
$volver = (string) ($_POST['volver'] ?? '');
$destino = '/admin/ventas/detalle?id=' . $ventaId . '#facturacion';
if (preg_match('#^/admin/ventas/detalle\?id=\d+$#', $volver)
    || preg_match('#^/admin/entregas-seguimiento/venta\?id=\d+$#', $volver)) {
    $destino = $volver . '#facturacion';
}

if (!consumirToken($_POST['token'] ?? null)) {
    redirigir($destino, 'error', 'Este formulario ya se envió: la operación no se repitió.');
}
if ($ventaId <= 0 || !\App\Ventas::buscarID($ventaId)) {
    redirigir('/admin/ventas', 'error', 'La venta ya no existe.');
}

$ingreso = \App\Contabilidad::ingresoDeVenta($ventaId);
if (!$ingreso) {
    redirigir($destino, 'error', 'La venta no tiene ingreso contable asociado.');
}
$ingresoId = (int) $ingreso['id'];

/* Una venta ya respaldada con CFDI no se vuelve a capturar aquí: su
 * corrección se hace desde una incidencia de entrega (baja el documento
 * anterior y sube el correcto). El formulario de la pantalla ya se oculta. */
if ($ingreso['estado_facturacion'] === 'facturada' && \App\Contabilidad::cfdiDeIngreso($ingresoId) !== null) {
    redirigir($destino, 'error', 'Esta venta ya está facturada con CFDI. Para corregirla, usa una incidencia de entrega que marque «Corregir factura».');
}

/* -------------------------------------------------------------------------
 * Cambios de clasificación sin captura (no se facturará / volver a pendiente).
 * ---------------------------------------------------------------------- */
if ($accion === 'no_facturada' || $accion === 'pendiente') {
    $estado = $accion === 'no_facturada' ? 'no_facturada' : 'sin_clasificar';
    $ok = \App\Contabilidad::actualizarFacturacion($ingresoId, $estado, '', '', '', $usuario, false);
    if ($ok) {
        redirigir($destino, 'exito', $accion === 'no_facturada'
            ? 'Venta marcada como «No se facturará».'
            : 'Venta devuelta a «Pendiente de facturar».');
    }
    redirigir($destino, 'error', \App\Contabilidad::getErrores()[0] ?? 'No fue posible cambiar la facturación.');
}

if ($accion !== 'emitir') {
    redirigir($destino, 'error', 'Acción de facturación no reconocida.');
}

/* -------------------------------------------------------------------------
 * 1. Datos fiscales del receptor.
 * ---------------------------------------------------------------------- */
$datosReceptor = [];
foreach (['rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi'] as $campo) {
    // Los campos vacíos no se envían: así un dato que ya estaba en la ficha no
    // se borra cuando la persona deja el campo en blanco por error.
    if (isset($_POST[$campo]) && trim((string) $_POST[$campo]) !== '') {
        $datosReceptor[$campo] = (string) $_POST[$campo];
    }
}
if ($datosReceptor && !\App\VentasFiscal::completarReceptor($ventaId, $datosReceptor, $usuario)) {
    redirigir($destino, 'error', implode(' · ', \App\VentasFiscal::getErrores()));
}

/* 2. Método y forma de pago declarados para el CFDI. */
if (!\App\VentasFiscal::guardarPago($ventaId, (string) ($_POST['metodo_pago'] ?? ''), (string) ($_POST['forma_pago'] ?? ''), $usuario)) {
    redirigir($destino, 'error', implode(' · ', \App\VentasFiscal::getErrores()));
}

/* 3. Uso y tipo de comprobante: se guardan en el ingreso (el documento manda
 *    sobre la ficha del cliente). Los fuera de catálogo se ignoran. */
\App\VentasFiscal::guardarDatosLeidos($ventaId, [
    'metodo_pago' => (string) ($_POST['metodo_pago'] ?? ''),
    'forma_pago'  => (string) ($_POST['forma_pago'] ?? ''),
    'uso_cfdi'    => (string) ($_POST['uso_cfdi'] ?? ''),
    'tipo_cfdi'   => (string) ($_POST['tipo_cfdi'] ?? ''),
], $usuario);

/* 4. Documentos de la factura: se adjuntan ANTES de decidir, para que un XML
 *    con timbre respalde la factura en este mismo envío. */
$fallos = [];
foreach (\App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null) as $archivo) {
    if (!\App\Archivos::subir('ingreso', $ingresoId, $archivo, 'auto', $usuario)) {
        $fallos[] = $archivo['name'] . ': ' . (\App\Archivos::getErrores()[0] ?? 'no se pudo adjuntar');
    }
}
if ($fallos) {
    redirigir($destino, 'error', implode(' · ', $fallos));
}

/* 5. Revalidación integral: no se avanza con errores obligatorios. */
$revision = \App\VentasFiscal::validar($ventaId);
if ($revision === null) {
    redirigir('/admin/ventas', 'error', 'La venta ya no existe.');
}
if (!$revision['puede_facturar']) {
    redirigir($destino, 'error', 'Validación previa sin aprobar: ' . implode(' · ', array_slice($revision['errores'], 0, 4))
        . (count($revision['errores']) > 4 ? ' (+' . (count($revision['errores']) - 4) . ' más)' : ''));
}

/* 6. Marcar la facturación: con CFDI adjunto o con confirmación manual. */
$cfdi = \App\Contabilidad::cfdiDeIngreso($ingresoId);
$confirmaManual = !empty($_POST['confirma_manual']);
if ($cfdi === null && !$confirmaManual && (int) $ingreso['facturacion_manual'] !== 1) {
    redirigir($destino, 'error', 'Adjunta el XML del CFDI con timbre o marca la confirmación manual: '
        . 'sin documento no se puede marcar como facturada.');
}

$folio = trim((string) ($_POST['factura_folio'] ?? ''));
$uuid  = strtoupper(trim((string) ($_POST['uuid_fiscal'] ?? '')));
if ($cfdi !== null) {
    $uuid = $uuid !== '' ? $uuid : strtoupper((string) $cfdi['uuid_fiscal']);
    $folio = $folio !== '' ? $folio : 'CFDI';
}
$fecha = trim((string) ($_POST['fecha_facturacion'] ?? ''));
$fecha = $fecha !== '' ? $fecha : date('Y-m-d');

$manual = $cfdi === null;   // sin CFDI sólo cabe la confirmación manual
if (!\App\Contabilidad::actualizarFacturacion($ingresoId, 'facturada', $folio, $uuid, $fecha, $usuario, $manual)) {
    redirigir($destino, 'error', \App\Contabilidad::getErrores()[0] ?? 'No fue posible registrar la facturación.');
}

redirigir($destino, 'exito', $cfdi !== null
    ? 'Factura registrada con su CFDI adjunto.'
    : 'Factura registrada como confirmación manual (sin CFDI adjunto).');

<?php
/**
 * views/admin/ventas/fiscal.php — Facturación de una venta (solo POST).
 *
 * Es la única puerta de escritura de la facturación de una venta:
 *   1. Lee el XML ANTES de guardar: de ahí salen UUID, folio, fecha, método,
 *      forma, uso y los datos fiscales del receptor que falten.
 *   2. Completa los datos fiscales del receptor (con búsqueda por RFC normalizado
 *      para no duplicar clientes).
 *   3. Declara método y forma de pago, uso y tipo de comprobante.
 *   4. Adjunta el documento (XML/PDF/foto).
 *   5. Si la venta sigue pendiente, revalida TODO: si queda un solo error
 *      obligatorio, NO se factura.
 *   6. Marca la facturación. Sin CFDI adjunto exige la confirmación manual
 *      explícita (queda registrada en la auditoría).
 *
 * Acciones:
 *   · emitir       — facturar por primera vez o adjuntar el XML que respalde
 *                    una confirmación manual anterior.
 *   · completar    — corregir datos de una venta YA facturada sin cambiar su
 *                    UUID ni su documento.
 *   · no_facturada / pendiente — sólo cambian la clasificación.
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
$yaFacturada = $ingreso['estado_facturacion'] === 'facturada';
$cfdiActual = \App\Contabilidad::cfdiDeIngreso($ingresoId);

/* Una venta ya respaldada con CFDI no se vuelve a capturar aquí: su
 * corrección se hace desde una incidencia de entrega (baja el documento
 * anterior y sube el correcto). La única excepción es «completar», que sólo
 * corrige datos de la ficha y del pago sin tocar el comprobante. */
if ($yaFacturada && $cfdiActual !== null && $accion !== 'completar') {
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

/* -------------------------------------------------------------------------
 * Acción «completar»: corrige datos de una factura ya registrada.
 * No cambia el UUID, ni el documento, ni el estado de facturación.
 * ---------------------------------------------------------------------- */
if ($accion === 'completar') {
    if (!$yaFacturada) {
        redirigir($destino, 'error', 'Esta venta todavía no está facturada: usa «Facturar y guardar».');
    }

    $datosReceptor = [];
    foreach (['rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi'] as $campo) {
        if (isset($_POST[$campo]) && trim((string) $_POST[$campo]) !== '') {
            $datosReceptor[$campo] = (string) $_POST[$campo];
        }
    }
    if ($datosReceptor && !\App\VentasFiscal::completarReceptor($ventaId, $datosReceptor, $usuario)) {
        redirigir($destino, 'error', implode(' · ', \App\VentasFiscal::getErrores()));
    }

    // En una factura ya emitida, el método puede quedar vacío: no se bloquea.
    if (!\App\VentasFiscal::guardarPago(
        $ventaId,
        (string) ($_POST['metodo_pago'] ?? ''),
        (string) ($_POST['forma_pago'] ?? ''),
        $usuario,
        true
    )) {
        redirigir($destino, 'error', implode(' · ', \App\VentasFiscal::getErrores()));
    }

    \App\VentasFiscal::guardarDatosLeidos($ventaId, [
        'metodo_pago' => (string) ($_POST['metodo_pago'] ?? ''),
        'forma_pago'  => (string) ($_POST['forma_pago'] ?? ''),
        'uso_cfdi'    => (string) ($_POST['uso_cfdi'] ?? ''),
        'tipo_cfdi'   => (string) ($_POST['tipo_cfdi'] ?? ''),
    ], $usuario);

    redirigir($destino, 'exito', 'Datos del CFDI actualizados. El UUID y el documento no cambiaron.');
}

if ($accion !== 'emitir') {
    redirigir($destino, 'error', 'Acción de facturación no reconocida.');
}

/* -------------------------------------------------------------------------
 * 0. Leer los XML ANTES de escribir. El documento manda: de aquí salen el
 *    UUID, folio, fecha, método, forma, uso y los datos del receptor.
 * ---------------------------------------------------------------------- */
$subidos = \App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null);
$xmlDatos = null;
$erroresXml = [];
foreach ($subidos as $archivo) {
    $validado = \App\Archivos::validar($archivo, true);
    if ($validado === null) {
        $erroresXml[] = $archivo['name'] . ': ' . (\App\Archivos::getErrores()[0] ?? 'no se pudo leer el archivo');
        continue;
    }
    if ($validado['familia'] !== 'xml') {
        continue;
    }
    if (empty($validado['cfdi']['valido'])) {
        $erroresXml[] = $archivo['name'] . ': ' . ($validado['cfdi']['error'] ?? 'el XML no es un CFDI válido');
        continue;
    }

    $datos = $validado['cfdi']['datos'];
    if (strtoupper((string) ($datos['tipo'] ?? '')) === 'P') {
        $erroresXml[] = $archivo['name'] . ': es un complemento de pago (CFDI tipo P). '
            . 'Cárgalo en Ventas → Complementos de pago.';
        continue;
    }
    if ($xmlDatos === null) {
        $xmlDatos = $datos;
    } elseif (strtoupper((string) ($datos['uuid'] ?? '')) !== strtoupper((string) ($xmlDatos['uuid'] ?? ''))) {
        $erroresXml[] = 'Seleccionaste dos XML con UUID distintos: sube sólo el de esta factura.';
    }
}
if ($erroresXml) {
    redirigir($destino, 'error', implode(' · ', $erroresXml));
}

$datosXml = $xmlDatos !== null ? \App\VentasFiscal::datosDeCfdi($xmlDatos) : null;
$avance = \App\VentasFiscal::validar($ventaId);
if ($avance === null) {
    redirigir('/admin/ventas', 'error', 'La venta ya no existe.');
}

/* El XML tiene que ser de esta venta: emisor, receptor y total. */
if ($datosXml !== null) {
    $rfcEmisor = \App\VentasFiscal::normalizarRfc((string) (($avance['empresa']['rfc'] ?? '') ?: ($avance['contribuyente_rfc'] ?? '')));
    $rfcCliente = \App\VentasFiscal::normalizarRfc((string) ($avance['cliente']['rfc'] ?? ''));

    if ($datosXml['emisor_rfc'] !== '' && $rfcEmisor !== '' && $datosXml['emisor_rfc'] !== $rfcEmisor) {
        redirigir($destino, 'error', 'El XML pertenece al emisor ' . $datosXml['emisor_rfc']
            . ' y esta venta la emite ' . $rfcEmisor . ': no corresponde.');
    }
    if ($datosXml['receptor_rfc'] !== '' && $rfcCliente !== '' && $datosXml['receptor_rfc'] !== $rfcCliente) {
        redirigir($destino, 'error', 'El XML es del receptor ' . $datosXml['receptor_rfc']
            . ' y la venta es del cliente ' . $rfcCliente . ': no corresponde.');
    }
    if ($datosXml['total'] !== ''
        && \App\Contabilidad::centavos($datosXml['total']) !== \App\Contabilidad::centavos($ingreso['total'])
        && empty($_POST['acepta_discrepancias'])) {
        redirigir($destino, 'error', 'El total del XML (' . $datosXml['total'] . ') no coincide con el de la venta ('
            . number_format((float) $ingreso['total'], 2) . '). Revisa el archivo o marca la casilla de discrepancia.');
    }

    // Si la venta ya tenía un UUID, el XML que la respalda debe ser el mismo.
    $uuidActual = strtoupper(trim((string) ($ingreso['uuid_fiscal'] ?? '')));
    if ($yaFacturada && $uuidActual !== '' && $datosXml['uuid'] !== '' && $datosXml['uuid'] !== $uuidActual) {
        redirigir($destino, 'error', 'Esta venta ya está facturada con el UUID ' . $uuidActual
            . '; el XML trae ' . $datosXml['uuid'] . '. Para cambiarla usa una corrección o sustitución autorizada.');
    }
}

/* -------------------------------------------------------------------------
 * 1. Datos fiscales del receptor. Se completa lo que falte en la ficha con
 *    lo que trae el XML; nunca se borra un dato existente por dejar el campo
 *    en blanco.
 * ---------------------------------------------------------------------- */
$datosReceptor = [];
foreach (['rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi'] as $campo) {
    $valor = isset($_POST[$campo]) ? trim((string) $_POST[$campo]) : '';
    $actual = trim((string) ($avance['cliente'][$campo] ?? ''));
    if ($valor === '' && $actual === '' && $datosXml !== null) {
        $valor = $datosXml[$campo];
    }
    if ($valor !== '') {
        $datosReceptor[$campo] = $valor;
    }
}
if ($datosReceptor && !\App\VentasFiscal::completarReceptor($ventaId, $datosReceptor, $usuario)) {
    redirigir($destino, 'error', implode(' · ', \App\VentasFiscal::getErrores()));
}

/* 2. Método y forma de pago. Si el ingreso no tiene dato, se usa el del XML.
 *    En una venta ya facturada no se exige método. */
$metodo = trim((string) ($_POST['metodo_pago'] ?? ''));
if ($metodo === '') {
    $metodo = (string) ($ingreso['metodo_pago'] ?? '');
}
/* Al registrar una factura por primera vez manda el documento: el XML ya
 * emitido no puede declarar PUE si dice PPD. En una venta ya facturada se
 * conserva lo que había y la discrepancia se informa al final. */
if ($datosXml !== null && !$yaFacturada && $datosXml['metodo_pago'] !== '') {
    $metodo = $datosXml['metodo_pago'];
}
if ($metodo === '' && $datosXml !== null) {
    $metodo = $datosXml['metodo_pago'];
}
$forma = trim((string) ($_POST['forma_pago'] ?? ''));
if ($forma === '') {
    $forma = (string) ($ingreso['forma_pago'] ?? '');
}
if ($datosXml !== null && !$yaFacturada && $datosXml['forma_pago'] !== '') {
    $forma = $datosXml['forma_pago'];
}
if ($forma === '' && $datosXml !== null) {
    $forma = $datosXml['forma_pago'];
}
if (!\App\VentasFiscal::guardarPago($ventaId, $metodo, $forma, $usuario, $yaFacturada)) {
    redirigir($destino, 'error', implode(' · ', \App\VentasFiscal::getErrores()));
}

/* 3. Uso y tipo de comprobante: el documento manda sobre la ficha. */
$uso = trim((string) ($_POST['uso_cfdi'] ?? ''));
if ($uso === '') {
    $uso = (string) ($ingreso['uso_cfdi'] ?? '');
}
if ($datosXml !== null && !$yaFacturada && $datosXml['uso_cfdi'] !== '') {
    $uso = $datosXml['uso_cfdi'];
}
if ($uso === '' && $datosXml !== null) {
    $uso = $datosXml['uso_cfdi'];
}
$tipo = trim((string) ($_POST['tipo_cfdi'] ?? ''));
if ($tipo === '') {
    $tipo = (string) ($ingreso['tipo_cfdi'] ?? '');
}
if ($datosXml !== null && !$yaFacturada && $datosXml['tipo_cfdi'] !== '') {
    $tipo = $datosXml['tipo_cfdi'];
}
if ($tipo === '' && $datosXml !== null) {
    $tipo = $datosXml['tipo_cfdi'];
}
\App\VentasFiscal::guardarDatosLeidos($ventaId, [
    'metodo_pago' => $metodo,
    'forma_pago'  => $forma,
    'uso_cfdi'    => $uso,
    'tipo_cfdi'   => $tipo,
], $usuario);

/* Lo que el XML traiga y siga vacío se completa aquí; lo que difiera se
 * devuelve como discrepancia (no se sobrescribe). */
$completado = ['aplicados' => [], 'discrepancias' => [], 'errores' => []];
if ($xmlDatos !== null) {
    $completado = \App\VentasFiscal::completarDesdeCfdi($ventaId, $xmlDatos, $usuario);
    if ($completado['errores']) {
        redirigir($destino, 'error', implode(' · ', $completado['errores']));
    }
}

/* 4. Documentos de la factura: se adjuntan ANTES de decidir, para que un XML
 *    con timbre respalde la factura en este mismo envío. */
$fallos = [];
foreach ($subidos as $archivo) {
    if (!\App\Archivos::subir('ingreso', $ingresoId, $archivo, 'auto', $usuario)) {
        $fallos[] = $archivo['name'] . ': ' . (\App\Archivos::getErrores()[0] ?? 'no se pudo adjuntar');
    }
}
if ($fallos) {
    redirigir($destino, 'error', implode(' · ', $fallos));
}

/* 5. Revalidación integral. Una venta ya facturada no tiene emisión pendiente:
 *    sólo se actualizan sus datos y, si llegó el XML, se vincula. */
$cfdi = \App\Contabilidad::cfdiDeIngreso($ingresoId);

if (!$yaFacturada) {
    $revision = \App\VentasFiscal::validar($ventaId, true);
    if ($revision === null) {
        redirigir('/admin/ventas', 'error', 'La venta ya no existe.');
    }
    if (!$revision['puede_facturar']) {
        redirigir($destino, 'error', 'Validación previa sin aprobar: ' . implode(' · ', array_slice($revision['errores'], 0, 4))
            . (count($revision['errores']) > 4 ? ' (+' . (count($revision['errores']) - 4) . ' más)' : ''));
    }
}

/* 6. Marcar o completar la facturación. */
$confirmaManual = !empty($_POST['confirma_manual']);
if (!$yaFacturada && $cfdi === null && !$confirmaManual && (int) $ingreso['facturacion_manual'] !== 1) {
    redirigir($destino, 'error', 'Adjunta el XML del CFDI con timbre o marca la confirmación manual: '
        . 'sin documento no se puede marcar como facturada.');
}

$folio = trim((string) ($_POST['factura_folio'] ?? ''));
if ($folio === '' && $datosXml !== null) {
    $folio = $datosXml['folio'];
}
$uuid = strtoupper(trim((string) ($_POST['uuid_fiscal'] ?? '')));
if ($uuid === '' && $datosXml !== null) {
    $uuid = $datosXml['uuid'];
}
if ($cfdi !== null) {
    $uuid = $uuid !== '' ? $uuid : strtoupper((string) $cfdi['uuid_fiscal']);
    $folio = $folio !== '' ? $folio : 'CFDI';
}
$fecha = trim((string) ($_POST['fecha_facturacion'] ?? ''));
if ($fecha === '' && $datosXml !== null) {
    $fecha = $datosXml['fecha'];
}
$fecha = $fecha !== '' ? $fecha : date('Y-m-d');

if ($yaFacturada && $cfdi === null) {
    // Facturada sin XML y sin archivo nuevo: sólo se guardaron datos.
    redirigir($destino, 'exito', 'Datos de la factura actualizados.'
        . ($completado['discrepancias'] ? ' ' . implode(' ', $completado['discrepancias']) : ''));
}

$manual = $cfdi === null;   // sin CFDI sólo cabe la confirmación manual
if (!\App\Contabilidad::actualizarFacturacion($ingresoId, 'facturada', $folio, $uuid, $fecha, $usuario, $manual)) {
    redirigir($destino, 'error', \App\Contabilidad::getErrores()[0] ?? 'No fue posible registrar la facturación.');
}

$mensaje = $yaFacturada
    ? 'XML de la factura vinculado y datos completados.'
    : ($cfdi !== null
        ? 'Factura registrada con su CFDI adjunto.'
        : 'Factura registrada como confirmación manual (sin CFDI adjunto).');
if ($completado['aplicados']) {
    $mensaje .= ' Se completaron del XML: ' . implode(', ', $completado['aplicados']) . '.';
}
if ($completado['discrepancias']) {
    $mensaje .= ' ' . implode(' ', $completado['discrepancias']);
}

redirigir($destino, 'exito', $mensaje);

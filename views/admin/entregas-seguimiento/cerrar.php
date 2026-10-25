<?php
/**
 * views/admin/entregas-seguimiento/cerrar.php — Cierra una incidencia (solo POST).
 *
 *   accion=resolver → se resuelve; si la incidencia pedía corregir la factura,
 *   sube el PDF y el XML correctos, da de baja los anteriores y sincroniza el
 *   ingreso (folio, UUID, fecha, subtotal, IVA y total) con el XML nuevo.
 *   accion=cancelar → la incidencia se cierra sin efectos (reportada por error).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$incidenciaId = (int) ($_POST['incidencia_id'] ?? 0);
$accion       = (string) ($_POST['accion'] ?? '');
$incidencia   = $incidenciaId > 0 ? \App\IncidenciasEntrega::buscar($incidenciaId) : null;

if (!$incidencia) {
    paginaNoEncontrada();
}

$rutaVenta = '/admin/entregas-seguimiento/venta?id=' . (int) $incidencia['venta_id'];
$usuario   = (int) ($_SESSION['id'] ?? 0);

if (!consumirToken($_POST['token'] ?? null)) {
    redirigir($rutaVenta, 'error', 'Este formulario ya se envió; la incidencia no se cerró dos veces.');
}
if ($incidencia['estado'] !== 'abierta') {
    redirigir($rutaVenta, 'error', 'Esta incidencia ya estaba cerrada.');
}
if (!in_array($accion, ['resolver', 'cancelar'], true)) {
    redirigir($rutaVenta, 'error', 'Acción no reconocida.');
}

$resolucion = (string) ($_POST['resolucion'] ?? '');
if (mb_strlen(trim($resolucion)) < 5) {
    redirigir($rutaVenta, 'error', 'Escribe cómo se resolvió o canceló la incidencia (mínimo 5 caracteres).');
}

$archivos = [];
if ($accion === 'resolver' && (int) $incidencia['factura_requerida'] === 1) {
    $pdfs = \App\Archivos::listaDeSubidas($_FILES['factura_pdf'] ?? null);
    $xmls = \App\Archivos::listaDeSubidas($_FILES['factura_xml'] ?? null);
    if (count($pdfs) !== 1 || count($xmls) !== 1) {
        redirigir($rutaVenta . '#incidencia-' . $incidenciaId, 'error', 'Adjunta exactamente un PDF y un XML de la factura correcta.');
    }
    $archivos = [
        'pdf'    => $pdfs[0],
        'xml'    => $xmls[0],
        'fecha'  => (string) ($_POST['fecha_factura'] ?? ''),
    ];
}

$cerrado = \App\IncidenciasEntrega::cerrar(
    $incidenciaId,
    $accion === 'resolver' ? 'resuelta' : 'cancelada',
    $resolucion,
    $accion === 'resolver' && (int) $incidencia['factura_requerida'] === 1,
    $archivos,
    $usuario
);

if ($cerrado) {
    if ($accion === 'resolver' && $archivos) {
        redirigir($rutaVenta . '#incidencia-' . $incidenciaId, 'exito',
            'Incidencia resuelta: la factura correcta se adjuntó, los documentos anteriores quedaron dados de baja y '
            . 'el ingreso se sincronizó con los datos del XML nuevo. Revisa los cobros si quedaron de más.');
    }
    redirigir($rutaVenta . '#incidencia-' . $incidenciaId, 'exito',
        $accion === 'resolver' ? 'Incidencia resuelta.' : 'Incidencia cancelada; nada del negocio cambió.');
}

redirigir($rutaVenta . '#incidencia-' . $incidenciaId, 'error', implode(' · ', \App\IncidenciasEntrega::getErrores()));

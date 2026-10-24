<?php
/**
 * views/admin/ventas/fiscal.php — Validación previa y emisión de una venta (solo POST).
 *
 * Es la única puerta de escritura de la FASE 5:
 *   1. Completa los datos fiscales del receptor (con búsqueda por RFC normalizado
 *      para no duplicar clientes).
 *   2. Declara método y forma de pago del CFDI.
 *   3. Vuelve a validar TODO: si queda un solo error obligatorio, NO se avanza.
 *   4. Intenta la emisión real: relaciona un CFDI existente si lo hay; si hay que
 *      timbrar y no hay PAC, sólo informa (no se simula timbrado y la venta se
 *      conserva sin facturas ni ingresos duplicados).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$ventaId = (int) ($_POST['venta'] ?? 0);
$usuario = (int) ($_SESSION['id'] ?? 0);
$destino = $ventaId > 0 ? '/admin/ventas/detalle?id=' . $ventaId . '#facturacion' : '/admin/ventas';

if (!consumirToken($_POST['token'] ?? null)) {
    redirigir($destino, 'error', 'Este formulario ya se envió: la operación no se repitió.');
}
if ($ventaId <= 0 || !\App\Ventas::buscarID($ventaId)) {
    redirigir('/admin/ventas', 'error', 'La venta ya no existe.');
}

/* 1. Datos fiscales del receptor enviados desde el modal de revisión. */
$datosReceptor = [];
foreach (['rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi'] as $campo) {
    if (isset($_POST[$campo])) {
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

/* 3. Revalidación integral: no se avanza con errores obligatorios. */
$revision = \App\VentasFiscal::validar($ventaId);
if ($revision === null) {
    redirigir('/admin/ventas', 'error', 'La venta ya no existe.');
}
if (!$revision['puede_facturar']) {
    redirigir($destino, 'error', 'Validación previa sin aprobar: ' . implode(' · ', array_slice($revision['errores'], 0, 4))
        . (count($revision['errores']) > 4 ? ' (+' . (count($revision['errores']) - 4) . ' más)' : ''));
}

/* 4. Emisión / relación del CFDI. */
$sustitucion = !empty($_POST['sustitucion']);
$resultado = \App\VentasFiscal::emitir($ventaId, $sustitucion, (string) ($_POST['motivo_sustitucion'] ?? ''), $usuario);

if (!empty($resultado['ok'])) {
    redirigir($destino, 'exito', $resultado['mensaje'] ?? 'Operación de facturación completada.');
}
if (!empty($resultado['pendiente_pac'])) {
    redirigir($destino, 'error', 'Validación aprobada, pero sin PAC: ' . $resultado['errores'][0]);
}
redirigir($destino, 'error', implode(' · ', $resultado['errores'] ?? ['No fue posible facturar la venta.']));

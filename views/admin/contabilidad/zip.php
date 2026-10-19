<?php
/**
 * views/admin/contabilidad/zip.php — ZIP mensual de documentos (ventas o gastos). Solo lectura.
 */

soloAdmin();

$tipo = ($_GET['tipo'] ?? '') === 'gastos' ? 'gastos' : 'ventas';
$periodo = periodoSeleccionado();

$zip = \App\ArchivoMensual::construir($tipo, $periodo['anio'], $periodo['mes']);

if ($zip === null) {
    redirigir('/admin/contabilidad', 'error', \App\ArchivoMensual::getErrores()[0] ?? 'No fue posible generar el ZIP.');
}

\App\Auditoria::registrar('archivo_mensual', null, 'descarga', null, null, [
    'tipo' => $tipo, 'anio' => $periodo['anio'], 'mes' => $periodo['mes'], 'archivos' => $zip['archivos'],
]);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip['nombre'] . '"');
header('Content-Length: ' . filesize($zip['ruta']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($zip['ruta']);
@unlink($zip['ruta']);
exit;

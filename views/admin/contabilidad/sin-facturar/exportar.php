<?php
/**
 * views/admin/contabilidad/sin-facturar/exportar.php — Reporte de ventas sin facturar en PDF, Excel o CSV.
 * Solo lectura: exportar no modifica ninguna venta ni ingreso.
 */

soloAdmin();

$periodo = periodoSeleccionado(false);
$formato = (string) ($_GET['formato'] ?? 'csv');
$sf      = \App\Contabilidad::sinFacturar($periodo['anio'], $periodo['mes'], $periodo['empresa']);
$sufijo  = $periodo['anio'] . ($periodo['mes'] ? sprintf('-%02d', $periodo['mes']) : '');

if ($formato === 'pdf') {
    \App\Documentos::descargarSinFacturar($sf, $periodo);
}

$encabezados = ['Fecha', 'Folio', 'Cliente', 'Negocio', 'Estado', 'Subtotal', 'IVA registrado', 'Total'];
$filas = array_map(static fn ($f) => [
    $f['fecha'], $f['folio'], $f['cliente_empresa'] ?: $f['cliente_nombre'], $f['empresa_nombre'],
    \App\Contabilidad::FACTURACION[$f['estado_facturacion']]['etiqueta'],
    round((float) $f['subtotal'] - (float) $f['descuento'], 2), (float) $f['iva'], (float) $f['total'],
], $sf['filas']);
$filas[] = ['', '', '', '', 'TOTAL', $sf['totales']['subtotal'], $sf['totales']['iva'], $sf['totales']['total']];

if ($formato === 'xlsx') {
    $bytes = \App\Exportador::xlsx('Sin facturar', $encabezados, $filas);
    if ($bytes === null) {
        redirigir('/admin/contabilidad/sin-facturar', 'error', 'No fue posible generar el archivo de Excel.');
    }
    \App\Exportador::descargar($bytes, "ventas-sin-facturar-$sufijo.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

\App\Exportador::descargar(\App\Exportador::csv($encabezados, $filas), "ventas-sin-facturar-$sufijo.csv", 'text/csv; charset=utf-8');

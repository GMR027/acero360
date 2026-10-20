<?php
/**
 * views/admin/contabilidad/ingresos/exportar.php — Ingresos del periodo (con los filtros de la lista) en PDF, Excel o CSV.
 * Solo lectura: exportar no modifica ningún ingreso.
 */

soloAdmin();

$periodo = periodoSeleccionado(false);
$fecha   = static fn (string $c): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET[$c] ?? '')) ? $_GET[$c] : '';
$filtros = $periodo + [
    'facturacion' => (string) ($_GET['facturacion'] ?? ''), 'q' => trim($_GET['q'] ?? ''),
    'estado' => ($_GET['estado'] ?? '') === 'cancelado' ? 'cancelado' : '', 'cobro' => ($_GET['cobro'] ?? '') === 'pendiente' ? 'pendiente' : '',
    'desde' => $fecha('desde'), 'hasta' => $fecha('hasta'),
];
if ($filtros['desde'] !== '' || $filtros['hasta'] !== '' || ($_GET['todos'] ?? '') === '1') {
    $filtros['anio'] = 0;
    $filtros['mes'] = 0;
}
$formato = in_array($_GET['formato'] ?? '', ['pdf', 'xlsx'], true) ? $_GET['formato'] : 'csv';
$ingresos = \App\Contabilidad::listarIngresos($filtros, 100000, 0);
$tot      = \App\Contabilidad::totalesIngresos($filtros);
$texto    = $filtros['anio'] > 0 ? ($filtros['mes'] > 0 ? nombreMes($filtros['mes']) . ' ' : '') . $filtros['anio'] : 'Todos los periodos';
$sufijo   = $filtros['anio'] > 0 ? $filtros['anio'] . ($filtros['mes'] > 0 ? sprintf('-%02d', $filtros['mes']) : '') : 'todos';
$volver   = '/admin/contabilidad/ingresos';

$enc = ['Fecha', 'Venta', 'Cliente', 'Negocio', 'Subtotal', 'IVA', 'Total', 'Facturación', 'Cobro', 'Saldo', 'Documentos'];
$filas = array_map(static fn ($i) => [
    $i['fecha'], $i['folio'], $i['cliente_empresa'] ?: $i['cliente_nombre'], $i['empresa_nombre'],
    round((float) $i['subtotal'] - (float) $i['descuento'], 2), (float) $i['iva'], (float) $i['total'],
    \App\Contabilidad::FACTURACION[$i['estado_facturacion']]['etiqueta'], \App\Contabilidad::etiquetaCobro($i['estado_cobro'])['etiqueta'],
    round((float) $i['saldo'], 2), (int) $i['documentos'],
], $ingresos);
$filas[] = ['', '', '', 'TOTAL (' . $tot['n'] . ')', $tot['subtotal'], $tot['iva'], $tot['total'], '', '', $tot['saldo'], ''];

if ($formato === 'pdf') {
    \App\Documentos::descargarTabla('Ingresos por ventas · ' . $texto, 'Contabilidad', $enc, $filas, "ingresos-$sufijo",
        'Importes registrados; no son cálculos fiscales.', $volver, true);
}
if ($formato === 'xlsx') {
    $bytes = \App\Exportador::xlsx('Ingresos', $enc, $filas);
    if ($bytes === null) {
        redirigir($volver, 'error', 'No fue posible generar el archivo de Excel.');
    }
    \App\Exportador::descargar($bytes, "ingresos-$sufijo.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
\App\Exportador::descargar(\App\Exportador::csv($enc, $filas), "ingresos-$sufijo.csv", 'text/csv; charset=utf-8');

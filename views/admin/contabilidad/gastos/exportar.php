<?php
/**
 * views/admin/contabilidad/gastos/exportar.php — Gastos del periodo (con los filtros de la lista) en PDF, Excel o CSV.
 * Solo lectura: exportar no modifica ningún gasto.
 */

soloAdmin();

$periodo = periodoSeleccionado(false);
$fecha   = static fn (string $c): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET[$c] ?? '')) ? $_GET[$c] : '';
$filtros = $periodo + [
    'q' => trim($_GET['q'] ?? ''), 'pago' => (string) ($_GET['pago'] ?? ''),
    'sin_comprobante' => ($_GET['sin_comprobante'] ?? '') === '1' ? '1' : '',
    'estado' => ($_GET['estado'] ?? '') === 'cancelado' ? 'cancelado' : '', 'desde' => $fecha('desde'), 'hasta' => $fecha('hasta'),
];
if ($filtros['desde'] !== '' || $filtros['hasta'] !== '' || ($_GET['todos'] ?? '') === '1') {
    $filtros['anio'] = 0;
    $filtros['mes'] = 0;
}
$formato = in_array($_GET['formato'] ?? '', ['pdf', 'xlsx'], true) ? $_GET['formato'] : 'csv';
$gastos  = \App\Gastos::listar($filtros, 100000, 0);
$tot     = \App\Gastos::totales($filtros);
$texto   = $filtros['anio'] > 0 ? ($filtros['mes'] > 0 ? nombreMes($filtros['mes']) . ' ' : '') . $filtros['anio'] : 'Todos los periodos';
$sufijo  = $filtros['anio'] > 0 ? $filtros['anio'] . ($filtros['mes'] > 0 ? sprintf('-%02d', $filtros['mes']) : '') : 'todos';
$volver  = '/admin/contabilidad/gastos';

$enc = ['Fecha', 'Proveedor', 'Concepto', 'Categoría', 'Negocio', 'Subtotal', 'IVA', 'Total', 'Pago', 'Comprobante', 'Documentos'];
$filas = array_map(static fn ($g) => [
    $g['fecha'], $g['beneficiario'], $g['concepto'], (string) $g['categoria_nombre'],
    (int) $g['compartido'] === 1 ? 'Compartido' : (string) ($g['empresa_nombre'] ?? 'General'),
    (float) $g['subtotal'], (float) $g['iva'], (float) $g['total'],
    $g['estado_pago'] === 'pagado' ? 'Pagado' : 'Pendiente', (int) $g['comprobante_fiscal'] === 1 ? 'Sí' : 'No', (int) $g['documentos'],
], $gastos);
$filas[] = ['', '', '', '', 'TOTAL (' . $tot['n'] . ')', $tot['subtotal'], $tot['iva'], $tot['total'], '', '', ''];

if ($formato === 'pdf') {
    \App\Documentos::descargarTabla('Egresos y gastos · ' . $texto, 'Contabilidad', $enc, $filas, "gastos-$sufijo",
        'Importes registrados; no son cálculos fiscales.', $volver, true);
}
if ($formato === 'xlsx') {
    $bytes = \App\Exportador::xlsx('Gastos', $enc, $filas);
    if ($bytes === null) {
        redirigir($volver, 'error', 'No fue posible generar el archivo de Excel.');
    }
    \App\Exportador::descargar($bytes, "gastos-$sufijo.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
\App\Exportador::descargar(\App\Exportador::csv($enc, $filas), "gastos-$sufijo.csv", 'text/csv; charset=utf-8');

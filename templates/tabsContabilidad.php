<?php
/**
 * templates/tabsContabilidad.php — Secciones del módulo de contabilidad, en lenguaje llano.
 * Lo avanzado (documentos, cierre del mes, periodos cerrados) vive en «Más opciones».
 * Variables: $tab, $periodo (anio/mes/empresa) para conservar el periodo al navegar.
 */
$tab = in_array($tab, ['documentos', 'cierre', 'periodos'], true) ? 'mas' : $tab;
$consulta = http_build_query(array_filter(
    ['anio' => $periodo['anio'] ?? null, 'mes' => $periodo['mes'] ?? null, 'empresa' => $periodo['empresa'] ?? null],
    static fn ($v) => $v !== null && $v !== 0
));
$sufijo = $consulta !== '' ? '?' . $consulta : '';
$secciones = [
    'resumen'  => ['/admin/contabilidad' . $sufijo, 'Resumen del mes', 'dashboard'],
    'lotes'    => ['/admin/contabilidad/lotes' . $sufijo, 'Carga por lotes', 'upload_file'],
    'ingresos' => ['/admin/contabilidad/ingresos' . $sufijo, 'Ventas y cobros', 'payments'],
    'gastos'   => ['/admin/contabilidad/gastos' . $sufijo, 'Gastos', 'receipt'],
    'sin'      => ['/admin/contabilidad/sin-facturar' . $sufijo, 'Pendientes de facturar', 'receipt_long'],
    'mas'      => ['/admin/contabilidad/mas', 'Más opciones', 'more_horiz'],
];
$etiqueta = 'Secciones de contabilidad';
require __DIR__ . '/navModulo.php';

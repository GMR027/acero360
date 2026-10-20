<?php
/**
 * views/admin/contabilidad/cierre-exportar.php — Resumen del cierre en PDF o CSV (solo lectura).
 * Exporta la fotografía GUARDADA al cerrar: no recalcula nada.
 */

soloAdmin();

$periodo = periodoSeleccionado();
$estado  = \App\CierrePeriodo::periodo(\App\Contabilidad::contribuyentePrincipal(), $periodo['anio'], $periodo['mes']);

if (!$estado || $estado['estado'] !== 'cerrado' || empty($estado['snapshot'])) {
    redirigir("/admin/contabilidad/cierre?anio={$periodo['anio']}&mes={$periodo['mes']}", 'error', 'Solo se exporta el resumen de un periodo cerrado.');
}

$s = $estado['snapshot'];
$sufijo = sprintf('%04d-%02d', $periodo['anio'], $periodo['mes']);

if (($_GET['formato'] ?? 'pdf') === 'pdf') {
    \App\Documentos::descargarCierre($s, $estado);
}

$mx = static fn ($c) => $c === null ? '' : round($c / 100, 2);
$f = $s['fiscal'];
$filas = [
    ['Periodo', $s['periodo']], ['Cerrado en', $s['cerrado_en']],
    ['Ventas del periodo (total)', $s['contabilidad']['ventas']['total']], ['Subtotal de operaciones', $s['contabilidad']['ventas']['subtotal']],
    ['IVA trasladado registrado', $s['contabilidad']['ventas']['iva']], ['Gastos y compras (total)', $mx($s['contabilidad']['gastos']['total'])],
    ['Facturado en el mes', $f['facturado'] ?? 0], ['Pagos y gastos del mes', $f['pagos'] ?? 0],
    ['IVA por pagar (estimado)', $f['iva_por_pagar'] ?? 0], ['ISR por pagar (estimado)', $f['isr_por_pagar'] ?? 0],
    ['Impuestos por pagar (estimado)', $f['impuestos'] ?? 0],
    ['Aviso', 'Estimación simple: validar con el contador antes de presentar declaraciones.'],
];
foreach ($s['verificaciones'] as $v) {
    $filas[] = ['Verificación (' . $v['nivel'] . ')', $v['titulo'] . ' · ' . $v['cantidad']];
}

\App\Exportador::descargar(\App\Exportador::csv(['Concepto', 'Valor'], $filas), "cierre-$sufijo.csv", 'text/csv; charset=utf-8');

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
    ['IVA trasladado cobrado (estimado)', $mx($f['iva']['trasladado_cobrado'] ?? null)], ['IVA acreditable considerado (estimado)', $mx($f['iva']['acreditable_considerado'] ?? null)],
    ['IVA estimado a cargo', $mx($f['iva']['a_cargo'] ?? null)], ['Saldo a favor de IVA estimado', $mx($f['iva']['saldo_a_favor'] ?? null)],
    ['Ingresos acumulados del ejercicio', $mx($f['isr']['ingresos_acumulados'] ?? null)], ['Deducciones registradas', $mx($f['isr']['deducciones_acumuladas'] ?? null)],
    ['Base gravable', $mx($f['isr']['utilidad_fiscal'] ?? null)], ['ISR determinado', $mx($f['isr']['impuesto_determinado'] ?? null)],
    ['Pagos provisionales anteriores', $mx($f['isr']['pagos_provisionales_anteriores'] ?? null)], ['ISR provisional estimado', $mx($f['isr']['a_pagar'] ?? null)],
    ['Reglas ISR (versión)', $f['versiones']['isr'] ?? ''], ['Reglas IVA (versión)', $f['versiones']['iva'] ?? ''], ['Criterios (versión)', $f['versiones']['reglas'] ?? ''],
    ['Aviso', 'Estimación administrativa: validar con el contador antes de presentar declaraciones.'],
];
foreach ($s['verificaciones'] as $v) {
    $filas[] = ['Verificación (' . $v['nivel'] . ')', $v['titulo'] . ' · ' . $v['cantidad']];
}

\App\Exportador::descargar(\App\Exportador::csv(['Concepto', 'Valor'], $filas), "cierre-$sufijo.csv", 'text/csv; charset=utf-8');

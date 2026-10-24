<?php
/**
 * views/admin/fiscal/exportar.php — Reportes de impuestos en PDF, Excel o CSV.
 *   tipo=impuestos    → impuestos por pagar del mes (o los 12 meses si mes=0)
 *   tipo=movimientos  → todas las ventas y gastos del periodo, con los impuestos por pagar al final
 *                     (PDF y Excel traen además la sección de proveedores: compras, pagos y saldo)
 * Solo lectura: exportar no modifica ninguna cifra.
 */

soloAdmin();

$contrib = \App\Contabilidad::contribuyentePrincipal();
$anio    = (int) ($_GET['anio'] ?? date('Y'));
$anio    = ($anio >= 2000 && $anio <= 2100) ? $anio : (int) date('Y');
$mes     = (int) ($_GET['mes'] ?? 0);
$mes     = ($mes >= 0 && $mes <= 12) ? $mes : 0;
$tipo    = ($_GET['tipo'] ?? '') === 'movimientos' ? 'movimientos' : 'impuestos';
$formato = in_array($_GET['formato'] ?? '', ['pdf', 'xlsx'], true) ? $_GET['formato'] : 'csv';
$periodo = ($mes > 0 ? nombreMes($mes) . ' ' : '') . $anio;
$sufijo  = $anio . ($mes > 0 ? sprintf('-%02d', $mes) : '');
$volver  = '/admin/fiscal' . ($tipo === 'movimientos' ? '/movimientos' : '') . "?anio=$anio&mes=" . max(1, $mes);
$r       = \App\ResumenFiscal::anual($contrib, $anio);
$tasa    = rtrim(rtrim(number_format($r['tasa'], 2), '0'), '.');
$aviso   = "Estimación administrativa: ISR al $tasa % de (facturado sin IVA − pagos sin IVA), con el IVA real de cada CFDI. Cuenta sólo las ventas facturadas PAGADAS al 100 % y los gastos YA PAGADOS: las facturas en parcialidades o sin cobrar y los gastos por pagar quedan fuera hasta confirmar su pago. El método del CFDI (PUE/PPD/por definir) y los archivos adjuntos son informativos y no cambian la cuenta. Confírmala con tu contador.";
$horizontal = false;

if ($tipo === 'impuestos' && $mes > 0) {
    $x = $r['meses'][$mes];
    $titulo = 'Impuestos por pagar · ' . $periodo;
    $enc    = ['Concepto', 'Importe'];
    $filas  = [
        ['Facturado (con IVA)', round($x['facturado'], 2)],
        ['Pagos y gastos (con IVA)', round($x['pagos'], 2)],
        ['Fuera: facturas sin pagar o en parcialidades (' . number_format((int) $x['facturas_sin_pagar_n']) . ')', round($x['facturas_sin_pagar'], 2)],
        ['Fuera: gastos por pagar (' . number_format((int) $x['gastos_no_pagados_n']) . ')', round($x['gastos_no_pagados'], 2)],
        ['IVA facturado', round($x['iva_facturado'], 2)],
        ['IVA de los gastos', round($x['iva_acreditado'], 2)],
        ['IVA por pagar', round($x['iva_por_pagar'], 2)],
        ["ISR por pagar ($tasa %)", round($x['isr_por_pagar'], 2)],
        ['IMPUESTOS POR PAGAR', round($x['impuestos'], 2)],
    ];
    $nombre = "impuestos-$sufijo";
} elseif ($tipo === 'impuestos') {
    $titulo = 'Impuestos por pagar mes por mes · ' . $anio;
    $enc    = ['Concepto'];
    for ($i = 1; $i <= 12; $i++) {
        $enc[] = mb_substr(nombreMes($i), 0, 3);
    }
    $enc[]  = 'Año';
    $filas  = [];
    foreach ([['facturado', 'Facturado'], ['pagos', 'Pagos y gastos'], ['facturas_sin_pagar', 'Fuera: facturas sin pagar'], ['gastos_no_pagados', 'Fuera: gastos por pagar'],
        ['iva_por_pagar', 'IVA por pagar'], ['isr_por_pagar', "ISR por pagar ($tasa %)"], ['impuestos', 'IMPUESTOS POR PAGAR']] as [$k, $texto]) {
        $fila = [$texto];
        for ($i = 1; $i <= 12; $i++) {
            $fila[] = round($r['meses'][$i][$k], 2);
        }
        $fila[] = round($r['total'][$k], 2);
        $filas[] = $fila;
    }
    $nombre = "impuestos-$anio";
    $horizontal = true;
} else {
    $d   = \App\ResumenFiscal::movimientos($contrib, $anio, $mes);
    $imp = $mes > 0 ? $r['meses'][$mes] : $r['total'];
    $titulo = 'Ventas y gastos · ' . $periodo;
    $enc    = ['Fecha', 'Tipo', 'Folio o concepto', 'Cliente o proveedor', 'Negocio', 'Detalle', 'Subtotal', 'IVA', 'Total'];
    $filas  = array_map(static fn ($f) => [
        $f['fecha'], $f['tipo'], $f['referencia'], $f['contraparte'], $f['negocio'], $f['detalle'],
        round((float) $f['subtotal'], 2), round((float) $f['iva'], 2), round((float) $f['total'], 2),
    ], $d['filas']);
    $filas[] = ['', '', '', '', '', '', '', '', ''];
    $filas[] = ['', '', '', '', '', 'TOTAL VENTAS', '', round($d['totales']['iva_ventas'], 2), round($d['totales']['ventas'], 2)];
    $filas[] = ['', '', '', '', '', 'TOTAL GASTOS', '', round($d['totales']['iva_gastos'], 2), round($d['totales']['gastos'], 2)];
    $filas[] = ['', '', '', '', '', 'IVA por pagar', '', '', round($imp['iva_por_pagar'], 2)];
    $filas[] = ['', '', '', '', '', "ISR por pagar ($tasa %)", '', '', round($imp['isr_por_pagar'], 2)];
    $filas[] = ['', '', '', '', '', 'IMPUESTOS POR PAGAR', '', '', round($imp['impuestos'], 2)];
    $nombre = "ventas-y-gastos-$sufijo";
    $horizontal = true;
    $prov   = \App\ResumenFiscal::proveedores($anio, $mes);
}

if ($tipo === 'movimientos' && $formato === 'pdf') {
    \App\Documentos::descargarReporteFiscal(
        ['titulo' => $titulo, 'd' => $d, 'imp' => $imp, 'tasa' => $tasa, 'prov' => $prov, 'aviso' => $aviso . ' Lo que se debe a proveedores es el saldo de sus compras menos los pagos registrados.'],
        $nombre,
        $volver
    );
}
if ($tipo === 'movimientos' && $formato === 'xlsx') {
    $mon = static fn ($v) => ['v' => round((float) $v, 2), 'f' => 'moneda'];
    $neg = static fn (array $textos) => array_map(static fn ($t) => ['v' => $t, 'f' => 'neg'], $textos);
    $ventas = array_values(array_filter($d['filas'], static fn ($f) => $f['tipo'] === 'Venta'));
    $gastos = array_values(array_filter($d['filas'], static fn ($f) => $f['tipo'] !== 'Venta'));
    $hojaMov = static function (string $nombreHoja, array $filasMov, string $colTipo, string $totalTexto, float $iva, float $total) use ($mon, $neg) {
        $filas = [$neg(['Fecha', $nombreHoja === 'Ventas' ? 'Folio' : 'Concepto', $nombreHoja === 'Ventas' ? 'Cliente' : 'Proveedor o beneficiario', 'Negocio', $colTipo, 'Subtotal', 'IVA', 'Total'])];
        $sub = 0.0;
        foreach ($filasMov as $f) {
            $sub += (float) $f['subtotal'];
            $filas[] = [$f['fecha'], $f['referencia'], $f['contraparte'], $f['negocio'], $f['detalle'], $mon($f['subtotal']), $mon($f['iva']), $mon($f['total'])];
        }
        $filas[] = [['v' => $totalTexto, 'f' => 'neg'], '', '', '', '', $mon($sub), $mon($iva), $mon($total)];

        return ['nombre' => $nombreHoja, 'filas' => $filas, 'anchos' => [1 => 12, 2 => 22, 3 => 30, 4 => 22, 5 => 22, 6 => 14, 7 => 14, 8 => 14]];
    };
    $filasRes = [
        [['v' => $titulo, 'f' => 'titulo']], [],
        $neg(['Concepto', 'Importe']),
        ['Ventas (total)', $mon($d['totales']['ventas'])], ['   de las cuales facturadas', $mon($d['totales']['ventas_facturadas'])],
        ['   de las cuales facturadas y cobradas (sí cuentan)', $mon($d['totales']['ventas_facturadas_pagadas'])],
        ['Gastos (total)', $mon($d['totales']['gastos'])], ['   de los cuales ya pagados (sí descuentan)', $mon($d['totales']['gastos_pagados'])],
        ['Fuera del cálculo: facturas sin pagar o en parcialidades', $mon($imp['facturas_sin_pagar'])],
        ['Fuera del cálculo: gastos por pagar', $mon($imp['gastos_no_pagados'])],
        ['IVA por pagar', $mon($imp['iva_por_pagar'])], ["ISR por pagar ($tasa %)", $mon($imp['isr_por_pagar'])],
        [['v' => 'IMPUESTOS POR PAGAR', 'f' => 'neg'], $mon($imp['impuestos'])], [],
        ['Comprado a proveedores en el periodo', $mon($prov['totales']['comprado'])],
        ['Pagado a proveedores en el periodo', $mon($prov['totales']['pagado_periodo'])],
        [['v' => 'SE DEBE A PROVEEDORES (hoy)', 'f' => 'neg'], $mon($prov['totales']['saldo'])], [],
        [['v' => $aviso, 'f' => 'nota']],
    ];
    $filasProv = [$neg(['Proveedor', 'RFC', 'Compras', 'Comprado en el periodo', 'Pagado en el periodo', 'Se debe (saldo)'])];
    foreach ($prov['proveedores'] as $x) {
        $filasProv[] = [$x['proveedor'], $x['rfc'], (int) $x['compras'], $mon($x['comprado']), $mon($x['pagado_periodo']), $mon($x['saldo'])];
    }
    $filasProv[] = [['v' => 'TOTAL', 'f' => 'neg'], '', '', $mon($prov['totales']['comprado']), $mon($prov['totales']['pagado_periodo']), $mon($prov['totales']['saldo'])];
    $filasCompras = [$neg(['Fecha', 'Folio', 'Proveedor', 'Estado', 'Subtotal', 'IVA', 'Total', 'Pagado', 'Debe'])];
    foreach ($prov['compras'] as $c) {
        $filasCompras[] = [$c['fecha'], $c['folio'], $c['proveedor'], \App\OrdenesCompra::ESTADOS[$c['estado']]['etiqueta'] ?? $c['estado'],
            $mon($c['subtotal']), $mon($c['iva']), $mon($c['total']), $mon($c['pagado']), $mon(max(0, (float) $c['total'] - (float) $c['pagado']))];
    }
    $filasPagos = [$neg(['Fecha', 'Proveedor', 'Compra', 'Método', 'Referencia', 'Importe'])];
    foreach ($prov['pagos'] as $p) {
        $filasPagos[] = [$p['fecha'], $p['proveedor'], $p['folio'], (string) $p['metodo'], (string) $p['referencia'], $mon($p['importe'])];
    }
    $bytes = \App\Exportador::libro([
        ['nombre' => 'Resumen', 'filas' => $filasRes, 'anchos' => [1 => 42, 2 => 18]],
        $hojaMov('Ventas', $ventas, 'Estado', 'TOTAL VENTAS', $d['totales']['iva_ventas'], $d['totales']['ventas']),
        $hojaMov('Gastos', $gastos, 'Tipo', 'TOTAL GASTOS', $d['totales']['iva_gastos'], $d['totales']['gastos']),
        ['nombre' => 'Proveedores', 'filas' => $filasProv, 'anchos' => [1 => 32, 2 => 16, 3 => 10, 4 => 22, 5 => 22, 6 => 18]],
        ['nombre' => 'Compras', 'filas' => $filasCompras, 'anchos' => [1 => 12, 2 => 16, 3 => 30, 4 => 18, 5 => 14, 6 => 14, 7 => 14, 8 => 14, 9 => 14]],
        ['nombre' => 'Pagos a proveedores', 'filas' => $filasPagos, 'anchos' => [1 => 12, 2 => 30, 3 => 16, 4 => 16, 5 => 20, 6 => 14]],
    ]);
    if ($bytes === null) {
        redirigir($volver, 'error', 'No fue posible generar el archivo de Excel.');
    }
    \App\Exportador::descargar($bytes, $nombre . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
if ($formato === 'pdf') {
    \App\Documentos::descargarTabla($titulo, 'Ambos negocios', $enc, $filas, $nombre, $aviso, $volver, $horizontal);
}
if ($formato === 'xlsx') {
    $bytes = \App\Exportador::xlsx(mb_substr($tipo === 'movimientos' ? 'Ventas y gastos' : 'Impuestos', 0, 30), $enc, $filas);
    if ($bytes === null) {
        redirigir($volver, 'error', 'No fue posible generar el archivo de Excel.');
    }
    \App\Exportador::descargar($bytes, $nombre . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

\App\Exportador::descargar(\App\Exportador::csv($enc, $filas), $nombre . '.csv', 'text/csv; charset=utf-8');

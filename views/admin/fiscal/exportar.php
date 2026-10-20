<?php
/**
 * views/admin/fiscal/exportar.php — Reportes de impuestos en PDF, Excel o CSV.
 *   tipo=impuestos    → impuestos por pagar del mes (o los 12 meses si mes=0)
 *   tipo=movimientos  → todas las ventas y gastos del periodo, con los impuestos por pagar al final
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
$aviso   = "Estimación administrativa: ISR al $tasa % de (facturado sin IVA − pagos sin IVA); cuenta las ventas facturadas y todos los gastos. Confírmala con tu contador.";
$horizontal = false;

if ($tipo === 'impuestos' && $mes > 0) {
    $x = $r['meses'][$mes];
    $titulo = 'Impuestos por pagar · ' . $periodo;
    $enc    = ['Concepto', 'Importe'];
    $filas  = [
        ['Facturado (con IVA)', round($x['facturado'], 2)],
        ['Pagos y gastos (con IVA)', round($x['pagos'], 2)],
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
    foreach ([['facturado', 'Facturado'], ['pagos', 'Pagos y gastos'], ['iva_por_pagar', 'IVA por pagar'], ['isr_por_pagar', "ISR por pagar ($tasa %)"], ['impuestos', 'IMPUESTOS POR PAGAR']] as [$k, $texto]) {
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

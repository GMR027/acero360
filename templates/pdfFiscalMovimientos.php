<?php
/**
 * templates/pdfFiscalMovimientos.php — Reporte fiscal de ventas, gastos, impuestos y proveedores.
 *
 * Secciones: resumen del periodo · 1 Ventas · 2 Gastos · 3 Impuestos por pagar · 4 Proveedores
 * (resumen de lo que se debe, compras y pagos del periodo).
 *
 * Variables: $titulo, $periodo, $d (ResumenFiscal::movimientos), $imp, $tasa, $prov (ResumenFiscal::proveedores), $aviso
 */
$mx    = static fn ($v) => '$' . number_format((float) $v, 2);
$fecha = static fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '';
$ventas = array_values(array_filter($d['filas'], static fn ($f) => $f['tipo'] === 'Venta'));
$gastos = array_values(array_filter($d['filas'], static fn ($f) => $f['tipo'] !== 'Venta'));
$sumaSub = static fn (array $filas) => array_sum(array_map(static fn ($f) => (float) $f['subtotal'], $filas));
$estadoCompra = static fn ($e) => \App\OrdenesCompra::ESTADOS[$e]['etiqueta'] ?? $e;
$hayDeuda = $prov['totales']['saldo'] > 0.005;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= escaparValores($titulo) ?></title>
    <style>
        @page { margin: 1.2cm 1cm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #4d4c53; }
        h1 { margin: 0 0 .15rem; font-size: 17px; color: #2f5f8c; }
        h2 { margin: 1.1rem 0 .15rem; padding: .3rem .5rem; font-size: 12px; color: #fff; background: #4679a6; }
        h2.gasto { background: #8a5a00; } h2.imp { background: #5c4a8a; } h2.prov { background: #1c6b3c; }
        h3 { margin: .7rem 0 .2rem; font-size: 10px; color: #2f5f8c; }
        p { margin: 0 0 .35rem; } .guia { color: #6b6b70; font-size: 8.5px; margin: .15rem 0 .35rem; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: .25rem; background: #eaf1f8; border: 1px solid #c7dcef; color: #2f5f8c; font-size: 8px; text-transform: uppercase; text-align: left; }
        td { padding: .22rem; border: 1px solid #e4e6ea; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #fafbfc; }
        .num { text-align: right; } .centro { text-align: center; }
        tfoot td { font-weight: 700; background: #f0f3f7; border-top: 2px solid #c7dcef; }
        .resumen td { border: 1px solid #d9dde3; background: #f4f7fa; padding: .4rem .5rem; width: 25%; }
        .resumen strong { display: block; font-size: 14px; color: #2f5f8c; }
        .resumen small { color: #6b6b70; font-size: 8px; }
        .debe { color: #b3261e; font-weight: 700; } .ok { color: #1c6b3c; font-weight: 700; }
        .aviso { margin-top: .8rem; padding: .4rem; background: #fff7e0; border: 1px solid #f0d58a; }
        .vacio { text-align: center; color: #6b6b70; }
    </style>
</head>
<body>
    <h1><?= escaparValores($titulo) ?></h1>
    <p>Ambos negocios · Emitido <?= date('d/m/Y H:i') ?></p>

    <table class="resumen"><tr>
        <td><small>Ventas (<?= count($ventas) ?>)</small><strong><?= $mx($d['totales']['ventas']) ?></strong><small>Facturadas: <?= $mx($d['totales']['ventas_facturadas']) ?> · cobradas: <?= $mx($d['totales']['ventas_facturadas_pagadas']) ?></small></td>
        <td><small>Gastos (<?= count($gastos) ?>)</small><strong><?= $mx($d['totales']['gastos']) ?></strong><small>Pagados: <?= $mx($d['totales']['gastos_pagados']) ?> · IVA de gastos: <?= $mx($d['totales']['iva_gastos']) ?></small></td>
        <td><small><?= $imp['impuestos'] >= 0 ? 'Impuestos por pagar' : 'Saldo a tu favor' ?></small><strong><?= $mx(abs($imp['impuestos'])) ?></strong><small>IVA <?= $mx($imp['iva_por_pagar']) ?> + ISR <?= $mx($imp['isr_por_pagar']) ?></small></td>
        <td><small>Se debe a proveedores</small><strong class="<?= $hayDeuda ? 'debe' : 'ok' ?>"><?= $mx($prov['totales']['saldo']) ?></strong><small>Al día de hoy, todas las compras</small></td>
    </tr></table>

    <h2>1. Ventas</h2>
    <p class="guia">Lo que se vendió en el periodo. Subtotal + IVA = Total. «Estado» indica si ya se facturó y si está pagada: sólo las marcadas como <strong>Pagada</strong> entran en el cálculo de impuestos. El método (PUE/PPD/por definir) es informativo.</p>
    <table>
        <thead><tr><th>Fecha</th><th>Folio</th><th>Cliente</th><th>Negocio</th><th>Estado</th><th class="num">Subtotal</th><th class="num">IVA</th><th class="num">Total</th></tr></thead>
        <tbody>
            <?php foreach ($ventas as $f) : ?>
                <tr><td><?= $fecha($f['fecha']) ?></td><td><?= escaparValores($f['referencia']) ?></td><td><?= escaparValores($f['contraparte']) ?></td>
                    <td><?= escaparValores($f['negocio']) ?></td><td><?= escaparValores($f['detalle']) ?></td>
                    <td class="num"><?= $mx($f['subtotal']) ?></td><td class="num"><?= $mx($f['iva']) ?></td><td class="num"><?= $mx($f['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$ventas) : ?><tr><td colspan="8" class="vacio">Sin ventas en el periodo.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="5" class="num">TOTAL VENTAS</td><td class="num"><?= $mx($sumaSub($ventas)) ?></td><td class="num"><?= $mx($d['totales']['iva_ventas']) ?></td><td class="num"><?= $mx($d['totales']['ventas']) ?></td></tr></tfoot>
    </table>

    <h2 class="gasto">2. Gastos</h2>
    <p class="guia">Lo que se gastó en el periodo (incluye pagos a proveedores que se registraron como gasto). «Tipo» es la categoría del gasto y, tras «·», si ya se pagó y su método del CFDI (PUE una sola exhibición, PPD parcialidades o por definir, informativo): sólo los marcados como <strong>Pagado</strong> descuentan de impuestos.</p>
    <table>
        <thead><tr><th>Fecha</th><th>Concepto</th><th>Proveedor o beneficiario</th><th>Negocio</th><th>Tipo</th><th class="num">Subtotal</th><th class="num">IVA</th><th class="num">Total</th></tr></thead>
        <tbody>
            <?php foreach ($gastos as $f) : ?>
                <tr><td><?= $fecha($f['fecha']) ?></td><td><?= escaparValores($f['referencia']) ?></td><td><?= escaparValores($f['contraparte']) ?></td>
                    <td><?= escaparValores($f['negocio']) ?></td><td><?= escaparValores($f['detalle']) ?></td>
                    <td class="num"><?= $mx($f['subtotal']) ?></td><td class="num"><?= $mx($f['iva']) ?></td><td class="num"><?= $mx($f['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$gastos) : ?><tr><td colspan="8" class="vacio">Sin gastos en el periodo.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="5" class="num">TOTAL GASTOS</td><td class="num"><?= $mx($sumaSub($gastos)) ?></td><td class="num"><?= $mx($d['totales']['iva_gastos']) ?></td><td class="num"><?= $mx($d['totales']['gastos']) ?></td></tr></tfoot>
    </table>

    <h2 class="imp">3. Impuestos por pagar</h2>
    <table style="width:55%">
        <tr><td>IVA de las ventas</td><td class="num"><?= $mx($imp['iva_facturado']) ?></td></tr>
        <tr><td>IVA de los gastos (acreditable)</td><td class="num"><?= $mx($imp['iva_acreditado']) ?></td></tr>
        <tr><td>IVA por pagar</td><td class="num"><?= $mx($imp['iva_por_pagar']) ?></td></tr>
        <tr><td>ISR por pagar (<?= escaparValores($tasa) ?> %)</td><td class="num"><?= $mx($imp['isr_por_pagar']) ?></td></tr>
        <tfoot><tr><td>IMPUESTOS POR PAGAR</td><td class="num"><?= $mx($imp['impuestos']) ?></td></tr></tfoot>
    </table>
    <?php
    $fuera = [];
    if ((int) ($imp['facturas_sin_pagar_n'] ?? 0) > 0) {
        $fuera[] = number_format((int) $imp['facturas_sin_pagar_n']) . ' factura(s) sin pagar o en parcialidades por ' . $mx($imp['facturas_sin_pagar']);
    }
    if ((int) ($imp['gastos_no_pagados_n'] ?? 0) > 0) {
        $fuera[] = number_format((int) $imp['gastos_no_pagados_n']) . ' gasto(s) por pagar por ' . $mx($imp['gastos_no_pagados']);
    }
    ?>
    <?php if ($fuera) : ?>
        <p class="guia">Fuera de esta estimación: <?= implode(' y ', $fuera) ?>. Se cuentan cuando se confirme que ya se pagaron.</p>
    <?php endif; ?>

    <h2 class="prov">4. Proveedores: compras, pagos y lo que se debe</h2>
    <p class="guia">Saldo = total de las compras (subtotal + IVA) − pagos registrados. Es lo que se debe hoy, sin importar el periodo; compras y pagos sí se limitan al periodo.</p>
    <table>
        <thead><tr><th>Proveedor</th><th class="num">Compras</th><th class="num">Comprado en el periodo</th><th class="num">Pagado en el periodo</th><th class="num">Se debe (saldo)</th></tr></thead>
        <tbody>
            <?php foreach ($prov['proveedores'] as $x) : ?>
                <tr><td><?= escaparValores($x['proveedor']) ?><?= $x['rfc'] !== '' ? ' · ' . escaparValores($x['rfc']) : '' ?></td>
                    <td class="num"><?= (int) $x['compras'] ?></td><td class="num"><?= $mx($x['comprado']) ?></td><td class="num"><?= $mx($x['pagado_periodo']) ?></td>
                    <td class="num <?= $x['saldo'] > 0.005 ? 'debe' : 'ok' ?>"><?= $x['saldo'] > 0.005 ? $mx($x['saldo']) : 'Al corriente' ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$prov['proveedores']) : ?><tr><td colspan="5" class="vacio">Sin compras ni pagos a proveedores.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="2" class="num">TOTAL</td><td class="num"><?= $mx($prov['totales']['comprado']) ?></td><td class="num"><?= $mx($prov['totales']['pagado_periodo']) ?></td><td class="num"><?= $mx($prov['totales']['saldo']) ?></td></tr></tfoot>
    </table>

    <h3>Compras del periodo</h3>
    <table>
        <thead><tr><th>Fecha</th><th>Folio</th><th>Proveedor</th><th>Estado</th><th class="num">Subtotal</th><th class="num">IVA</th><th class="num">Total</th><th class="num">Pagado</th><th class="num">Debe</th></tr></thead>
        <tbody>
            <?php foreach ($prov['compras'] as $c) : $debe = round((float) $c['total'] - (float) $c['pagado'], 2); ?>
                <tr><td><?= $fecha($c['fecha']) ?></td><td><?= escaparValores($c['folio']) ?></td><td><?= escaparValores($c['proveedor']) ?></td><td><?= escaparValores($estadoCompra($c['estado'])) ?></td>
                    <td class="num"><?= $mx($c['subtotal']) ?></td><td class="num"><?= $mx($c['iva']) ?></td><td class="num"><?= $mx($c['total']) ?></td><td class="num"><?= $mx($c['pagado']) ?></td>
                    <td class="num <?= $debe > 0.005 ? 'debe' : 'ok' ?>"><?= $mx(max(0, $debe)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$prov['compras']) : ?><tr><td colspan="9" class="vacio">Sin compras en el periodo.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h3>Pagos a proveedores del periodo</h3>
    <table>
        <thead><tr><th>Fecha</th><th>Proveedor</th><th>Compra</th><th>Método</th><th>Referencia</th><th class="num">Importe</th></tr></thead>
        <tbody>
            <?php foreach ($prov['pagos'] as $p) : ?>
                <tr><td><?= $fecha($p['fecha']) ?></td><td><?= escaparValores($p['proveedor']) ?></td><td><?= escaparValores($p['folio']) ?></td>
                    <td><?= escaparValores((string) $p['metodo']) ?></td><td><?= escaparValores((string) $p['referencia']) ?></td><td class="num"><?= $mx($p['importe']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$prov['pagos']) : ?><tr><td colspan="6" class="vacio">Sin pagos en el periodo.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="5" class="num">TOTAL PAGADO</td><td class="num"><?= $mx($prov['totales']['pagado_periodo']) ?></td></tr></tfoot>
    </table>

    <p class="aviso"><?= escaparValores($aviso) ?></p>
</body>
</html>

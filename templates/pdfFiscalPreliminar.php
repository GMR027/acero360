<?php
/**
 * templates/pdfFiscalPreliminar.php — PDF del Preliminar básico de impuestos.
 *
 * Simulador del documento «FISCAL 2026» con las facturas reales de la base:
 *   1. Tabla fiscal mes por mes (bases, IVA, ISR 32 %, impuestos por pagar) + Año
 *   2. Clientes facturados del mes elegido (quiénes generaron las ventas)
 *   3. Proveedores y compras facturadas del mes (quiénes generaron los gastos)
 *
 * Variables: $titulo, $anio, $mes (1-12), $r (PreliminarFiscal::anual),
 * $clientes y $compras (PreliminarFiscal::clientes / ::compras), $aviso.
 */
$mx  = static fn ($v) => (float) $v < 0 ? '-$' . number_format(abs((float) $v), 2) : '$' . number_format((float) $v, 2);
$fecha = static fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '';
$filas = [
    ['ventas', 'Ventas facturadas (con IVA)'],
    ['gastos', 'Gastos facturados (con IVA)'],
    ['ventas_base', 'Ventas sin IVA (base)'],
    ['gastos_base', 'Gastos sin IVA (base)'],
    ['iva_facturado', 'IVA facturado (trasladado)'],
    ['iva_acreditado', 'IVA acreditable (de gastos)'],
    ['iva_por_pagar', 'IVA por pagar'],
    ['isr', 'ISR por pagar (32 % de la utilidad)'],
    ['impuestos', 'IMPUESTOS POR PAGAR'],
];
$t = $r['total'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= escaparValores($titulo) ?></title>
    <style>
        @page { margin: 1.1cm .9cm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #4d4c53; }
        h1 { margin: 0 0 .15rem; font-size: 17px; color: #2f5f8c; }
        h2 { margin: 1.1rem 0 .15rem; padding: .3rem .5rem; font-size: 12px; color: #fff; background: #4679a6; }
        h2.compra { background: #8a5a00; }
        p { margin: 0 0 .35rem; } .guia { color: #6b6b70; font-size: 8.5px; margin: .15rem 0 .35rem; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: .22rem .25rem; background: #eaf1f8; border: 1px solid #c7dcef; color: #2f5f8c; font-size: 7.5px; text-transform: uppercase; text-align: left; }
        td { padding: .2rem .25rem; border: 1px solid #e4e6ea; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #fafbfc; }
        .num { text-align: right; }
        tfoot td { font-weight: 700; background: #f0f3f7; border-top: 2px solid #c7dcef; }
        .fiscal td, .fiscal th { font-size: 7.8px; }
        .fiscal td.concepto { text-align: left; font-weight: 600; }
        .fiscal tr.final td { font-weight: 700; background: #f0f3f7; border-top: 2px solid #c7dcef; }
        .fiscal td.actual { background: #eaf1f8; }
        .aviso { margin-top: .8rem; padding: .4rem; background: #fff7e0; border: 1px solid #f0d58a; }
        .vacio { text-align: center; color: #6b6b70; }
        .mes { text-transform: capitalize; }
    </style>
</head>
<body>
    <h1><?= escaparValores($titulo) ?></h1>
    <p>Ambos negocios · Listados de <span class="mes"><?= escaparValores(nombreMes((int) $mes)) ?></span> de <?= (int) $anio ?> · Emitido <?= date('d/m/Y H:i') ?></p>

    <h2>1. Cálculo fiscal mes por mes (<?= (int) $anio ?>)</h2>
    <p class="guia">Simulador del documento Fiscal 2026 con las facturas registradas: ventas facturadas y gastos con comprobante fiscal, importes reales (IVA 16 %, 8 % o 0 %). El ISR es plano del 32 % sobre la utilidad del mes; el año suma los 12 meses. No usa cobros ni pagos: la estimación formal (flujo de efectivo y tarifa art. 106) está en la pestaña Impuestos.</p>
    <table class="fiscal">
        <thead><tr>
            <th>Concepto</th>
            <?php for ($i = 1; $i <= 12; $i++) : ?><th class="num"><?= escaparValores(mb_substr(nombreMes($i), 0, 3)) ?></th><?php endfor; ?>
            <th class="num">Año</th>
        </tr></thead>
        <tbody>
            <?php foreach ($filas as [$clave, $tituloFila]) : $final = $clave === 'impuestos'; ?>
                <tr<?= $final ? ' class="final"' : '' ?>>
                    <td class="concepto"><?= escaparValores($tituloFila) ?></td>
                    <?php for ($i = 1; $i <= 12; $i++) : ?>
                        <td class="num<?= $i === (int) $mes ? ' actual' : '' ?>"><?= $mx($r['meses'][$i][$clave]) ?></td>
                    <?php endfor; ?>
                    <td class="num"><?= $mx($t[$clave]) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>2. Clientes facturados · <span class="mes"><?= escaparValores(nombreMes((int) $mes)) ?></span> <?= (int) $anio ?></h2>
    <table>
        <thead><tr><th>Fecha</th><th>Folio</th><th>Cliente</th><th class="num">Monto S/IVA</th><th class="num">IVA</th><th class="num">Monto C/IVA</th></tr></thead>
        <tbody>
            <?php foreach ($clientes['filas'] as $f) : ?>
                <tr><td><?= $fecha($f['fecha']) ?></td><td><?= escaparValores($f['folio']) ?></td><td><?= escaparValores($f['cliente']) ?></td>
                    <td class="num"><?= $mx($f['sin_iva']) ?></td><td class="num"><?= $mx($f['iva']) ?></td><td class="num"><?= $mx($f['con_iva']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$clientes['filas']) : ?><tr><td colspan="6" class="vacio">Sin ventas facturadas en el mes.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="3" class="num">TOTAL (<?= (int) $clientes['totales']['n'] ?>)</td>
            <td class="num"><?= $mx($clientes['totales']['sin_iva']) ?></td><td class="num"><?= $mx($clientes['totales']['iva']) ?></td><td class="num"><?= $mx($clientes['totales']['con_iva']) ?></td></tr></tfoot>
    </table>

    <h2 class="compra">3. Proveedores y compras facturadas · <span class="mes"><?= escaparValores(nombreMes((int) $mes)) ?></span> <?= (int) $anio ?></h2>
    <table>
        <thead><tr><th>Fecha</th><th>Proveedor o beneficiario</th><th>Concepto</th><th class="num">Monto S/IVA</th><th class="num">IVA</th><th class="num">Monto C/IVA</th></tr></thead>
        <tbody>
            <?php foreach ($compras['filas'] as $f) : ?>
                <tr><td><?= $fecha($f['fecha']) ?></td><td><?= escaparValores($f['beneficiario']) ?></td><td><?= escaparValores($f['concepto']) ?></td>
                    <td class="num"><?= $mx($f['sin_iva']) ?></td><td class="num"><?= $mx($f['iva']) ?></td><td class="num"><?= $mx($f['con_iva']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$compras['filas']) : ?><tr><td colspan="6" class="vacio">Sin gastos con comprobante en el mes.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="3" class="num">TOTAL (<?= (int) $compras['totales']['n'] ?>)</td>
            <td class="num"><?= $mx($compras['totales']['sin_iva']) ?></td><td class="num"><?= $mx($compras['totales']['iva']) ?></td><td class="num"><?= $mx($compras['totales']['con_iva']) ?></td></tr></tfoot>
    </table>

    <p class="aviso"><?= escaparValores($aviso) ?></p>
</body>
</html>

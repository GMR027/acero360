<?php
/**
 * templates/pdfSinFacturar.php — Reporte de ventas sin facturar (solo lectura).
 * Variables: $sf (Contabilidad::sinFacturar), $periodo (anio/mes/empresa)
 */
$t = $sf['totales'];
$mx = static fn ($v) => '$' . number_format((float) $v, 2);
$rotulo = ($periodo['mes'] ? nombreMes($periodo['mes']) . ' ' : 'Año ') . $periodo['anio'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ventas sin facturar <?= escaparValores($rotulo) ?></title>
    <style>
        body { margin: 0; padding: 1.5rem; font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #4d4c53; }
        h1 { margin: 0 0 .2rem; font-size: 17px; color: #4679a6; }
        h2 { margin: 1rem 0 .3rem; font-size: 12px; color: #4679a6; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: .3rem; background: #eaf1f8; border: 1px solid #c7dcef; font-size: 8.5px; text-transform: uppercase; color: #4679a6; }
        td { padding: .3rem; border: 1px solid #e4e6ea; font-size: 10px; }
        .num { text-align: right; } .total td { font-weight: 700; background: #f5f7fa; }
        .aviso { margin-top: 1rem; padding: .5rem; background: #fff7e0; border: 1px solid #f0d58a; font-size: 9.5px; }
    </style>
</head>
<body>
    <h1>Ventas sin facturar · <?= escaparValores($rotulo) ?></h1>
    <p>Emitido <?= date('d/m/Y H:i') ?> · <?= (int) $t['operaciones'] ?> operaciones
        (<?= (int) $t['no_facturada'] ?> no facturadas, <?= (int) $t['sin_clasificar'] ?> sin clasificar)</p>

    <h2>Por negocio</h2>
    <table>
        <thead><tr><th>Negocio</th><th>Operaciones</th><th>Subtotal</th><th>IVA registrado</th><th>Total</th></tr></thead>
        <tbody>
            <?php foreach ($sf['por_negocio'] as $n) : ?>
                <tr><td><?= escaparValores($n['nombre']) ?></td><td class="num"><?= (int) $n['operaciones'] ?></td>
                    <td class="num"><?= $mx($n['subtotal']) ?></td><td class="num"><?= $mx($n['iva']) ?></td><td class="num"><?= $mx($n['total']) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Detalle</h2>
    <table>
        <thead><tr><th>Fecha</th><th>Venta</th><th>Cliente</th><th>Negocio</th><th>Estado</th><th>Subtotal</th><th>IVA</th><th>Total</th></tr></thead>
        <tbody>
            <?php foreach ($sf['filas'] as $f) : ?>
                <tr><td><?= date('d/m/Y', strtotime($f['fecha'])) ?></td><td><?= escaparValores($f['folio']) ?></td>
                    <td><?= escaparValores($f['cliente_empresa'] ?: $f['cliente_nombre']) ?></td><td><?= escaparValores($f['empresa_nombre']) ?></td>
                    <td><?= escaparValores(\App\Contabilidad::FACTURACION[$f['estado_facturacion']]['etiqueta']) ?></td>
                    <td class="num"><?= $mx((float) $f['subtotal'] - (float) $f['descuento']) ?></td>
                    <td class="num"><?= $mx($f['iva']) ?></td><td class="num"><?= $mx($f['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$sf['filas']) : ?><tr><td colspan="8" style="text-align:center">Sin ventas sin facturar en el periodo.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="total"><td colspan="5">Total</td><td class="num"><?= $mx($t['subtotal']) ?></td><td class="num"><?= $mx($t['iva']) ?></td><td class="num"><?= $mx($t['total']) ?></td></tr></tfoot>
    </table>
    <p class="aviso">Importes registrados en el sistema. «Sin clasificar» significa que aún no se indicó si la venta se facturó.
        Este reporte no es una declaración ni un cálculo fiscal.</p>
</body>
</html>

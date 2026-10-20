<?php
/**
 * templates/pdfCierre.php — Resumen del cierre mensual (fotografía guardada). Variables: $s (snapshot), $estado.
 */
$f = $s['fiscal'];
$mx = static fn ($c) => $c === null ? '—' : '$' . number_format($c / 100, 2);
$p = static fn ($v) => '$' . number_format((float) $v, 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cierre <?= escaparValores($s['periodo']) ?></title>
    <style>
        body { margin: 0; padding: 1.5rem; font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #4d4c53; }
        h1 { margin: 0 0 .2rem; font-size: 17px; color: #4679a6; } h2 { margin: 1rem 0 .3rem; font-size: 12px; color: #4679a6; } p { margin: 0; }
        table { width: 100%; border-collapse: collapse; } th, td { padding: .3rem; border: 1px solid #e4e6ea; font-size: 10px; text-align: left; }
        th { background: #eaf1f8; color: #4679a6; } .num { text-align: right; }
        .aviso { margin-top: 1rem; padding: .5rem; background: #fff7e0; border: 1px solid #f0d58a; font-size: 9.5px; }
    </style>
</head>
<body>
    <h1>Resumen de cierre · <?= escaparValores($s['periodo']) ?></h1>
    <p>Cerrado el <?= escaparValores(date('d/m/Y H:i', strtotime($s['cerrado_en']))) ?> · <?= escaparValores((string) ($estado['cerrado_por_nombre'] ?? '')) ?>
        <?= $s['nota'] ? ' · ' . escaparValores($s['nota']) : '' ?></p>

    <h2>Importes registrados</h2>
    <table>
        <tr><th>Concepto</th><th class="num">Importe</th></tr>
        <tr><td>Ventas del periodo (total)</td><td class="num"><?= $p($s['contabilidad']['ventas']['total']) ?></td></tr>
        <tr><td>Subtotal de operaciones</td><td class="num"><?= $p($s['contabilidad']['ventas']['subtotal']) ?></td></tr>
        <tr><td>IVA trasladado registrado</td><td class="num"><?= $p($s['contabilidad']['ventas']['iva']) ?></td></tr>
        <tr><td>Gastos y compras (total)</td><td class="num"><?= $mx($s['contabilidad']['gastos']['total']) ?></td></tr>
    </table>

    <h2>Estimación de impuestos del mes</h2>
    <table>
        <tr><th>Concepto</th><th class="num">Importe</th></tr>
        <tr><td>Facturado</td><td class="num"><?= $p($f['facturado'] ?? 0) ?></td></tr>
        <tr><td>Pagos y gastos</td><td class="num"><?= $p($f['pagos'] ?? 0) ?></td></tr>
        <tr><td>IVA por pagar</td><td class="num"><?= $p($f['iva_por_pagar'] ?? 0) ?></td></tr>
        <tr><td>ISR por pagar</td><td class="num"><?= $p($f['isr_por_pagar'] ?? 0) ?></td></tr>
        <tr><td><strong>Impuestos por pagar</strong></td><td class="num"><strong><?= $p($f['impuestos'] ?? 0) ?></strong></td></tr>
    </table>

    <h2>Verificaciones al cierre</h2>
    <table>
        <tr><th>Nivel</th><th>Verificación</th><th>Cantidad</th></tr>
        <?php foreach ($s['verificaciones'] as $v) : ?>
            <tr><td><?= escaparValores($v['nivel']) ?></td><td><?= escaparValores($v['titulo']) ?><?= in_array($v['codigo'], $s['avisos_reconocidos'], true) ? ' (reconocido)' : '' ?></td><td class="num"><?= (int) $v['cantidad'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$s['verificaciones']) : ?><tr><td colspan="3">Sin hallazgos.</td></tr><?php endif; ?>
    </table>
    <p class="aviso">Estimación simple (facturado menos pagos). Debe validarse con el contador antes de presentar declaraciones.</p>
</body>
</html>

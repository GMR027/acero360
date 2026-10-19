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

    <h2>Estimación fiscal (flujo de efectivo)</h2>
    <table>
        <tr><th>Concepto</th><th class="num">Importe</th></tr>
        <tr><td>IVA trasladado cobrado</td><td class="num"><?= $mx($f['iva']['trasladado_cobrado'] ?? null) ?></td></tr>
        <tr><td>IVA acreditable considerado</td><td class="num"><?= $mx($f['iva']['acreditable_considerado'] ?? null) ?></td></tr>
        <tr><td>IVA estimado a cargo</td><td class="num"><?= $mx($f['iva']['a_cargo'] ?? null) ?></td></tr>
        <tr><td>Ingresos acumulados del ejercicio</td><td class="num"><?= $mx($f['isr']['ingresos_acumulados'] ?? null) ?></td></tr>
        <tr><td>Deducciones registradas</td><td class="num"><?= $mx($f['isr']['deducciones_acumuladas'] ?? null) ?></td></tr>
        <tr><td>Base gravable</td><td class="num"><?= $mx($f['isr']['utilidad_fiscal'] ?? null) ?></td></tr>
        <tr><td>ISR determinado</td><td class="num"><?= $mx($f['isr']['impuesto_determinado'] ?? null) ?></td></tr>
        <tr><td>Pagos provisionales anteriores y retenciones</td><td class="num"><?= $mx(($f['isr']['pagos_provisionales_anteriores'] ?? 0) + ($f['isr']['retenciones_acumuladas'] ?? 0)) ?></td></tr>
        <tr><td><strong>ISR provisional estimado</strong></td><td class="num"><strong><?= $mx($f['isr']['a_pagar'] ?? null) ?></strong></td></tr>
    </table>
    <p>Reglas usadas: ISR v#<?= (int) ($f['versiones']['isr'] ?? 0) ?> · IVA v#<?= (int) ($f['versiones']['iva'] ?? 0) ?> · criterios v#<?= (int) ($f['versiones']['reglas'] ?? 0) ?></p>

    <h2>Verificaciones al cierre</h2>
    <table>
        <tr><th>Nivel</th><th>Verificación</th><th>Cantidad</th></tr>
        <?php foreach ($s['verificaciones'] as $v) : ?>
            <tr><td><?= escaparValores($v['nivel']) ?></td><td><?= escaparValores($v['titulo']) ?><?= in_array($v['codigo'], $s['avisos_reconocidos'], true) ? ' (reconocido)' : '' ?></td><td class="num"><?= (int) $v['cantidad'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$s['verificaciones']) : ?><tr><td colspan="3">Sin hallazgos.</td></tr><?php endif; ?>
    </table>
    <p class="aviso">Estimación administrativa calculada con las reglas instaladas. Debe validarse con el contador antes de presentar declaraciones.</p>
</body>
</html>

<?php
/**
 * templates/pdfEstadisticas.php — PDF de estadísticas. Variable: $r (Estadisticas::reporte()).
 */
$p = $r['parametros'];
$m = static fn (int $c): string => '$' . number_format($c / 100, 2);
$periodo = \App\Estadisticas::etiquetaPeriodo($p['tipo'], $p['anio'], $p['indice']);
$filtros = [];
if ($p['filtros']['empresa']) { $filtros[] = 'Negocio #' . $p['filtros']['empresa']; }
if ($p['filtros']['cliente']) { $filtros[] = 'Cliente #' . $p['filtros']['cliente']; }
if ($p['filtros']['producto']) { $filtros[] = 'Producto #' . $p['filtros']['producto'] . ' (renglones sin IVA)'; }
$v = static function (?array $x) use ($m): string {
    if ($x === null) { return '—'; }
    return ($x['absoluta'] > 0 ? '+' : '') . $m($x['absoluta']) . ($x['porcentual'] === null ? ' (sin base)' : ' (' . ($x['absoluta'] > 0 ? '+' : '') . number_format($x['porcentual'], 2) . ' %)');
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Estadísticas de ventas <?= escaparValores($periodo) ?></title>
    <style>
        body { margin: 0; padding: 1.5rem; font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #4d4c53; }
        h1 { margin: 0 0 .2rem; font-size: 17px; color: #4679a6; } h2 { margin: 1rem 0 .3rem; font-size: 12px; color: #4679a6; } p { margin: 0; }
        table { width: 100%; border-collapse: collapse; } th, td { padding: .3rem; border: 1px solid #e4e6ea; font-size: 10px; text-align: left; }
        th { background: #eaf1f8; color: #4679a6; } .num { text-align: right; }
        .aviso { margin-top: .6rem; padding: .5rem; background: #fff7e0; border: 1px solid #f0d58a; font-size: 9.5px; }
    </style>
</head>
<body>
    <h1>Estadísticas de ventas · <?= escaparValores($periodo) ?></h1>
    <p>Emitido <?= date('d/m/Y H:i') ?> · Importes <?= $p['base'] === 'subtotal' ? 'sin IVA (con descuentos)' : 'con IVA' ?> · Comparado con los <?= (int) $p['atras'] ?> año(s) anterior(es)
        <?= $filtros ? ' · Filtros: ' . escaparValores(implode(', ', $filtros)) : ' · Ambos negocios, todos los clientes y productos' ?></p>
    <?php if (!$r['actual']['completo']) : ?>
        <p class="aviso"><strong><?= $r['actual']['futuro'] ? 'Periodo futuro.' : 'Periodo incompleto:' ?></strong> los datos llegan al <?= date('d/m/Y', strtotime($r['actual']['corte'] ?? $p['hoy'])) ?>. No lo compares con un periodo completo; usa la variación del mismo avance.</p>
    <?php endif; ?>

    <h2>Comparación entre años</h2>
    <table>
        <tr><th>Periodo</th><th>Realizadas</th><th>Oper.</th><th>Piezas</th><th>Variación vs año anterior</th><th>Facturadas</th><th>Cobros</th></tr>
        <?php foreach (array_reverse($r['comparacion']) as $x) : ?>
            <tr><td><?= escaparValores($x['etiqueta']) ?><?= $x['futuro'] ? ' (futuro)' : (!$x['completo'] ? ' (incompleto)' : '') ?></td>
                <td class="num"><?= $m($x['realizadas']['importe']) ?></td><td class="num"><?= (int) $x['realizadas']['operaciones'] ?></td><td class="num"><?= (int) $x['realizadas']['unidades'] ?></td>
                <td class="num"><?= $v($x['variacion']) ?><?= !empty($x['variacion_mismo_avance']) ? '<br>Mismo avance: ' . $v($x['variacion_mismo_avance']) : '' ?></td>
                <td class="num"><?= $m($x['facturadas']['importe']) ?></td><td class="num"><?= $m($x['cobros']['importe']) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <p class="aviso">«Realizadas» = ventas del periodo; «Facturadas» = las marcadas como facturadas; «Cobros» = dinero recibido en el periodo (con IVA). No son equivalentes.</p>

    <h2>Tendencia</h2>
    <p><?= escaparValores($r['tendencia']['etiqueta']) ?><?= $r['tendencia']['direccion'] !== 'sin_datos' ? ' (' . $r['tendencia']['pendiente_pct'] . ' % por mes)' : '' ?></p>

    <h2>Productos más vendidos</h2>
    <table>
        <tr><th>Producto</th><th>Piezas</th><th>Importe (sin IVA)</th><th>Ventas</th></tr>
        <?php foreach ($r['productos'] as $x) : ?>
            <tr><td><?= escaparValores(trim($x['sku'] . ' ' . $x['nombre'])) ?></td><td class="num"><?= (int) $x['unidades'] ?></td><td class="num"><?= $m($x['importe']) ?></td><td class="num"><?= (int) $x['operaciones'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$r['productos']) : ?><tr><td colspan="4">Sin ventas en el periodo.</td></tr><?php endif; ?>
    </table>

    <h2>Clientes con mayor volumen de compra</h2>
    <table>
        <tr><th>Cliente</th><th>Importe</th><th>Ventas</th><th>Piezas</th></tr>
        <?php foreach ($r['clientes'] as $x) : ?>
            <tr><td><?= escaparValores($x['nombre']) ?></td><td class="num"><?= $m($x['importe']) ?></td><td class="num"><?= (int) $x['operaciones'] ?></td><td class="num"><?= (int) $x['unidades'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$r['clientes']) : ?><tr><td colspan="4">Sin ventas en el periodo.</td></tr><?php endif; ?>
    </table>
</body>
</html>

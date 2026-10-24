<?php
/**
 * templates/pdfEstadisticas.php — PDF de estadísticas. Variable: $r (Estadisticas::reporte()).
 * Las gráficas se dibujan en el servidor (GraficaSvg) con los mismos datos y filtros de la pantalla.
 *
 * Además de las gráficas y tablas, el reporte trae DOS tarjetas de producto que la pantalla
 * NO muestra (se pidió «solo en el reporte exportado»): los más vendidos del periodo con su
 * imagen y las existencias bajas, mismas tarjetas del panel de administración.
 */
$p = $r['parametros'];
$m = static fn (int $c): string => '$' . number_format($c / 100, 2);
$mp = static fn (float $v): string => '$' . number_format($v, 2);
$periodo = \App\Estadisticas::etiquetaPeriodo($p['tipo'], $p['anio'], $p['indice']);
$v = static function (?array $x) use ($m): string {
    if ($x === null) { return '—'; }
    return ($x['absoluta'] > 0 ? '+' : '') . $m($x['absoluta']) . ($x['porcentual'] === null ? ' (sin base)' : ' (' . ($x['absoluta'] > 0 ? '+' : '') . number_format($x['porcentual'], 2) . ' %)');
};
$secciones = ['ventas' => 'Ventas', 'ingresos' => 'Ingresos, cobros y facturación', 'gastos' => 'Gastos', 'negocios' => 'Negocios y clientes', 'productos' => 'Productos'];
$negocios = $r['analisis']['negocios'];
$totalNegocios = array_sum(array_column($negocios, 'periodo'));

/* --- Tarjetas de producto --------------------------------------------------
 * Imagen en base64 (dompdf nunca sale a Internet) y se prefiere la miniatura
 * 300×300 para que el archivo no crezca de más.
 * Colores de la cifra: mismos que el panel (--color-error / --color-advertencia
 * del tema claro, ver src/scss/base/_tema.scss).
 */
$archivoAbsoluto = static function (?string $archivo): string {
    $archivo = trim((string) $archivo);
    if ($archivo === '' || strpos($archivo, '..') !== false) {
        return '';
    }
    $ruta = dirname(__DIR__) . '/public/image/' . $archivo;

    return is_file($ruta) ? $ruta : '';
};
$imagenBase64 = static function (?string $archivo) use ($archivoAbsoluto): string {
    $ruta = $archivoAbsoluto($archivo);
    if ($ruta === '') {
        return '';
    }
    $datos = file_get_contents($ruta);
    if ($datos === false) {
        return '';
    }
    $tipo = function_exists('mime_content_type') ? mime_content_type($ruta) : false;
    if (!is_string($tipo) || strpos($tipo, 'image/') !== 0) {
        $tipo = 'image/jpeg';
    }

    return 'data:' . $tipo . ';base64,' . base64_encode($datos);
};
$miniaturaBase64 = static function (?string $archivo) use ($imagenBase64): string {
    $archivo = trim((string) $archivo);
    if ($archivo === '') {
        return '';
    }
    $mini = preg_replace('/\.([a-z0-9]+)$/i', '_mini.$1', $archivo);
    $datos = $mini !== null ? $imagenBase64($mini) : '';

    return $datos !== '' ? $datos : $imagenBase64($archivo);
};
$plural = static fn (int $n, string $uno, string $muchos): string => number_format($n) . ' ' . ($n === 1 ? $uno : $muchos);
$rojo = '#e11d48';      // sin existencias: agotado o negativo
$naranja = '#d97706';   // con pocas: en o debajo del mínimo

$masVendidos = \App\Panel::productosMasVendidos($r['periodo'][0], $r['periodo'][1], 4);
$stockBajo   = \App\Panel::productosStockBajo(8);
$stockTotal  = \App\Panel::contarStockBajo();

/** Imagen de una tarjeta (o su marcador cuando no hay archivo). */
$tarjetaImagen = static function (array $p) use ($miniaturaBase64): string {
    $img = $miniaturaBase64(!empty($p['imagen']) ? (string) $p['imagen'] : null);

    return $img !== '' ? '<img src="' . $img . '" alt="">' : '<span class="sin-imagen">sin foto</span>';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Estadísticas de ventas <?= escaparValores($periodo) ?></title>
    <style>
        body { margin: 0; padding: 1.2rem; font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #4d4c53; }
        h1 { margin: 0 0 .2rem; font-size: 18px; color: #38648c; }
        h2 { margin: 1rem 0 .3rem; padding-bottom: .2rem; border-bottom: 2px solid #4679a6; font-size: 13px; color: #38648c; }
        h3 { margin: .7rem 0 .1rem; font-size: 11px; color: #4d4c53; } p { margin: 0; }
        table { width: 100%; border-collapse: collapse; } th, td { padding: .18rem .3rem; border: 1px solid #e4e6ea; font-size: 9.5px; text-align: left; }
        th { background: #eaf1f8; color: #38648c; } .num { text-align: right; } .sube { color: #2e7d32; } .baja { color: #b3261e; } .flecha { font-family: 'DejaVu Sans', sans-serif; }
        .aviso { margin-top: .5rem; padding: .45rem; background: #fff7e0; border: 1px solid #f0d58a; font-size: 9px; }
        .sub { color: #64646a; font-size: 9px; margin-bottom: .2rem; }
        .kpis td { width: 25%; vertical-align: top; border: 1px solid #c7dcef; background: #f7fafd; padding: .45rem; }
        .kpis .valor { font-size: 15px; font-weight: bold; color: #38648c; }
        .grafica { page-break-inside: avoid; margin-bottom: .5rem; }
        /* Tarjetas de producto (solo en este reporte). dompdf sólo entiende un subconjunto
           de CSS, por eso la maqueta va con tablas anidadas, como en el resto de los PDF. */
        table.tarjetas { width: 100%; border-collapse: collapse; }
        table.tarjetas > tbody > tr > td { width: 50%; padding: 0 5px 6px 0; border: none; vertical-align: top; }
        table.tarjeta { width: 100%; border: 1px solid #e4e6ea; border-radius: 4px; page-break-inside: avoid; }
        table.tarjeta td { border: none; padding: .35rem .4rem; vertical-align: middle; }
        .tarjeta-img { width: 54px; text-align: center; background: #f7fafd; }
        .tarjeta-img img { width: 40px; height: 40px; }
        .sin-imagen { font-size: 8px; color: #64646a; }
        .tarjeta-clave { font-size: 9px; font-weight: bold; color: #38648c; }
        .tarjeta-nombre { font-size: 10px; }
        .tarjeta-cifra { font-size: 13px; font-weight: bold; }
        .tarjeta-pie { font-size: 8.5px; color: #64646a; }
    </style>
</head>
<body>
    <h1>Estadísticas de ventas · <?= escaparValores($periodo) ?></h1>
    <?php foreach (\App\Estadisticas::contexto($r) as $i => $linea) : ?>
        <?php if ($i < 3) : ?><p><?= escaparValores($linea) ?></p><?php endif; ?>
    <?php endforeach; ?>
    <p class="sub">Emitido <?= date('d/m/Y H:i') ?></p>
    <?php if (!$r['actual']['completo']) : ?>
        <p class="aviso"><strong><?= $r['actual']['futuro'] ? 'Periodo futuro.' : 'Periodo incompleto:' ?></strong> los datos llegan al <?= date('d/m/Y', strtotime($r['actual']['corte'] ?? $p['hoy'])) ?>. Las variaciones comparan el mismo avance del año anterior.</p>
    <?php endif; ?>

    <!-- Debajo de los filtros: tarjetas que sólo trae este reporte (la pantalla no las muestra). -->
    <h2>Productos más vendidos · <?= escaparValores($periodo) ?></h2>
    <?php if (!$masVendidos) : ?>
        <p class="sub">No hay ventas de productos registradas en el periodo elegido.</p>
    <?php else : ?>
        <table class="tarjetas">
            <?php foreach (array_chunk($masVendidos, 2) as $fila) : ?>
                <tr>
                    <?php foreach ($fila as $pTarjeta) : ?>
                        <td>
                            <table class="tarjeta"><tr>
                                <td class="tarjeta-img"><?= $tarjetaImagen($pTarjeta) ?></td>
                                <td>
                                    <div class="tarjeta-clave"><?= escaparValores($pTarjeta['sku'] !== '' ? $pTarjeta['sku'] : 'Sin código') ?></div>
                                    <div class="tarjeta-nombre"><?= escaparValores($pTarjeta['nombre']) ?></div>
                                    <div class="tarjeta-cifra"><?= $mp($pTarjeta['importe']) ?></div>
                                    <div class="tarjeta-pie"><?= $plural($pTarjeta['piezas'], 'pieza', 'piezas') ?> en <?= $plural($pTarjeta['ventas'], 'venta', 'ventas') ?> · renglones sin IVA</div>
                                </td>
                            </tr></table>
                        </td>
                    <?php endforeach; ?>
                    <?php if (count($fila) % 2 !== 0) : ?><td></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>Productos con existencias bajas</h2>
    <?php if (!$stockBajo) : ?>
        <p class="sub">Ningún producto está agotado, con existencia negativa ni por debajo de su cantidad mínima.</p>
    <?php else : ?>
        <table class="tarjetas">
            <?php foreach (array_chunk($stockBajo, 2) as $fila) : ?>
                <tr>
                    <?php foreach ($fila as $pTarjeta) :
                        $estadoTexto = ['negativo' => 'Existencia negativa', 'agotado' => 'Agotado', 'bajo' => 'Bajo el mínimo'][$pTarjeta['estado']] ?? '';
                        $colorCifra = $pTarjeta['estado'] === 'bajo' ? $naranja : $rojo; ?>
                        <td>
                            <table class="tarjeta"><tr>
                                <td class="tarjeta-img"><?= $tarjetaImagen($pTarjeta) ?></td>
                                <td>
                                    <div class="tarjeta-clave"><?= escaparValores($pTarjeta['sku'] !== '' ? $pTarjeta['sku'] : 'Sin código') ?></div>
                                    <div class="tarjeta-nombre"><?= escaparValores($pTarjeta['nombre']) ?></div>
                                    <div class="tarjeta-cifra" style="color: <?= $colorCifra ?>"><?= number_format($pTarjeta['stock']) ?></div>
                                    <div class="tarjeta-pie"><?= escaparValores($estadoTexto) ?><?= $pTarjeta['stock_minimo'] !== null ? ' · mínimo ' . number_format($pTarjeta['stock_minimo']) : '' ?></div>
                                </td>
                            </tr></table>
                        </td>
                    <?php endforeach; ?>
                    <?php if (count($fila) % 2 !== 0) : ?><td></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($stockTotal > count($stockBajo)) : ?>
            <p class="sub">Se muestran <?= count($stockBajo) ?> de <?= number_format($stockTotal) ?> productos en alerta; primero los de existencia negativa y los agotados.</p>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Mismo periodo: los productos que menos se movieron, de menos a más piezas. -->
    <h2>Productos menos vendidos · <?= escaparValores($periodo) ?></h2>
    <?php $menores = $r['analisis']['menor_movimiento']; if (!$menores) : ?>
        <p class="sub">No hay productos con ventas en el periodo elegido.</p>
    <?php else : ?>
        <table>
            <tr>
                <th>Negocio</th>
                <th>Código</th>
                <th>Producto</th>
                <th class="num">Piezas</th>
                <th class="num">Importe (sin IVA)</th>
            </tr>
            <?php foreach ($menores as $pMenor) : ?>
                <tr>
                    <td><?= escaparValores($pMenor['negocio']) ?></td>
                    <td><?= escaparValores($pMenor['sku'] !== '' ? $pMenor['sku'] : '—') ?></td>
                    <td><?= escaparValores($pMenor['nombre']) ?></td>
                    <td class="num"><?= number_format($pMenor['unidades']) ?></td>
                    <td class="num"><?= $m((int) $pMenor['importe']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="sub">Sólo productos que sí se vendieron en el periodo, de menos a más piezas y separados por negocio.</p>
    <?php endif; ?>

    <table class="kpis" style="margin-top:.6rem"><tr>
        <?php foreach ($r['kpis'] as $k) : $vk = $k['variacion']; $bien = $vk && $vk['absoluta'] != 0 && (($vk['absoluta'] > 0) === ($k['sentido'] === 'directo')); ?>
            <td><span class="sub"><?= escaparValores($k['nombre']) ?></span><br><span class="valor"><?= $m($k['importe']) ?></span><br>
                <span class="flecha <?= $vk && $vk['absoluta'] != 0 ? ($bien ? 'sube' : 'baja') : '' ?>"><?= $vk ? ($vk['absoluta'] > 0 ? '▲ ' : ($vk['absoluta'] < 0 ? '▼ ' : '')) . ($vk['porcentual'] === null ? 'sin base de comparación' : number_format(abs($vk['porcentual']), 1) . ' % vs ' . ($p['anio'] - 1)) : 'sin datos para comparar' ?></span><br>
                <span class="sub"><?= escaparValores($k['detalle']) ?></span></td>
        <?php endforeach; ?>
    </tr></table>
    <p class="aviso">«Realizadas» = ventas del periodo; «Facturadas» = las marcadas como facturadas; «Cobros» = dinero recibido en el periodo (con IVA); «Gastos» = gastos vigentes. No son equivalentes: no se suman ni se mezclan en una misma serie.</p>

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
    <p class="sub" style="margin-top:.3rem"><?= escaparValores($r['tendencia']['etiqueta']) ?><?= $r['tendencia']['direccion'] !== 'sin_datos' ? ' (' . $r['tendencia']['pendiente_pct'] . ' % por mes)' : '' ?></p>

    <?php foreach ($secciones as $clave => $titulo) : $deSeccion = array_filter($r['graficas'], static fn ($g) => $g['seccion'] === $clave); if (!$deSeccion) { continue; } ?>
        <?php $primero = true; foreach ($deSeccion as $g) : [$enc, $filas, $total] = \App\Analisis::tabla($g); $moneda = $g['formato'] === 'moneda'; ?>
            <div class="grafica">
                <?php if ($primero) : $primero = false; ?><h2><?= $titulo ?></h2><?php endif; ?>
                <h3><?= escaparValores($g['titulo']) ?></h3>
                <p class="sub"><?= escaparValores($g['subtitulo']) ?></p>
                <?= \App\GraficaSvg::imagen($g) ?>
                <table>
                    <tr><?php foreach ($enc as $i => $t) : ?><th class="<?= $i ? 'num' : '' ?>"><?= escaparValores($t) ?></th><?php endforeach; ?></tr>
                    <?php foreach (array_merge($filas, $total ? [$total] : []) as $fila) : ?>
                        <tr><?php foreach ($fila as $i => $c) : ?>
                            <td class="<?= $i ? 'num' : '' ?>"><?php
                                if ($i === 0) { echo $fila[0] === 'Total' ? '<strong>Total</strong>' : escaparValores((string) $c); }
                                elseif ($c === null) { echo '—'; }
                                elseif ($i > count($g['series']) + count($g['extra'] ?? [])) { echo number_format((float) $c, 2) . ' %'; }
                                elseif ($i > count($g['series'])) { echo number_format((float) $c); }
                                else { echo $moneda ? '$' . number_format((float) $c, 2) : number_format((float) $c); }
                            ?></td>
                        <?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>

        <?php if ($clave === 'negocios' && $negocios) : ?>
            <h3>Comparación entre negocios · <?= escaparValores($periodo) ?></h3>
            <table>
                <tr><th>Negocio</th><th>Ventas del periodo</th><th>Operaciones</th><th>Mismo avance año anterior</th><th>Variación</th><th>Participación</th></tr>
                <?php foreach ($negocios as $n) : $vn = \App\Estadisticas::variacion($n['previo'], $n['periodo']); ?>
                    <tr><td><?= escaparValores($n['nombre']) ?></td><td class="num"><?= $m($n['periodo']) ?></td><td class="num"><?= (int) $n['ventas'] ?></td><td class="num"><?= $m($n['previo']) ?></td>
                        <td class="num"><?= $v($vn) ?></td><td class="num"><?= $totalNegocios > 0 ? number_format($n['periodo'] * 100 / $totalNegocios, 1) . ' %' : '—' ?></td></tr>
                <?php endforeach; ?>
            </table>
            <p class="sub">No se aplica el filtro de negocio en esta comparación; el cliente sí.</p>
            <?php foreach (\App\Estadisticas::agruparPorNegocio($r['productos']) as $grupoProductos) : ?>
                <h3>Ventas por negocio · productos de <?= escaparValores($grupoProductos['negocio']) ?></h3>
                <table>
                    <tr><th>Código</th><th>Producto</th><th>Piezas</th><th>Importe (sin IVA)</th><th>Ventas</th></tr>
                    <?php foreach ($grupoProductos['filas'] as $x) : ?>
                        <tr><td><?= escaparValores($x['sku'] !== '' ? $x['sku'] : '—') ?></td><td><?= escaparValores($x['nombre']) ?></td>
                            <td class="num"><?= (int) $x['unidades'] ?></td><td class="num"><?= $m($x['importe']) ?></td><td class="num"><?= (int) $x['operaciones'] ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($clave === 'ventas') : ?>
            <h3>Semestres con variación contra el año anterior</h3>
            <table>
                <tr><th>Año</th><th>Primer semestre</th><th>Variación</th><th>Segundo semestre</th><th>Variación</th><th>Total</th></tr>
                <?php foreach (\App\Estadisticas::semestresFilas($r) as $x) : ?>
                    <tr><td><?= (int) $x['anio'] ?></td><td class="num"><?= $m($x['s1']) ?></td><td class="num"><?= $v($x['var1']) ?></td><td class="num"><?= $m($x['s2']) ?></td><td class="num"><?= $v($x['var2']) ?></td><td class="num"><?= $m($x['total']) ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <?php if ($clave === 'productos') : $sin = $r['analisis']['sin_movimiento']; ?>
            <h3>Productos activos sin movimiento en el periodo: <?= (int) $sin['total'] ?></h3>
            <?php if ($sin['lista']) : ?>
                <table><tr><th>Producto</th><th>SKU</th><th>Existencia</th></tr>
                    <?php foreach ($sin['lista'] as $x) : ?><tr><td><?= escaparValores($x['nombre']) ?></td><td><?= escaparValores($x['sku']) ?></td><td class="num"><?= (int) $x['stock'] ?></td></tr><?php endforeach; ?>
                </table>
                <?php if ($sin['total'] > count($sin['lista'])) : ?><p class="sub">Se muestran los <?= count($sin['lista']) ?> de mayor existencia; el Excel trae el mismo recorte.</p><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endforeach; ?>
</body>
</html>
